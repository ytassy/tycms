<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use Configuration;
use DirectoryIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Media\MediaHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageFileList;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\WF\WFMedia;
use YTassy\Tycms\Response\ExceptionResponse;

final class PageFileListTest extends TestCase
{
    private string $directory;
    private Space $space;
    private MediaHelper $media;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/ty-page-files-' . bin2hex(random_bytes(8));
        mkdir($this->directory);
        $this->space = new class($this->directory) extends Space {
            public function __construct(private string $root) {}
            public function getMediasDir(): string { return $this->root; }
        };
        $this->space->id = 7;
        $this->space->medias_dir = 'test-files';
        $this->media = new MediaHelper(new Application(new Configuration()));
        mkdir($this->directory . '/nested');
        file_put_contents($this->directory . '/nested/secret.txt', 'Contenu du sous-dossier');
        file_put_contents($this->directory . '/.hidden.txt', 'Masqué');
        file_put_contents($this->directory . '/Note.txt', 'Bonjour');
        file_put_contents($this->directory . '/Image.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        symlink($this->directory . '/Note.txt', $this->directory . '/link.txt');
    }

    protected function tearDown(): void
    {
        $remove = function (string $folder) use (&$remove): void {
            foreach (new DirectoryIterator($folder) as $file) {
                if ($file->isDot()) continue;
                if ($file->isDir() && !$file->isLink()) $remove($file->getPathname());
                else unlink($file->getPathname());
            }
            rmdir($folder);
        };
        $remove($this->directory);
    }

