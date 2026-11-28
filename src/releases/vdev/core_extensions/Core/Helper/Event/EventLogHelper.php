<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Event;

use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Stockage du journal d’événements.
 *
 * L’écriture est appelée par `EventJournalListener` dans la transaction métier :
 * une erreur ici annule le métier, comme pour toute autre écriture. La purge
 * applique la rétention configurée par Space et travaille par lots bornés.
 */
class EventLogHelper extends BaseHelper
{
    /** Taille d’un lot de purge. */
    public const PURGE_BATCH_SIZE = 500;

    /** Nombre maximal de lots supprimés par Space et par passage. */
    public const PURGE_MAX_BATCHES = 20;

    /**
     * Écrit une occurrence et retourne l’identifiant de la ligne.
     *
     * La charge utile a déjà été validée et bornée par `Event`. Elle reste une
     * référence courte : jamais de secret, de corps d’e-mail ni de réponse
     * distante.
     */
    public function write(Event $event): int
    {
        $this->db->execute(<<<'SQL'
            INSERT INTO event_log
                (space_id, event_type, category, severity, actor_id, actor_type,
                 subject_type, subject_id, data, created_at)
            VALUES (%, %, %, %, %, %, %, %, %, %)
            SQL, [
                $event->spaceId,
                $event->type,
                $event->definition->category,
                $event->definition->severity,
                $event->actorId,
                $event->actorType->value,
                $event->subjectType,
                $event->subjectId,
                json_encode(
                    $event->data,
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                $event->occurredAt->format('Y-m-d H:i:s'),
            ]);

        $logId = $this->db->getLastInsertId();

        // Sans identifiant, la notification ne peut pas s’adosser au journal :
        // mieux vaut annuler le métier que produire une trace incohérente.
        if ($logId <= 0) {
            throw new TechException(sprintf(
                'Ligne de journal introuvable après l’écriture de l’événement "%s".',
                $event->type,
            ));
        }

        return $logId;
    }

    /**
     * Supprime par lots les événements plus anciens que la rétention du Space.
     *
     * `0` ou une valeur négative signifie « aucune limite » : rien n’est
     * supprimé. Le DELETE porte sur l’index `(space_id, created_at)` ; le nombre
     * de lots est borné pour ne pas monopoliser le CRON.
     */
    public function purge(int $spaceId, int $retentionDays): int
    {
        if ($spaceId <= 0 || $retentionDays <= 0) {
            return 0;
        }

        $deleted = 0;
        for ($batch = 0; $batch < self::PURGE_MAX_BATCHES; $batch++) {
            $removed = $this->db->execute(
                'DELETE FROM event_log
                 WHERE space_id = % AND created_at < NOW() - INTERVAL % DAY
                 ORDER BY id
                 LIMIT ' . self::PURGE_BATCH_SIZE,
                [$spaceId, $retentionDays],
            );

            $deleted += $removed;

            // Un lot incomplet signale la fin des lignes éligibles.
            if ($removed < self::PURGE_BATCH_SIZE) {
                break;
            }
        }

        return $deleted;
    }
}
