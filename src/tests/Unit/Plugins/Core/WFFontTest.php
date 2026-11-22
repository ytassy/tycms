<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Fonts\FontHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\WF\WFFont;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use YTassy\Tycms\Response\ExceptionResponse;

final class WFFontTest extends FrameworkTestCase
{
    public function testAdditionRequiresPermissionOnTheRequestedSpaceBeforeDownloading(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::never())->method('install');
        $wf = $this->workflow($fonts, [7]);
        $this->expectException(AccessDeniedException::class);
        $wf->install(8, 'google_roboto');
    }

    public function testSpaceAdministratorCanAddASharedFont(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::once())->method('install')->with('google_roboto')->willReturn(['id' => 'google_roboto']);
        self::assertSame(['id' => 'google_roboto'], $this->workflow($fonts, [7])->install(7, 'google_roboto'));
    }

    public function testInstallationFailureReturnsHttp422InsteadOfSuccess(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::once())->method('install')->willThrowException(new \RuntimeException('Dossier inaccessible en écriture'));
        try {
            $this->workflow($fonts, [7])->install(7, 'google_roboto');
            self::fail('Le refus d’écriture doit être une erreur HTTP.');
        } catch (ExceptionResponse $exception) {
            self::assertSame(422, $exception->getResponse()->code);
            self::assertTrue(json_decode($exception->getResponse()->body, true)['error']);
        }
    }

    public function testAdditionNeverAllowsDeletion(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::never())->method('delete');
        $this->expectException(AccessDeniedException::class);
        $this->workflow($fonts, [7])->delete('google_roboto');
    }

    public function testManagementInAChildSpaceDoesNotAllowChangingGlobalState(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::never())->method('setEnabled');
        $this->expectException(AccessDeniedException::class);
        $this->workflow($fonts, [], [7])->setEnabled('google_roboto', false);
    }

    public function testGlobalManagerCanDisableAndDelete(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::once())->method('setEnabled')->with('google_roboto', false);
        $fonts->expects(self::once())->method('delete')->with('google_roboto');
        $wf = $this->workflow($fonts, [], [1]);
        $wf->setEnabled('google_roboto', false);
        $wf->delete('google_roboto');
    }

    public function testFontAdditionPermissionCannotRefreshTheSharedCatalog(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::never())->method('refreshCatalog');
        $this->expectException(AccessDeniedException::class);
        $this->workflow($fonts, [7])->refreshCatalog();
    }

    public function testOnlyTheGlobalManagerCanRefreshTheCatalog(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $expected = ['count' => 2000, 'updatedAt' => '2026-10-31T12:00:00Z'];
        $fonts->expects(self::once())->method('refreshCatalog')->willReturn($expected);
        self::assertSame($expected, $this->workflow($fonts, [], [1])->refreshCatalog());
    }

    public function testChildSpaceManagementCannotRefreshTheSharedCatalog(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::never())->method('refreshCatalog');
        $this->expectException(AccessDeniedException::class);
        $this->workflow($fonts, [], [7])->refreshCatalog();
    }

    public function testCatalogPreviewChoosesAnAvailableNormalVariantNear400(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::once())->method('catalog')->willReturn(['families' => [
            'google_example' => ['family' => 'Example', 'category' => 'Sans Serif', 'variants' => ['100', '300', '400i', '700']],
        ]]);
        $fonts->expects(self::once())->method('installed')->willReturn([]);
        $library = $this->workflow($fonts, [7])->library();
        self::assertSame('300', $library['families'][0]['previewVariant']);
    }

    public function testCatalogIsNotExposedWithoutEitherPermission(): void
    {
        $fonts = $this->createMock(FontHelper::class);
        $fonts->expects(self::never())->method('catalog');
        $this->expectException(AccessDeniedException::class);
        $this->workflow($fonts)->library();
    }

    private function workflow(FontHelper $fonts, array $addSpaces = [], array $manageSpaces = []): WFFont
    {
        $app = $this->createApplication();
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(static fn (string $permission, int $space): bool => match ($permission) {
            Permissions::FONT_ADD => in_array($space, $addSpaces, true),
            Permissions::FONT_MANAGE => in_array($space, $manageSpaces, true),
            default => false,
        });
        $acl->method('getSpacesHavingGlobalPermission')->willReturn($addSpaces);
        $spaces = $this->createStub(SpaceHelper::class);
        $space = new Space($app);
        $space->id = 7;
        $spaces->method('getSpacesByIds')->willReturn([7 => $space]);

        return new class($app, $acl, $spaces, $fonts) extends WFFont {
            public function __construct(Application $app, private AclHelper $acl, private SpaceHelper $spaces, private FontHelper $fonts)
            {
                parent::__construct($app);
            }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    AclHelper::class => $this->acl, SpaceHelper::class => $this->spaces, FontHelper::class => $this->fonts,
                    default => parent::getSharedExtensionsClass($className),
                };
            }
        };
    }
}
