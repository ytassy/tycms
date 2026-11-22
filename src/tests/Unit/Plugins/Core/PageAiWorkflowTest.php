<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageHelper;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\WF\WFPage;
use YTassy\Tycms\Response\ExceptionResponse;

final class PageAiWorkflowTest extends TestCase
{
    private function workflow(bool $allowAi = true, bool $allowEdit = true): PageAiWorkflowStub
    {
        $page = (new \ReflectionClass(Page::class))->newInstanceWithoutConstructor();
        $page->id = 12;
        $page->space_id = 7;
        $page->type = 2;

        $pages = $this->createMock(PageHelper::class);
        $pages->expects($this->atLeastOnce())->method('getPage')->willReturn($page);
        $acl = $this->createMock(AclHelper::class);
        $acl->expects($this->atLeastOnce())->method('hasGlobalPermission')->willReturnCallback(
            static fn (string $permission, int $spaceId): bool => $spaceId === 7
                && ($permission !== Permissions::AI_USE || $allowAi)
                && ($permission !== Permissions::PAGE_CRUD || $allowEdit),
        );
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $workflow = new PageAiWorkflowStub($registry);
        $workflow->services = [PageHelper::class => $pages, AclHelper::class => $acl,
            PageComponentRegistry::class => $registry];
        return $workflow;
    }

    public function testExternalDraftIsCleanedWithoutRequiringAiPermissions(): void
    {
        $document = PageFixtures::decode('{"items":[{"id":"r1","type":"core.row","columns":[{"id":"c1","items":[{"id":"h1","type":"core.html","props":{"content":"<p onclick=alert(1)>Bonjour</p><script>alert(2)</script>"}}]}]}]}');
        $result = $this->workflow(false)->validateDraft((object) [
            'page_id' => 12, 'space_id' => 7, 'data' => $document,
        ]);
        self::assertSame('<p>Bonjour</p>', $result['data']->items[0]->zones->columns[0]->items[0]->props->content);
        self::assertSame('h1', $result['data']->items[0]->zones->columns[0]->items[0]->id);
    }

    public function testExternalDraftRequiresPageCrud(): void
    {
        $this->expectException(ExceptionResponse::class);
        $this->workflow(false, false)->validateDraft((object) [
            'page_id' => 12, 'space_id' => 7, 'data' => (object) ['items' => []],
        ]);
    }

    public function testExternalDraftCannotOverridePageSpace(): void
    {
        $this->expectException(ExceptionResponse::class);
        $this->workflow()->validateDraft((object) [
            'page_id' => 12, 'space_id' => 8, 'data' => (object) ['items' => []],
        ]);
    }

    public function testExternalDraftRejectsInvalidRoot(): void
    {
        $this->expectException(ExceptionResponse::class);
        $this->workflow()->validateDraft((object) [
            'page_id' => 12, 'space_id' => 7,
            'data' => PageFixtures::decode('{"items":[{"id":"h1","type":"core.heading","props":{"text":"Titre"}}]}'),
        ]);
    }

    public function testAiHtmlIsSanitizedBeforeReturningToTheEditor(): void
    {
        $result = $this->workflow()->sanitizeAiHtml((object) [
            'page_id' => 12, 'space_id' => 7, 'html' => '<p>Bonjour</p><script>alert(1)</script>',
        ]);
        self::assertSame('<p>Bonjour</p>', $result['html']);
    }

    public function testAiDraftIsValidatedWithoutSaving(): void
    {
        $document = PageFixtures::decode('{"items":[{"id":"r1","type":"core.row","columns":[{"id":"c1","items":[{"id":"h1","type":"core.heading","props":{"text":"Titre"}}]}]}]}', false, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['valid' => true], $this->workflow()->validateAiDraft((object) [
            'page_id' => 12, 'space_id' => 7, 'data' => $document,
        ]));
    }

    public function testAiDraftRejectsAComponentAtTheRoot(): void
    {
        $document = PageFixtures::decode('{"items":[{"id":"h1","type":"core.heading","props":{"text":"Titre"}}]}', false, 512, JSON_THROW_ON_ERROR);
        $this->expectException(ExceptionResponse::class);
        $this->workflow()->validateAiDraft((object) [
            'page_id' => 12, 'space_id' => 7, 'data' => $document,
        ]);
    }

    public function testAiAccessOrWrongSpaceIsRejected(): void
    {
        $this->expectException(ExceptionResponse::class);
        $this->workflow(false)->sanitizeAiHtml((object) [
            'page_id' => 12, 'space_id' => 7, 'html' => '<p>Bonjour</p>',
        ]);
    }

    public function testSubmittedSpaceCannotOverrideThePageSpace(): void
    {
        $this->expectException(ExceptionResponse::class);
        $this->workflow()->sanitizeAiHtml((object) [
            'page_id' => 12, 'space_id' => 8, 'html' => '<p>Bonjour</p>',
        ]);
    }
}

class PageAiWorkflowStub extends WFPage
{
    /** @var array<class-string,BaseApplicationItem> */
    public array $services = [];

    public function getSharedExtensionsClass(string $className): BaseApplicationItem
    {
        return $this->services[$className];
    }
}
