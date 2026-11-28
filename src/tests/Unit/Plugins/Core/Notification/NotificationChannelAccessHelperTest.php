<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Notification;

use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Notification\NotificationChannelAccessHelper;
use YTassy\Tycms\CoreExts\Core\Helper\UserHelper;
use YTassy\Tycms\CoreExts\Core\Models\User;
use YTassy\Tycms\CoreExts\Core\Notification\NotificationChannelDefinition;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class NotificationChannelAccessHelperTest extends FrameworkTestCase
{
    #[Test]
    public function openChannelIsAllowedWithoutReadingTheAccount(): void
    {
        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->expects(self::never())->method('getUser');
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::never())->method('userHasGlobalPermission');

        $helper = $this->helper($userHelper, $acl);

        self::assertTrue($helper->isAllowedForUser(42, $this->channel(null)));
    }

    #[Test]
    public function invalidAccountIsRefusedEvenForAnOpenChannel(): void
    {
        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->expects(self::never())->method('getUser');
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::never())->method('userHasGlobalPermission');

        $helper = $this->helper($userHelper, $acl);

        self::assertFalse($helper->isAllowedForUser(0, $this->channel(null)));
    }

    #[Test]
    public function permissionIsCheckedOnThePrincipalSpaceOfTheAccount(): void
    {
        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->expects(self::once())->method('getUser')->with(42)->willReturn($this->user(7));

        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::once())
            ->method('userHasGlobalPermission')
            ->with(42, 'plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL', 7)
            ->willReturn(true);

        $helper = $this->helper($userHelper, $acl);

        self::assertTrue($helper->isAllowedForUser(42, $this->channel('plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL')));
    }

    #[Test]
    public function deniedPermissionIsRefused(): void
    {
        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->expects(self::once())->method('getUser')->willReturn($this->user(7));

        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::once())->method('userHasGlobalPermission')->willReturn(false);

        $helper = $this->helper($userHelper, $acl);

        self::assertFalse($helper->isAllowedForUser(42, $this->channel('plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL')));
    }

    #[Test]
    public function unknownAccountIsRefused(): void
    {
        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->expects(self::once())->method('getUser')->willReturn(null);

        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::never())->method('userHasGlobalPermission');

        $helper = $this->helper($userHelper, $acl);

        self::assertFalse($helper->isAllowedForUser(42, $this->channel('plg_webhook__USE_NOTIF_WEBHOOK_CHANNEL')));
    }

    #[Test]
    public function accountIsReadOnceWhenSeveralChannelsAreFiltered(): void
    {
        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->expects(self::once())->method('getUser')->willReturn($this->user(7));

        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::exactly(2))->method('userHasGlobalPermission')->willReturnCallback(
            static fn (int $userId, string $permission, int $spaceId): bool
                => $permission === 'plg_webhook__USE'
        );

        $helper = $this->helper($userHelper, $acl);

        $allowed = $helper->allowedChannelIdsForUser(42, [
            $this->channel('plg_webhook__USE'),
            $this->channel('plg_other__USE', 'other'),
        ]);

        self::assertSame(['webhook'], $allowed);
    }

    private function helper(UserHelper $userHelper, AclHelper $acl): NotificationChannelAccessHelper
    {
        $app = $this->createApplication();
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')->setValue($app, [
            UserHelper::class => $userHelper,
            AclHelper::class => $acl,
        ]);

        return new NotificationChannelAccessHelper($app);
    }

    private function channel(?string $permission, string $id = 'webhook'): NotificationChannelDefinition
    {
        return new NotificationChannelDefinition($id, 'Webhook', 2000, $permission);
    }

    private function user(int $spaceId): User
    {
        $user = (new ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $user->space_id = $spaceId;

        return $user;
    }
}
