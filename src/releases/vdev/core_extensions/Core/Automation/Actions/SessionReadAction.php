<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

/**
 * Lit une donnée de la session du navigateur du Space du run.
 *
 * La lecture est une action `read` : elle reste permise en simulation, mais
 * elle ne voit que le contenu déjà présent avant la requête courante, jamais
 * la valeur écrite par le scénario en cours de requête.
 */
final class SessionReadAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $key = ValueTools::assertString($params['key'] ?? null, $context->span);
        $default = $params['default'] ?? null;
        $path = $params['path'] ?? '';

        if ($path !== null && !is_string($path)) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Le chemin de lecture doit être une chaîne.',
                $context->span,
            );
        }

        try {
            $value = $this->coreBrowserSessionPropertyHelper->read(
                $context->spaceId,
                $key,
                $default,
                $context,
            );
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }

        // Un chemin vide lit la donnée entière ; un chemin absent retombe sur
        // la valeur par défaut fournie par le scénario.
        if (is_string($path) && $path !== '') {
            return $this->coreBrowserSessionPropertyHelper->path($value, $path) ?? $default;
        }

        return $value;
    }
}
