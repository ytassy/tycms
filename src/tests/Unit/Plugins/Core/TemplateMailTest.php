<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Admin\ExtensionsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\WF\Admin\WFExtensions;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TemplateMailTest extends FrameworkTestCase
{
    public function testPreviewRequiresTemplateEditOnTheRequestedSpace(): void
    {
        $app = $this->createApplication();
        $space = $this->space($app);
        $mail = $this->createMock(MailAdminHelper::class);
        $mail->expects(self::never())->method('sendTemplateTestHtml');
        $wf = $this->workflow($app, $space, false, $mail);

        $this->expectException(AccessDeniedException::class);
        $wf->sendTemplateTestMail(7, 'test@example.test', 'default', (object) []);
    }

    public function testPreviewRefusesDisabledSending(): void
    {
        $app = $this->createApplication();
        $app->getConf()->mailonline = false;
        $space = $this->space($app);
        $mail = $this->createMock(MailAdminHelper::class);
        $mail->expects(self::never())->method('sendTemplateTestHtml');
        $wf = $this->workflow($app, $space, true, $mail);

        try {
            $wf->sendTemplateTestMail(7, 'test@example.test', 'default', (object) []);
            self::fail('Le blocage global doit empêcher le test.');
        } catch (ExceptionResponse $exception) {
            self::assertStringContainsString('désactivé sur ce site', $exception->getResponse()->getMessage());
        }

        $app->getConf()->mailonline = true;
        $space->params = ['mail_online' => false];
        $this->expectException(ExceptionResponse::class);
        $wf->sendTemplateTestMail(7, 'test@example.test', 'default', (object) []);
    }

    public function testPreviewRefusesASeparateTemplate(): void
    {
        $app = $this->createApplication();
        $space = $this->space($app);
        $space->template = 'tpl_other';
        $mail = $this->createMock(MailAdminHelper::class);
        $mail->expects(self::never())->method('sendTemplateTestHtml');
        $wf = $this->workflow($app, $space, true, $mail);

        $this->expectException(ExceptionResponse::class);
        $wf->sendTemplateTestMail(7, 'test@example.test', 'default', (object) []);
    }

    public function testPreviewSendsValidatedDraftWithoutSavingIt(): void
    {
        $app = $this->createApplication();
        $app->getConf()->mailonline = true;
        $space = $this->space($app);
        $mail = $this->createMock(MailAdminHelper::class);
        $mail->expects(self::once())->method('sendTemplateTestHtml')
            ->with($space, 'test@example.test', self::callback(
                static fn (object $params): bool => $params->preview_text === 'Brouillon'
            ), 'blank')->willReturn(true);
        $wf = $this->workflow($app, $space, true, $mail);

        self::assertTrue($wf->sendTemplateTestMail(7, ' test@example.test ', 'blank', (object) [
            'preview_text' => 'Brouillon',
        ]));
    }

    private function space(Application $app): Space
    {
        $space = new Space($app);
        $space->id = 7;
        $space->template = 'tpl_main';
        $space->params = ['mail_online' => true, 'mail_from' => 'source@example.test'];
        return $space;
    }

    private function workflow(Application $app, Space $space, bool $allowed, MailAdminHelper $mail): WFExtensions
    {
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission, int $spaceId): bool => $allowed
                && $permission === Permissions::TEMPLATE_EDIT && $spaceId === 7
        );
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getSpacesByIds')->willReturnCallback(
            static fn (array $ids): array => in_array(7, $ids, true) ? [7 => $space] : []
        );
        $form = (new Form($app))->setId('template.main.test')
            ->addSection('email', 'E-mail')
            ->addField('email', 'preview_text', 'core.text', ['default' => 'Enregistré']);
        $extensions = new ExtensionsHelper($app);
        $extensions->setFormsByExtension(['tpl_main' => $form]);

        return new class($app, $acl, $spaces, $extensions, $mail) extends WFExtensions {
            public function __construct(
                Application $app,
                private AclHelper $acl,
                private SpaceHelper $spaces,
                private ExtensionsHelper $extensions,
                private MailAdminHelper $mail,
            ) {
                parent::__construct($app);
            }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    AclHelper::class => $this->acl,
                    SpaceHelper::class => $this->spaces,
                    ExtensionsHelper::class => $this->extensions,
                    MailAdminHelper::class => $this->mail,
                    default => parent::getSharedExtensionsClass($className),
                };
            }
        };
    }
}
