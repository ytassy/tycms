<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Acl;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclUserResolver;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/**
 * Contrat de refus du resolver d'audience.
 *
 * Les cas qui demandent la base sont couverts par
 * `tests/Integration/AclUserResolverIntegrationTest.php`.
 */
final class AclUserResolverTest extends FrameworkTestCase
{
    #[Test]
    public function everyMethodRefusesANonPositiveLimit(): void
    {
        $resolver = new AclUserResolver($this->createApplication());

        $calls = [
            'permission globale' => fn (int $limit): array
                => $resolver->usersWithGlobalPermission('plg_core__USERS_READ', 1, $limit),
            'permission sur objet' => fn (int $limit): array
                => $resolver->usersWithPermissionOnObject('plg_core__PAGE_READ', 'plg_core', 'page', 3, 1, $limit),
            'rôle global' => fn (int $limit): array
                => $resolver->usersWithGlobalRole(4, 1, $limit),
            'rôle sur objet' => fn (int $limit): array
                => $resolver->usersWithRoleOnObject(4, 'plg_core', 'page', 3, 1, $limit),
        ];

        foreach ($calls as $label => $call) {
            foreach ([0, -1] as $limit) {
                try {
                    $call($limit);
                    self::fail(sprintf('La limite %d aurait dû être refusée pour %s.', $limit, $label));
                } catch (InvalidArgumentException) {
                    self::assertTrue(true);
                }
            }
        }
    }

    #[Test]
    public function malformedPermissionIsRefused(): void
    {
        $resolver = new AclUserResolver($this->createApplication());

        foreach ([
            'USERS_READ',
            'plg_core__',
            '__USERS_READ',
            'plg_core__users_read',
            'plg.core__USERS_READ',
            'plg_core__USERS-READ',
        ] as $permission) {
            try {
                $resolver->usersWithGlobalPermission($permission, 1, 10);
                self::fail(sprintf('La permission "%s" aurait dû être refusée.', $permission));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function invalidScopeReturnsAnEmptyListWithoutDatabaseAccess(): void
    {
        // Aucune base n'est initialisée : tout accès SQL échouerait ici.
        $resolver = new AclUserResolver($this->createApplication());

        self::assertSame([], $resolver->usersWithGlobalPermission('plg_core__USERS_READ', 0, 10));
        self::assertSame([], $resolver->usersWithGlobalRole(4, 0, 10));
        self::assertSame([], $resolver->usersWithGlobalRole(0, 1, 10));
        self::assertSame([], $resolver->usersWithPermissionOnObject(
            'plg_core__PAGE_READ', 'plg_core', 'page', 0, 1, 10,
        ));
        self::assertSame([], $resolver->usersWithRoleOnObject(4, 'plg_core', 'page', 0, 1, 10));
    }
}
