<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHistoryHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\Models\PageHistory;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\WF\WFPage;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use ReflectionProperty;

final class PageHistoryWorkflowTest extends FrameworkTestCase
{
    public function testHistoryListsVersionsForTheRealSpace(): void
    {
        $page = $this->page();
        $history = $this->createMock(PageHistoryHelper::class);
        $history->expects(self::once())->method('listForPage')->with(12)->willReturn([
            (object) [
                'id' => 4,
                'page_id' => 12,
                'user_id' => 5,
                'name' => 'Ancienne page',
                'description' => 'Ancienne description',
                'type' => 2,
                'data_size' => 2048,
                'created' => '2026-11-24 10:30:00',
                'first_name' => 'Yann',
                'last_name' => 'Tassy',
                'user_type' => 'user',
            ],
        ]);

        $workflow = $this->workflow($page, $history);

        $result = $workflow->history(12);

        self::assertSame(12, $result['page']['id']);
        self::assertSame(4, $result['versions'][0]['id']);
        self::assertSame('Yann Tassy', $result['versions'][0]['userName']);
        self::assertSame('2026-11-24T10:30:00+00:00', $result['versions'][0]['created']);
        self::assertSame(PageHistoryHelper::CURRENT_DAY_VERSIONS, $result['retention']['currentDayVersions']);
    }

    public function testHistoryHidesAuthorNameWithoutUserReadPermission(): void
    {
        $page = $this->page();
        $history = $this->createMock(PageHistoryHelper::class);
        $history->expects(self::once())->method('listForPage')->with(12)->willReturn([
            (object) [
                'id' => 4, 'page_id' => 12, 'user_id' => 5, 'name' => 'Ancienne page',
                'description' => '', 'type' => 2, 'data_size' => 10,
                'created' => '2026-11-24 10:30:00',
                'first_name' => 'Yann', 'last_name' => 'Tassy', 'user_type' => 'user',
            ],
        ]);

        $workflow = $this->workflow($page, $history, canReadUsers: false);
        $result = $workflow->history(12);

        self::assertSame('', $result['versions'][0]['userName']);
        self::assertSame(5, $result['versions'][0]['userId']);
    }

    public function testHistoryVersionIsReadWithThePageScope(): void
    {
        $page = $this->page();
        $version = $this->version();
        $history = $this->createMock(PageHistoryHelper::class);
        $history->expects(self::once())->method('getVersion')->with(12, 4)->willReturn($version);

        $result = $this->workflow($page, $history)->historyVersion(12, 4);

        self::assertSame(4, $result['id']);
        self::assertSame(12, $result['pageId']);
        self::assertSame('<p>Ancien</p>', $result['rawHtml']);
        self::assertSame(3, $result['data']->version);
        self::assertSame([], $result['data']->items);
    }

    public function testMissingVersionIsRejected(): void
    {
        $page = $this->page();
        $history = $this->createMock(PageHistoryHelper::class);
        $history->expects(self::once())->method('getVersion')->with(12, 99)->willReturn(null);

        $this->expectException(ExceptionResponse::class);

        $this->workflow($page, $history)->historyVersion(12, 99);
    }

    public function testSaveRecordsTheReplacedStateBeforeWriting(): void
    {
        $app = $this->createApplication();
        $page = $this->page($app);
        $page->type = 1;
        $page->raw_html = '<p>Ancien</p>';
        $page->data = null;

        $saved = $this->page($app);
        $saved->type = 1;
        $saved->raw_html = '<p>Nouveau</p>';
        $saved->data = null;
        $saved->name = 'Nouvelle page';

        $pages = $this->createMock(PageHelper::class);
        $pages->expects(self::once())->method('getPageForUpdate')->with(12)->willReturn($page);
        $pages->expects(self::once())->method('savePage')->with($page, 7, self::callback(
            static fn (object $data): bool => $data->name === 'Nouvelle page'
                && $data->type === 1 && $data->raw_html === '<p>Nouveau</p>',
        ))->willReturn($saved);
        $pages->expects(self::never())->method('updateCompiledCss');

        $acl = $this->createMock(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission): bool => in_array($permission, [Permissions::PAGE_READ, Permissions::PAGE_CRUD], true),
        );
        $acl->method('isRoot')->willReturn(true);

        $history = $this->createMock(PageHistoryHelper::class);
        $history->expects(self::once())->method('record')->with($page, 5);

        $space = $this->createMock(Space::class);
        $space->method('getMediasDir')->willReturn(sys_get_temp_dir());
        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->method('getSpace')->with(7)->willReturn($space);

        $session = $this->createMock(Session::class);
        $session->method('getUserId')->willReturn(5);
        (new ReflectionProperty(\YTassy\Tycms\Application::class, 'session'))->setValue($app, $session);

        $workflow = new PageHistoryWorkflowStub($app);
        $workflow->services = [
            PageHelper::class => $pages,
            AclHelper::class => $acl,
            PageHistoryHelper::class => $history,
            SpaceHelper::class => $spaces,
        ];

        $revision = hash('sha256', serialize([
            $page->name, $page->description, $page->type, $page->raw_html,
            $page->raw_scss, $page->data, $page->access_edit_role_id,
        ]));

        $workflow->save(12, (object) [
            'space_id' => 7,
            'name' => 'Nouvelle page',
            'description' => '',
            'type' => 1,
            'raw_html' => '<p>Nouveau</p>',
            'raw_scss' => '',
            'data' => null,
            'revision' => $revision,
        ]);
    }

    private function workflow(Page $page, PageHistoryHelper $history, bool $canReadUsers = true): PageHistoryWorkflowStub
    {
        $app = $this->createApplication();

        $pages = $this->createMock(PageHelper::class);
        $pages->method('getPage')->with(12)->willReturn($page);

        $acl = $this->createMock(AclHelper::class);
        $acl->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission): bool => $permission !== Permissions::USERS_READ || $canReadUsers,
        );

        $workflow = new PageHistoryWorkflowStub($app);
        $workflow->services = [
            PageHelper::class => $pages,
            AclHelper::class => $acl,
            PageHistoryHelper::class => $history,
        ];

        return $workflow;
    }

    private function page(?\YTassy\Tycms\Application $app = null): Page
    {
        $page = new Page($app ?? $this->createApplication());
        $page->id = 12;
        $page->space_id = 7;
        $page->user_defined = true;
        $page->name = 'Ancienne page';
        $page->description = 'Ancienne description';
        $page->type = 2;
        $page->status = 1;
        $page->raw_html = null;
        $page->raw_scss = '';
        $page->data = (object) ['version' => 3, 'container' => (object) ['fluid' => false], 'items' => []];
        $page->access_edit_role_id = null;
        $page->updated = null;

        return $page;
    }

    private function version(): PageHistory
    {
        $version = new PageHistory($this->createApplication());
        $version->id = 4;
        $version->page_id = 12;
        $version->user_id = 5;
        $version->name = 'Ancienne page';
        $version->description = 'Ancienne description';
        $version->type = 1;
        $version->raw_html = '<p>Ancien</p>';
        $version->raw_scss = '';
        $version->data = (object) ['version' => 3, 'items' => []];
        $version->access_edit_role_id = null;
        $version->created = new \DateTimeImmutable('2026-11-24 10:30:00', new \DateTimeZone('UTC'));

        return $version;
    }
}

class PageHistoryWorkflowStub extends WFPage
{
    /** @var array<class-string,BaseApplicationItem> */
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}
