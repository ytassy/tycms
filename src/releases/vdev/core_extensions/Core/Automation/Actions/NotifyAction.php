<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;

/**
 * Envoie un message aux canaux de scénario configurés dans le Space.
 *
 * Retourne vrai uniquement si tous les canaux demandés ont accepté le message.
 * Un échec est signalé au scénario, sans interrompre le run par lui-même.
 */
final class NotifyAction extends BaseAutomationAction
{
    use TraitAutomationHelpers;

    /** @var list<string> */
    private const CHANNELS = ['telegram', 'slack', 'discord'];

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
        $channels = $this->channels($params['channels'] ?? null, $context);

        try {
            $result = $this->automationNotificationSender->sendScenario(
                $context->spaceId,
                $title,
                $message,
                $channels,
            );
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }

        return $result['sent'] !== [] && $result['failed'] === [];
    }

    /** @return list<string>|null */
    private function channels(mixed $value, ActionContext $context): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Le paramètre channels doit être une liste de canaux.',
                $context->span,
            );
        }

        $channels = [];

        foreach ($value as $channel) {
            $channel = strtolower(trim(ValueTools::assertString($channel, $context->span)));

            if (!in_array($channel, self::CHANNELS, true)) {
                throw ValueTools::error(
                    DiagnosticCode::INVALID_ARGUMENT,
                    'Canal de notification inconnu : ' . $channel . '.',
                    $context->span,
                );
            }

            $channels[$channel] = true;
        }

        return array_keys($channels);
    }
}
