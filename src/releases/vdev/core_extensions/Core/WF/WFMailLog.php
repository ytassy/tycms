<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/**
 * Administration du journal des e-mails.
 *
 * L'écran est réservé à ROOT : les corps peuvent contenir des données
 * personnelles, même après masquage des secrets. Les seuils de purge sont
 * fermés aux trois actions proposées par l'interface.
 */
final class WFMailLog extends BaseWF
{
    use TraitAllHelpers;

    /** 0 signifie « tout purger » ; les deux autres valeurs sont les boutons. */
    public const PURGE_DAYS = [0, 7, 30];

    /** @return array{enabled:bool, retentionDays:int, oldest:?string, newest:?string} */
    public function status(): array
    {
        $this->assertRoot();

        return $this->coreMailLogHelper->status();
    }

    public function purge(int $days): int
    {
        $this->assertRoot();

        if (!in_array($days, self::PURGE_DAYS, true)) {
            ExceptionResponse::throwWarning('Seuil de purge invalide.');
        }

        $deleted = $this->coreMailLogHelper->purge($days);

        $this->logInfo(
            $days === 0
                ? 'Purge totale du journal des e-mails.'
                : 'Purge manuelle du journal des e-mails.',
            ['days' => $days, 'deleted' => $deleted],
            'MAIL_LOG_ADMIN_PURGE',
            'mail',
        );

        return $deleted;
    }

    private function assertRoot(): void
    {
        if (!$this->coreAclHelper->isRoot()) {
            throw new AccessDeniedException();
        }
    }
}
