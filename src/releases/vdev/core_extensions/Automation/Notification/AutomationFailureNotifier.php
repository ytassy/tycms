<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Envoi best-effort d'une notification après un run en échec. */
final class AutomationFailureNotifier extends BaseHelper
{
    use TraitAutomationHelpers;

    public function notify(
        int $spaceId,
        int $runId,
        string $scenarioName,
        string $triggerId,
        ?string $errorCode,
        ?string $errorMessage,
    ): void {
        $config = $this->storedConfig($spaceId);

        if ((string) ($config['notify_on_failure'] ?? '1') !== '1') {
            return;
        }

        $lines = [
            'Scénario : ' . $scenarioName,
            'Run : #' . $runId,
            'Déclencheur : ' . $triggerId,
            'Erreur : ' . ($errorCode ?? 'FAILED'),
        ];

        if ($errorMessage !== null && $errorMessage !== '') {
            $lines[] = $errorMessage;
        }

        try {
            $this->automationNotificationSender->sendFailure(
                $spaceId,
                'Automatisation en échec',
                mb_substr(implode("\n", $lines), 0, 300, 'UTF-8'),
            );
        } catch (\Throwable $exception) {
            $this->logException($exception);
        }
    }

    /** @return array<string, mixed> */
    private function storedConfig(int $spaceId): array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT params FROM config WHERE space_id = % AND extension = % LIMIT 1',
            [$spaceId, 'plg_automation'],
        );

        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) $row->params, true);

        return is_array($decoded) ? $decoded : [];
    }
}
