<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\TyCatalog;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\TyCatalog\Permissions;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TyCatalogPermissionsTest extends FrameworkTestCase
{
    #[Test]
    public function permissionsAreDeclaredWithTheExtensionPrefix(): void
    {
        $permissions = (new Permissions($this->createApplication()))->getPermissions();
        $names = array_map(static fn ($permission): string => $permission->name, $permissions);

        self::assertCount(4, $permissions);
        self::assertContains('plg_ty_catalog__VIEW', $names);
        self::assertContains('plg_ty_catalog__EDIT', $names);
        self::assertContains('plg_ty_catalog__PUBLISH', $names);
        self::assertContains('plg_ty_catalog__ADMIN', $names);

        // La configuration du stockage et des clés reste hors de l'édition des rôles.
        $admin = array_values(array_filter(
            $permissions,
            static fn ($permission): bool => $permission->name === 'plg_ty_catalog__ADMIN',
        ))[0];
        self::assertFalse($admin->manageableByRoleCrud);
    }
}