    private function app(bool $readable = true): Application
    {
        $spaceHelper = $this->createStub(SpaceHelper::class);
        $spaceHelper->method('getAllSpacesByIds')->willReturn([7 => $this->space]);
        $acl = $this->createStub(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturn($readable);
        return PageFixtures::application([
            MediaHelper::class => $this->media, SpaceHelper::class => $spaceHelper, AclHelper::class => $acl,
        ]);
    }

    public function testListingOnlyUsesChosenFolderAndBuildsPublicUrls(): void
    {
        $helper = new PageFileList($this->app());
        $files = $helper->files($this->space, (object) ['folder' => '']);
        self::assertSame(['Image.svg', 'Note.txt'], array_column($files, 'name'));
        self::assertSame('/medias/test-files/Note.txt', $files[1]['url']);
        self::assertSame([], $helper->files($this->space, (object) []));
        self::assertSame([], $this->media->listDirectory($this->space, '', false)['tree']);
    }

    public function testPrioritiesRemainFirstAndSecondSortBreaksTies(): void
    {
        $files = [
            ['name' => 'a.png', 'extension' => 'png', 'modified' => 1, 'size' => 10],
            ['name' => 'z.pdf', 'extension' => 'pdf', 'modified' => 2, 'size' => 20],
            ['name' => 'b.pdf', 'extension' => 'pdf', 'modified' => 3, 'size' => 5],
            ['name' => 'd.svg', 'extension' => 'svg', 'modified' => 4, 'size' => 5],
        ];
        $sorted = PageFileList::sortFiles($files, (object) ['sort_1' => 'extension', 'direction_1' => 'desc', 'first_1' => 'PDF,png', 'sort_2' => 'modified', 'direction_2' => 'desc']);
        self::assertSame(['b.pdf', 'z.pdf', 'a.png', 'd.svg'], array_column($sorted, 'name'));
        $sorted = PageFileList::sortFiles($files, (object) ['sort_1' => 'size', 'direction_1' => 'asc', 'sort_2' => 'name', 'direction_2' => 'desc']);
        self::assertSame(['d.svg', 'b.pdf', 'a.png', 'z.pdf'], array_column($sorted, 'name'));
    }

    public function testCompactAndDetailedRenderingEscapeNamesAndSupportThumbnails(): void
    {
        $files = (new PageFileList($this->app()))->files($this->space, (object) ['folder' => '']);
        $files[0]['name'] = '<script>alert(1)</script>.svg';
        $html = PageFileList::renderFiles($files, (object) ['compact' => true, 'show_thumbnails' => true, 'show_size' => false], 'fr');
        self::assertStringContainsString('page-file-list--compact', $html);
        self::assertStringContainsString('page-file-list-thumbnail', $html);
        self::assertStringContainsString('target="_blank" rel="noopener noreferrer"', $html);
        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>', $html);
        $detailed = PageFileList::renderFiles($files, (object) ['show_open' => false, 'show_date' => false], 'fr');
        self::assertStringContainsString('Télécharger', $detailed);
        self::assertStringContainsString('href="/medias/test-files/Note.txt" download="Note.txt"', $detailed);
        self::assertStringNotContainsString('fa-eye', $detailed);
        self::assertStringNotContainsString('<time', $detailed);
    }

    public function testEditorFolderNeedsMediaPermissionAndExactSpace(): void
    {
        $this->expectException(ExceptionResponse::class);
        (new PageFileList($this->app(false)))->validateForEditor((object) ['folder' => ''], 7);
    }

    public function testUnknownEditorSpaceCannotFallBackToAnotherSpace(): void
    {
        $this->expectException(ExceptionResponse::class);
        (new PageFileList($this->app()))->validateForEditor((object) ['folder' => ''], 8);
    }

    public function testPublicRendererRendersFreshDirectoryContentWithoutMediaPermission(): void
    {
        $page = (new \ReflectionClass(\YTassy\Tycms\CoreExts\Core\Models\Page::class))->newInstanceWithoutConstructor();
        $page->id = 17;
        $page->space_id = 7;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"items":[{"type":"core.file-list","props":{"folder":"","compact":true,"show_thumbnails":true}}]}]}]}');
        $renderer = new \YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer($this->app(false));
        $html = $renderer->render($page, 'fr')->getHtml();
        self::assertStringContainsString('Note.txt', $html);
        self::assertStringNotContainsString('secret.txt', $html);
        self::assertStringNotContainsString('.hidden.txt', $html);
        file_put_contents($this->directory . '/Nouveau.txt', 'Nouveau');
        self::assertStringContainsString('Nouveau.txt', $renderer->render($page, 'fr')->getHtml());
    }

    public function testAdminPreviewDoesNotBypassMediaPermission(): void
    {
        $this->expectException(ExceptionResponse::class);
        (new WFMedia($this->app(false)))->pageFileList(7, (object) ['folder' => '']);
    }

    public function testBoundedListingRefusesExcessiveDirectories(): void
    {
        $this->expectException(RuntimeException::class);
        $this->media->listDirectory($this->space, '', false, 1);
    }

    public static function unsafePaths(): iterable
    {
        foreach (['../outside', '/etc/passwd', '.hidden.txt', 'nested/../Note.txt', 'link.txt', 'Note.txt'] as $path) yield [$path];
    }

    #[DataProvider('unsafePaths')]
    public function testChosenFolderRefusesTraversalHiddenPathsSymlinksAndFiles(string $path): void
    {
        $this->expectException(RuntimeException::class);
        $this->media->assertDirectory($this->space, $path);
    }

    public static function invalidOptions(): iterable
    {
        foreach ([['folder' => '../secret'], ['folder' => '/root'], ['folder' => 'hidden/.secret'], ['folder' => "abc\0"], ['folder' => []],
            ['show_thumbnails' => 'true'], ['compact' => 1], ['sort_1' => 'created'], ['direction_2' => 'up'], ['first_1' => []]] as $options) yield [$options];
    }

    #[DataProvider('invalidOptions')]
    public function testOptionsAreValidatedDuringDocumentNormalization(array $options): void
    {
        $document = PageFixtures::decode(json_encode(['items' => [['type' => 'core.row', 'columns' => [['items' => [['type' => 'core.file-list', 'props' => $options]]]]]]], JSON_THROW_ON_ERROR));
        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalize($document);
    }
}
