<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceAdminHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\WF\WFSpace;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SpaceMailTest extends FrameworkTestCase
{
    public function testMailTestRequiresEditOrAdminOnTheActualSpace(): void
    {
        $app = $this->createApplication();
        $space = new Space($app);
        $space->id = 7;
        $spaceHelper = $this->createStub(SpaceAdminHelper::class);
        $spaceHelper->method('getSpace')->willReturn($space);
        $aclHelper = $this->createStub(AclHelper::class);
        $aclHelper->method('hasGlobalPermission')->willReturn(false);
        $mailHelper = $this->createMock(MailAdminHelper::class);
        $mailHelper->expects(self::never())->method('sendTestHtml');

        $wf = $this->workflow($app, $spaceHelper, $aclHelper, $mailHelper);
        $this->expectException(ExceptionResponse::class);
        $wf->sendTestMail(7, 'test@example.test', (object) ['mail_online' => true]);
    }

    public function testMailTestUsesEditPermissionAndRefusesDisabledSending(): void
    {
        $app = $this->createApplication();
        $app->getConf()->mailonline = true;
        $space = new Space($app);
        $space->id = 7;
        $spaceHelper = $this->createStub(SpaceAdminHelper::class);
        $spaceHelper->method('getSpace')->willReturn($space);
        $aclHelper = $this->createStub(AclHelper::class);
        $aclHelper->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission, int $spaceId): bool => $permission === Permissions::SPACE_EDIT && $spaceId === 7
        );
        $mailHelper = $this->createMock(MailAdminHelper::class);
        $mailHelper->expects(self::once())->method('sendTestHtml')
            ->with($space, 'test@example.test', ['mail_online' => true])->willReturn(true);

        $wf = $this->workflow($app, $spaceHelper, $aclHelper, $mailHelper);
        try {
            $wf->sendTestMail(7, 'test@example.test', (object) ['mail_online' => false]);
            self::fail('Un essai désactivé doit être refusé.');
        } catch (ExceptionResponse) {
            self::assertTrue($wf->sendTestMail(7, 'test@example.test', (object) ['mail_online' => true]));
        }
    }

    public function testMailTestRejectsInvalidRecipientBeforeSending(): void
    {
        $app = $this->createApplication();
        $app->getConf()->mailonline = true;
        $space = new Space($app);
        $space->id = 7;
        $spaceHelper = $this->createStub(SpaceAdminHelper::class);
        $spaceHelper->method('getSpace')->willReturn($space);
        $aclHelper = $this->createStub(AclHelper::class);
        $aclHelper->method('hasGlobalPermission')->willReturn(true);
        $mailHelper = $this->createMock(MailAdminHelper::class);
        $mailHelper->expects(self::never())->method('sendTestHtml');

        $wf = $this->workflow($app, $spaceHelper, $aclHelper, $mailHelper);
        $this->expectException(ExceptionResponse::class);
        $wf->sendTestMail(7, 'adresse invalide', (object) ['mail_online' => true]);
    }

    public function testGlobalMailSwitchReturnsAnApiMessageBeforeSending(): void
    {
        $app = $this->createApplication();
        $app->getConf()->mailonline = false;
        $space = new Space($app);
        $space->id = 7;
        $spaceHelper = $this->createStub(SpaceAdminHelper::class);
        $spaceHelper->method('getSpace')->willReturn($space);
        $aclHelper = $this->createStub(AclHelper::class);
        $aclHelper->method('hasGlobalPermission')->willReturn(true);
        $mailHelper = $this->createMock(MailAdminHelper::class);
        $mailHelper->expects(self::never())->method('sendTestHtml');

        $wf = $this->workflow($app, $spaceHelper, $aclHelper, $mailHelper);
        try {
            $wf->sendTestMail(7, 'test@example.test', (object) ['mail_online' => true]);
            self::fail('Le réglage global doit empêcher l’essai.');
        } catch (ExceptionResponse $exception) {
            self::assertSame('L’envoi d’e-mails est désactivé sur ce site.', $exception->getResponse()->getMessage());
        }
    }

    private function workflow(
        Application $app,
        SpaceAdminHelper $spaceHelper,
        AclHelper $aclHelper,
        MailAdminHelper $mailHelper,
    ): WFSpace {
        return new class($app, $spaceHelper, $aclHelper, $mailHelper) extends WFSpace {
            public function __construct(
                Application $app,
                private SpaceAdminHelper $spaceHelper,
                private AclHelper $aclHelper,
                private MailAdminHelper $mailHelper,
            ) {
                parent::__construct($app);
            }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    SpaceAdminHelper::class => $this->spaceHelper,
                    AclHelper::class => $this->aclHelper,
                    MailAdminHelper::class => $this->mailHelper,
                    default => parent::getSharedExtensionsClass($className),
                };
            }
        };
    }
}
