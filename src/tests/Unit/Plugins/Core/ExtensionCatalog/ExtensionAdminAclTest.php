<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\ExtensionCatalog;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminExtensionsController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminExtensionSourceController;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;

final class ExtensionAdminAclTest extends TestCase
{
    #[Test]
    public function installationUpdateAndUninstallRoutesAreRootOnly(): void
    {
        $routes = [
            [AdminExtensionSourceController::class, 'install'],
            [AdminExtensionSourceController::class, 'update'],
            [AdminExtensionSourceController::class, 'updateFinalize'],
            [AdminExtensionSourceController::class, 'updateRollback'],
            [AdminExtensionsController::class, 'uninstall'],
        ];

        foreach ($routes as [$controller, $method]) {
            $attributes = (new ReflectionMethod($controller, $method))->getAttributes(Acl::class);
            self::assertNotSame([], $attributes, $controller . '::' . $method . ' doit déclarer une ACL.');

            $root = false;
            foreach ($attributes as $attribute) {
                $acl = $attribute->newInstance();
                if ($acl->globalPermission === Permissions::ROOT && $acl->spaceId === 1) {
                    $root = true;
                }
            }
            self::assertTrue($root, $controller . '::' . $method . ' doit être réservé à ROOT.');
        }
    }
}
