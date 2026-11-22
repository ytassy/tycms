<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceLanguageHelper;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\Models\SpaceLanguage;
use YTassy\Tycms\CoreExts\Core\WF\WFPage;
use YTassy\Tycms\CoreExts\Security\Helper\SecurityPolicyHelper;
use YTassy\Tycms\CoreExts\Security\SecurityPolicy;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PageRawHtmlTest extends FrameworkTestCase
{
    private const SOURCE = '<p class="intro" onclick="alert(1)">Bonjour <strong>à tous</strong></p>'
        . '<script>alert(2)</script><img src="/medias/image.png" onerror="alert(3)">'
        . '<a href="javascript:alert(4)">Lien</a>';

    private const CLEAN = '<p class="intro">Bonjour <strong>à tous</strong></p>'
        . '<img src="/medias/image.png" /><a rel="noopener noreferrer">Lien</a>';

    public static function saveModes(): iterable
    {
        yield 'création' => [true];
        yield 'modification' => [false];
    }

    #[DataProvider('saveModes')]
    public function testRawHtmlIsCleanedBeforeDatabaseWrite(bool $create): void
    {
        $app = $this->createApplication();
        $page = new Page($app);
        $page->id = 7;

        // Contrôler la valeur réellement transmise au SQL, sans ouvrir de connexion.
        $database = $this->createMock(Database::class);
        $database->expects(self::once())->method('execute')->with(
            self::stringStartsWith($create ? 'INSERT INTO pages' : 'UPDATE pages'),
            self::callback(static fn (array $values): bool => $values[$create ? 5 : 3] === self::CLEAN),
        )->willReturn(1);
        if ($create) $database->expects(self::once())->method('getLastInsertId')->willReturn(7);
        $database->expects(self::once())->method('selectOrmOneOrNull')
            ->with(Page::class, 'SELECT * FROM pages WHERE id = %', [7])->willReturn($page);
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $database);

        (new PageHelper($app))->savePage($create ? null : $page, 1, (object) [
            'name' => 'Page', 'description' => '', 'type' => 1, 'raw_html' => self::SOURCE,
            'raw_scss' => '', 'raw_css' => '', 'access_edit_role_id' => null,
        ]);
    }

    public function testLegacyRawHtmlIsCleanedDuringRenderingWithoutRewritingThePage(): void
    {
        $page = (new ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 7;
        $page->type = 1;
        $page->raw_css = null;
        $page->raw_html = self::SOURCE;

        $renderer = (new ReflectionClass(PageRenderer::class))->newInstanceWithoutConstructor();

        self::assertSame('<div id="page-7">' . self::CLEAN . '</div>', $renderer->render($page)->getHtml(false));
        self::assertSame(self::SOURCE, $page->raw_html);
    }

    private function workflow(bool $root): RawHtmlWorkflowStub
    {
        $app = $this->createApplication();
        $workflow = new RawHtmlWorkflowStub($app);
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::once())->method('hasGlobalPermission')->with(Permissions::PAGE_CRUD, 1)->willReturn(true);
        $acl->expects(self::never())->method('hasGlobalRole');
        $acl->expects(self::once())->method('isRoot')->willReturn($root);
        $space = $this->createMock(Space::class);
        $space->expects($root ? self::once() : self::never())->method('getMediasDir')->willReturn(sys_get_temp_dir());
        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->expects($root ? self::once() : self::never())->method('getSpace')->with(1)->willReturn($space);
        $language = new SpaceLanguage($app);
        $language->locale = 'fr';
        $languages = $this->createMock(SpaceLanguageHelper::class);
        $languages->expects($root ? self::once() : self::never())->method('listActiveForSpace')->with(1)->willReturn([$language]);
        $security = $this->createMock(SecurityPolicyHelper::class);
        $security->expects($root ? self::once() : self::never())->method('forSpace')->with(1)
            ->willReturn(new SecurityPolicy(SecurityPolicy::defaults()));

        $workflow->services = [AclHelper::class => $acl, SpaceHelper::class => $spaces,
            SpaceLanguageHelper::class => $languages, SecurityPolicyHelper::class => $security];

        return $workflow;
    }

    public function testUnsavedRawHtmlPreviewUsesTheSameCleaning(): void
    {
        $result = $this->workflow(true)->preview((object) [
            'space_id' => 1, 'type' => 1, 'raw_html' => self::SOURCE, 'locale' => 'fr',
        ]);

        self::assertSame('<div class="container"><div id="page-0">' . self::CLEAN . '</div></div>', $result['html']);
        self::assertSame('', $result['css']);
    }

    public function testRawHtmlPreviewStillRequiresRoot(): void
    {
        $this->expectException(ExceptionResponse::class);

        $this->workflow(false)->preview((object) [
            'space_id' => 1, 'type' => 1, 'raw_html' => '<p>Bonjour</p>', 'locale' => 'fr',
        ]);
    }
}

class RawHtmlWorkflowStub extends WFPage
{
    /** @var array<class-string,BaseApplicationItem> */
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}
