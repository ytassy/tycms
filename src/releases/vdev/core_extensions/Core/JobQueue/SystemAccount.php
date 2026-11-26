<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

use Throwable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UserGroups;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Compte de service système de la file.
 *
 * Il porte le principal `system`. Son identifiant est réservé : l'installation
 * fixe `users.AUTO_INCREMENT = 1000`, donc aucun compte créé par l'application
 * ne peut occuper l'ID 2. Il appartient au groupe Super Admin, dont le rôle
 * porte déjà toutes les permissions sur le Space racine, `ROOT` compris.
 *
 * La migration de la file crée la ligne. Les workflows d'administration
 * refusent sa suppression, et le helper de suppression des comptes porte la
 * même garde comme invariant. Ce helper vérifie seulement que la ligne est
 * toujours là : une suppression faite en SQL direct est réparée au passage
 * suivant.
 */
final class SystemAccount extends BaseHelper
{
    /** Identifiant réservé, sous la plage des comptes applicatifs (1000 et plus). */
    public const USER_ID = 2;

    public const EMAIL = 'system@jobs.invalid';

    public const NAME = 'Système (file de jobs)';

    /** Space racine de l'installation. */
    private const ROOT_SPACE_ID = 1;

    /** Vérification faite une seule fois par instance de requête. */
    private bool $checked = false;

    /** Identifiant du compte système, présent ou recréé. */
    public function id(): int
    {
        if (!$this->checked) {
            $this->checked = true;
            $this->ensure();
        }

        return self::USER_ID;
    }

    private function ensure(): void
    {
        $present = $this->db->selectOneOrNull(
            "SELECT 1 AS present FROM users WHERE id = % AND type = 'service'",
            [self::USER_ID],
        );

        if ($present === null) {
            $this->recreate();
        }

        $this->assignRootGroup();
    }

    /**
     * Recrée la ligne réservée. Deux requêtes concurrentes se tranchent par la
     * clé primaire : la perdante constate la présence du gagnant.
     */
    private function recreate(): void
    {
        try {
            // Aucun mot de passe : la colonne vide interdit toute connexion
            // interactive, seul le principal de job peut incarner ce compte.
            $this->db->execute(
                'INSERT INTO users (id, space_id, type, first_name, last_name, email, password, params, register_date) '
                . "VALUES (%, %, 'service', %, '', %, '', '{}', current_timestamp())",
                [self::USER_ID, self::ROOT_SPACE_ID, self::NAME, self::EMAIL],
            );
        } catch (Throwable $exception) {
            // Un échec sans ligne en place est une vraie erreur : on la laisse
            // remonter plutôt que de masquer une base incohérente.
            $winner = $this->db->selectOneOrNull(
                "SELECT 1 AS present FROM users WHERE id = % AND type = 'service'",
                [self::USER_ID],
            );

            if ($winner === null) {
                throw $exception;
            }

            return;
        }

        $this->logWarning(
            'Compte de service système de la file recréé après suppression.',
            ['user_id' => self::USER_ID],
            'JOB_SYSTEM_ACCOUNT_RECREATED',
            'jobs',
        );
    }

    /** Le rattachement peut manquer même quand la ligne existe. */
    private function assignRootGroup(): void
    {
        $mapped = $this->db->selectOneOrNull(
            'SELECT 1 AS present FROM user_groups_map
              WHERE user_id = % AND group_id = %
              LIMIT 1',
            [self::USER_ID, UserGroups::GROUP_ROOT],
        );

        if ($mapped !== null) {
            return;
        }

        $this->db->execute(
            'INSERT INTO user_groups_map (user_id, group_id) VALUES (%, %)',
            [self::USER_ID, UserGroups::GROUP_ROOT],
        );
    }
}
