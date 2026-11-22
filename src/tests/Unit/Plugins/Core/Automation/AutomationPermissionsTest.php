<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\Permissions;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AutomationPermissionsTest extends FrameworkTestCase
{
    #[Test]
    public function permissionsAreDeclaredWithTheExtensionPrefix(): void
    {
        $permissions = (new Permissions($this->createApplication()))->getPermissions();
        $names = array_map(static fn ($permission): string => $permission->name, $permissions);

        self::assertCount(7, $permissions);
        self::assertContains('plg_automation__VIEW', $names);
        self::assertContains('plg_automation__EDIT', $names);
        self::assertContains('plg_automation__PUBLISH', $names);
        self::assertContains('plg_automation__RUN', $names);
        self::assertContains('plg_automation__RUN_MANAGE', $names);
        self::assertContains('plg_automation__RUN_AS_AUTHOR', $names);
        self::assertContains('plg_automation__ADMIN', $names);

        $admin = array_values(array_filter(
            $permissions,
            static fn ($permission): bool => $permission->name === 'plg_automation__ADMIN',
        ))[0];
        self::assertFalse($admin->manageableByRoleCrud);
    }
}
