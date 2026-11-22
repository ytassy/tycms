<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\Tests\Support\PageFixtures;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Media\MediaHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\WF\WFPage;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PageVideoWorkflowTest extends FrameworkTestCase
{
    public static function access(): iterable
    {
        yield 'sans lecture médias' => [false, true];
        yield 'fichier invalide pour ce Space' => [true, false];
        yield 'autorisé' => [true, true];
    }

    #[DataProvider('access')]
    public function testMediaAccessIsCheckedBeforeReadingTheFile(bool $mediaAccess, bool $fileValid): void
    {
        $app = $this->createApplication();
        PageFixtures::initialize($app);
        $page = new Page($app);
        $page->id = 12;
        $page->space_id = 7;
        $page->type = 2;
        $pages = $this->createMock(PageHelper::class);
        $pages->expects(self::once())->method('getPage')->with(12)->willReturn($page);
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::atLeastOnce())->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission, int $spaceId): bool => $spaceId === 7
                && (!in_array($permission, [Permissions::MEDIA_READ, Permissions::MEDIA_CRUD], true) || $mediaAccess),
        );
        $space = new Space($app);
        $space->id = 7;
        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->expects($mediaAccess ? self::once() : self::never())->method('getSpace')->with(7)->willReturn($space);
        $media = $this->createMock(MediaHelper::class);
        $call = $media->expects($mediaAccess ? self::once() : self::never())->method('assertSelectableFile');
        if ($mediaAccess) {
            $call->with($space, '/medias/test/video.mp4', 'video');
            if (!$fileValid) $call->willThrowException(new RuntimeException('Fichier hors Space.'));
        }
        $workflow = new PageVideoWorkflowStub($app);
        $workflow->services = [PageHelper::class => $pages, AclHelper::class => $acl, MediaHelper::class => $media, SpaceHelper::class => $spaces,
            PageComponentRegistry::class => PageFixtures::initialize($app, [AclHelper::class => $acl, MediaHelper::class => $media, SpaceHelper::class => $spaces])];
        $document = PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"items":[{"type":"core.video","props":{"source":"media","src":"/medias/test/video.mp4"}}]}]}]}', false, 512, JSON_THROW_ON_ERROR);
        if (!$mediaAccess || !$fileValid) $this->expectException(ExceptionResponse::class);

        $result = $workflow->validateAiDraft((object) ['page_id' => 12, 'space_id' => 7, 'data' => $document]);
        if ($mediaAccess && $fileValid) self::assertSame(['valid' => true], $result);
    }
}

class PageVideoWorkflowStub extends WFPage
{
    public array $services = [];
    public function getSharedExtensionsClass(string $className): BaseApplicationItem { return $this->services[$className]; }
}
