<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Action;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalType;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Répartiteur qui exécute les actions déclarées par les extensions. */
final class RegistryActionDispatcher extends BaseHelper implements ActionDispatcher
{
    public function __construct(
        BaseApplicationItem|Application $app,
        private readonly ?AutomationActionRegistry $registry = null,
        /** @var list<string> $excludedEffects Effets refusés à l'exécution. */
        private readonly array $excludedEffects = [],
    ) {
        parent::__construct($app);
    }

    public function dispatch(
        string $identifier,
        array $arguments,
        SourceSpan $span,
        RuntimeServices $services,
    ): mixed {
        $definition = $this->registry?->find($identifier);

        if ($definition === null) {
            throw ValueTools::error(
                DiagnosticCode::ACTION_UNAVAILABLE,
                'Action indisponible : « ' . $identifier . ' ».',
                $span,
            );
        }

        // Défense en profondeur : une action exclue ne doit jamais s'exécuter,
        // même si un AST forgé contournait le catalogue à la compilation.
        if (in_array($definition->effect, $this->excludedEffects, true)) {
            throw ValueTools::error(
                DiagnosticCode::ACTION_UNAVAILABLE,
                'Action indisponible : « ' . $identifier . ' ».',
                $span,
            );
        }

        $principal = $services->context->principal;
        $principalType = (string) ($principal['type'] ?? AutomationPrincipalType::ANONYMOUS);
        $principalId = $principal['id'] ?? null;
        $spaceId = (int) ($services->context->space['id'] ?? 0);

        // Un principal « user » ou « service » porte tous deux un identifiant de
        // compte réel, dont les permissions se résolvent via ses groupes/rôles.
        $isAccountPrincipal = in_array(
            $principalType,
            [AutomationPrincipalType::USER, AutomationPrincipalType::SERVICE],
            true,
        );

        foreach ($definition->requiredPermissions as $permission) {
            if (!$isAccountPrincipal
                || !is_int($principalId)
                || $principalId <= 0
                || !$this->coreAclHelper->userHasGlobalPermission($principalId, $permission, $spaceId)) {
                throw ValueTools::error(
                    DiagnosticCode::ACCESS_DENIED,
                    'Permission requise pour « ' . $identifier . ' » : ' . $permission . '.',
                    $span,
                );
            }
        }

        $run = $services->context->run;
        $context = new ActionContext(
            $principalType,
            is_int($principalId) ? $principalId : null,
            $spaceId,
            isset($run['id']) ? (int) $run['id'] : null,
            (int) ($run['scenarioId'] ?? 0),
            (int) ($run['versionId'] ?? 0),
            $span,
        );

        if (count($arguments) > count($definition->parameters)) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Trop d’arguments pour « ' . $identifier . ' ».',
                $span,
            );
        }

        $namedArguments = [];

        foreach ($definition->parameters as $index => $parameter) {
            if (array_key_exists($index, $arguments)) {
                $namedArguments[$parameter->name] = $arguments[$index];
                continue;
            }

            if ($parameter->required) {
                throw ValueTools::error(
                    DiagnosticCode::INVALID_ARGUMENT,
                    'Argument obligatoire manquant pour « ' . $identifier . ' » : ' . $parameter->name . '.',
                    $span,
                );
            }
        }

        try {
            return $this->registry->createHandler($identifier)->execute($context, $namedArguments);
        } catch (ExecutionException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValueTools::error(
                DiagnosticCode::ACTION_FAILED,
                'L’action « ' . $identifier . ' » a échoué.',
                $span,
            );
        }
    }
}
