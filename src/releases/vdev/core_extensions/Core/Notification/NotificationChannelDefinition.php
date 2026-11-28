<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Notification;

use InvalidArgumentException;

/**
 * Déclaration d'un type de canal de notification.
 *
 * Le contrat du Lot 0 porte l'identité du canal, sa longueur de message et sa
 * permission. Le formulaire de configuration, les champs secrets, le validateur
 * et la classe d'envoi seront ajoutés avec le lot 3, quand le handler de
 * livraison existera.
 *
 * `permission = null` signifie que le canal est ouvert à tous les comptes. Une
 * permission est un nom complet du catalogue ACL, par exemple
 * `plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL`. Elle n'est pas vérifiée contre le
 * catalogue à l'enregistrement : une faute de frappe rend le canal inutilisable.
 */
final class NotificationChannelDefinition
{
    /** Identifiant stable, aligné sur la colonne `channel` (varchar(32)). */
    public const ID_PATTERN = '/\A[a-z][a-z0-9_]{0,31}\z/';

    /** Nom complet d'une permission ACL : `<extension>__<PERMISSION>`. */
    public const PERMISSION_PATTERN = '/\A[a-z][a-z0-9_]*__[A-Za-z][A-Za-z0-9_]*\z/';

    public const MAX_MESSAGE_LENGTH_MIN = 1;
    public const MAX_MESSAGE_LENGTH_MAX = 65535;

    public const LABEL_MAX_LENGTH = 100;

    /**
     * Canaux intégrés. Leurs identifiants sont réservés à Core : une extension
     * ne peut pas revendiquer `email` ou `telegram` et changer leur permission.
     */
    public const BUILT_IN_IDS = ['ui', 'email', 'telegram', 'slack', 'discord'];

    public readonly string $id;

    public readonly string $label;

    public readonly int $maxMessageLength;

    public readonly ?string $permission;

    public function __construct(
        string $id,
        string $label,
        int $maxMessageLength,
        ?string $permission = null,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'Identifiant de canal invalide : "%s". Format attendu : minuscules, chiffres et tirets bas, 32 caractères maximum.',
                $id,
            ));
        }

        $label = trim($label);
        if ($label === '' || mb_strlen($label) > self::LABEL_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Libellé invalide pour le canal "%s".',
                $id,
            ));
        }

        if ($maxMessageLength < self::MAX_MESSAGE_LENGTH_MIN
            || $maxMessageLength > self::MAX_MESSAGE_LENGTH_MAX) {
            throw new InvalidArgumentException(sprintf(
                'Longueur maximale hors limites pour le canal "%s" (%d-%d).',
                $id,
                self::MAX_MESSAGE_LENGTH_MIN,
                self::MAX_MESSAGE_LENGTH_MAX,
            ));
        }

        if ($permission !== null) {
            $permission = trim($permission);

            if (preg_match(self::PERMISSION_PATTERN, $permission) !== 1) {
                throw new InvalidArgumentException(sprintf(
                    'Permission invalide pour le canal "%s" : "%s".',
                    $id,
                    $permission,
                ));
            }
        }

        $this->id = $id;
        $this->label = $label;
        $this->maxMessageLength = $maxMessageLength;
        $this->permission = $permission;
    }

    /** Un canal ouvert à tous n'a pas de permission. */
    public function isOpenToEveryone(): bool
    {
        return $this->permission === null;
    }
}
