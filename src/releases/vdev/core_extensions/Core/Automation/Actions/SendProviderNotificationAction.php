<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;

/**
 * Base commune aux actions d'un provider de notification.
 *
 * Sans cible, le canal de scénario du provider est utilisé. Avec une cible,
 * seuls la clé et les paramètres publics sont transmis au journal ; les
 * identifiants restent dans la configuration chiffrée du Space.
 */
abstract class SendProviderNotificationAction extends BaseAutomationAction
{
    use TraitAutomationHelpers;

    abstract protected function provider(): string;

    public function execute(ActionContext $context, array $params): mixed
    {
        $message = ValueTools::assertString($params['message'] ?? null, $context->span);

        if (trim($message) === '') {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Le message de notification est vide.',
                $context->span,
            );
        }

        $title = $params['title'] ?? null;

        if ($title !== null) {
            $title = ValueTools::assertString($title, $context->span);
        }

        $title = is_string($title) && trim($title) !== '' ? trim($title) : 'Automatisation';
        $target = $this->target($params['target'] ?? null, $context);

        try {
            $result = $this->automationNotificationSender->sendProvider(
                $context->spaceId,
                $this->provider(),
                $title,
                $message,
                $target,
            );
        } catch (\InvalidArgumentException $exception) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                $exception->getMessage(),
                $context->span,
            );
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }

        return $result['sent'] !== [] && $result['failed'] === [];
    }

    private function target(mixed $value, ActionContext $context): ?string
    {
        if ($value === null) {
            return null;
        }

        $target = strtolower(trim(ValueTools::assertString($value, $context->span)));

        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $target) !== 1) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Clé de profil de notification invalide.',
                $context->span,
            );
        }

        return $target;
    }
}
