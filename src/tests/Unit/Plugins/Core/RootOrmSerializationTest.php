<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsOrm\Acl;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Models\User;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class RootOrmSerializationTest extends FrameworkTestCase
{
    public static function actors(): iterable
    {
        yield 'sans ROOT' => [false];
        yield 'avec permission ROOT' => [true];
    }

    #[DataProvider('actors')]
    public function testUserFieldsCheckPermissionAndNeverExposeSecrets(bool $root): void
    {
        $app = $this->createApplication();
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::never())->method('hasGlobalRole');
        $acl->expects(self::atLeastOnce())->method('hasGlobalPermission')->with(Permissions::ROOT, 1)->willReturn($root);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [AclHelper::class => $acl]);
        $user = new User($app);
        $user->id = 12;
        $user->password = 'secret-hash';
        $user->reset_password_token = 'secret-token';
        $user->params = (object) ['internal' => true];

        $data = $user->jsonSerialize();
        self::assertSame($root, array_key_exists('params', $data));
        self::assertArrayNotHasKey('password', $data);
        self::assertArrayNotHasKey('reset_password_token', $data);
        self::assertSame(12, $data['id']);
    }

    public function testOrmAclCombinesDeclaredRoleAndPermissionAndRefusesEmptyAcl(): void
    {
        $app = $this->createApplication();
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturn(true);
        $acl->method('hasGlobalRole')->willReturn(false);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [AclHelper::class => $acl]);

        $data = (new OrmAclSample($app))->jsonSerialize();
        self::assertArrayHasKey('permissionOnly', $data);
        self::assertArrayNotHasKey('roleAndPermission', $data);
        self::assertArrayNotHasKey('empty', $data);
    }
}

class OrmAclSample extends BaseOrmObject
{
    #[Acl(globalPermission: Permissions::PAGE_READ, spaceId: 2)]
    public string $permissionOnly = 'allowed';

    #[Acl(globalRole: 42, globalPermission: Permissions::PAGE_READ, spaceId: 2)]
    public string $roleAndPermission = 'protected';

    #[Acl]
    public string $empty = 'protected';
}
