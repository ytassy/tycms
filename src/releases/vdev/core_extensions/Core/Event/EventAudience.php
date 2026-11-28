<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclUserResolver;

/**
 * Spécification de l’audience d’un événement.
 *
 * Le workflow émetteur décrit **qui doit être prévenu** ; c’est
 * `EventEmitter` qui résout la liste des comptes, avec le plafond de
 * destinataires et le resolver borné. Un workflow ne calcule donc ni liste
 * d’utilisateurs, ni limite.
 *
 * Les quatre sources reprennent les méthodes d’`AclUserResolver`. La liste
 * directe reste possible pour un cas marginal : elle est acceptée telle
 * quelle, sans lecture SQL, et le job de fan-out écarte ensuite les comptes
 * bloqués, de service et supprimés.
 */
final readonly class EventAudience
{
    private const SOURCE_USERS = 'users';
    private const SOURCE_GLOBAL_PERMISSION = 'global_permission';
    private const SOURCE_PERMISSION_ON_OBJECT = 'permission_on_object';
    private const SOURCE_GLOBAL_ROLE = 'global_role';
    private const SOURCE_ROLE_ON_OBJECT = 'role_on_object';

    /**
     * @param list<int> $userIds
     */
    private function __construct(
        private string $source,
        private array $userIds = [],
        private string $permission = '',
        private int $spaceId = 0,
        private ?int $roleId = null,
        private ?string $extension = null,
        private ?string $object = null,
        private ?int $objectId = null,
    ) {
    }

    /**
     * Liste directe fournie par l’émetteur, sans lecture SQL.
     *
     * À réserver aux cas où le métier désigne explicitement des comptes. Une
     * liste calculée à partir d’un droit ou d’un rôle passe par les autres
     * constructeurs.
     *
     * @param list<int> $userIds
     */
    public static function users(array $userIds): self
    {
        return new self(self::SOURCE_USERS, userIds: $userIds);
    }

    /** Comptes ayant la permission globale sur le Space ou un ancêtre. */
    public static function globalPermission(string $permission, int $spaceId): self
    {
        return new self(
            self::SOURCE_GLOBAL_PERMISSION,
            permission: $permission,
            spaceId: $spaceId,
        );
    }

    /** Comptes ayant la permission sur l’objet, d’un droit global, de type ou d’instance. */
    public static function permissionOnObject(
        string $permission,
        string $extension,
        string $object,
        int $objectId,
        int $spaceId,
    ): self {
        return new self(
            self::SOURCE_PERMISSION_ON_OBJECT,
            permission: $permission,
            spaceId: $spaceId,
            extension: $extension,
            object: $object,
            objectId: $objectId,
        );
    }

    /** Comptes ayant le rôle global sur le Space ou un ancêtre. */
    public static function globalRole(int $roleId, int $spaceId): self
    {
        return new self(self::SOURCE_GLOBAL_ROLE, roleId: $roleId, spaceId: $spaceId);
    }

    /** Comptes ayant le rôle sur l’objet, d’un rôle global, de type ou d’instance. */
    public static function roleOnObject(
        int $roleId,
        string $extension,
        string $object,
        int $objectId,
        int $spaceId,
    ): self {
        return new self(
            self::SOURCE_ROLE_ON_OBJECT,
            roleId: $roleId,
            spaceId: $spaceId,
            extension: $extension,
            object: $object,
            objectId: $objectId,
        );
    }

    /**
     * Liste des identifiants destinataires, au plus `$limit`.
     *
     * `$limit` est toujours positif : l’appelant passe `plafond + 1` pour
     * pouvoir distinguer une audience au plafond d’une audience trop grande.
     * Une liste directe n’est pas tronquée ici ; le plafond est vérifié à
     * l’émission du fan-out.
     *
     * @return list<int>
     */
    public function resolve(AclUserResolver $resolver, int $limit): array
    {
        return match ($this->source) {
            self::SOURCE_USERS => $this->userIds,
            self::SOURCE_GLOBAL_PERMISSION => $resolver->usersWithGlobalPermission(
                $this->permission,
                $this->spaceId,
                $limit,
            ),
            self::SOURCE_PERMISSION_ON_OBJECT => $resolver->usersWithPermissionOnObject(
                $this->permission,
                (string) $this->extension,
                (string) $this->object,
                (int) $this->objectId,
                $this->spaceId,
                $limit,
            ),
            self::SOURCE_GLOBAL_ROLE => $resolver->usersWithGlobalRole(
                (int) $this->roleId,
                $this->spaceId,
                $limit,
            ),
            self::SOURCE_ROLE_ON_OBJECT => $resolver->usersWithRoleOnObject(
                (int) $this->roleId,
                (string) $this->extension,
                (string) $this->object,
                (int) $this->objectId,
                $this->spaceId,
                $limit,
            ),
        };
    }
}
