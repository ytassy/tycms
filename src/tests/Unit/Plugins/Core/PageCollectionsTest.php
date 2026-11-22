<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Media\MediaHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageCollectionsTest extends TestCase
{
    private function document(string $type = 'testimonials', int $count = 1): object
    {
        $node = (object) ['id' => 'collection', 'type' => 'core.' . $type, 'props' => (object) [], 'zones' => (object) []];
        if ($type === 'testimonials') {
            $node->props->testimonials = array_fill(0, $count, (object) [
                'text' => (object) ['fr' => '<p>Merci <strong>Yann</strong></p><script>alert(1)</script>', 'en' => '<p>Thanks</p>'],
                'author' => '<Ann>', 'role' => 'Entreprise', 'rating' => 4, 'portrait' => '',
            ]);
        } else {
            $name = ['timeline' => 'steps', 'tabs' => 'tabs', 'accordion' => 'panels', 'row' => 'columns'][$type];
            $node->zones->{$name} = [];
            for ($index = 0; $index < $count; $index++) {
                $node->zones->{$name}[] = (object) ['id' => 'step_' . $index,
                    'label' => (object) ['fr' => 'Phase ' . $index, 'en' => 'Step ' . $index],
                    'props' => (object) [], 'items' => [(object) ['id' => 'text_' . $index, 'type' => 'core.rich-text',
                        'props' => (object) ['content' => '<p>Contenu ' . $index . '</p><script>evil()</script>'], 'zones' => (object) []]]];
            }
        }
        return PageFixtures::document((object) ['items' => [(object) ['type' => 'core.row', 'columns' => [(object) ['items' => [$node]]]]]]);
    }

    private function node(object $document): object { return $document->items[0]->zones->columns[0]->items[0]; }

    private function render(object $document, string $locale = 'fr', array $services = []): string
    {
        $app = PageFixtures::application($services);
        $page = new Page($app);
        $page->id = 19;
        $page->space_id = 7;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = $app->getOrAddSharedExtensionsClass(PageComponentRegistry::class)->normalizeDocument($document, 7);
        return (new PageRenderer($app))->render($page, $locale)->getHtml();
    }

    public function testTestimonialsArePurifiedLocalizedAndEscapedWithoutSendingOtherLanguages(): void
    {
        $document = $this->document();
        $html = $this->render($document);
        self::assertStringContainsString('<strong>Yann</strong>', $html);
        self::assertStringContainsString('<div class="page-testimonials-text">', $html);
        self::assertStringContainsString('&lt;Ann&gt;', $html);
        self::assertStringContainsString('4 sur 5', $html);
        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('Thanks', $html);
        self::assertStringContainsString('data-testimonials', $html);
        self::assertStringContainsString('data-autoplay="0"', $html);
        self::assertStringContainsString('<p>Thanks</p>', $this->render($document, 'en'));

        $props = $this->node($document)->props;
        $props->mode = 'grid';
        $props->testimonials[0]->rating = null;
        $html = $this->render($document);
        self::assertStringNotContainsString('page-testimonials-controls', $html);
        self::assertStringNotContainsString('page-testimonials-rating', $html);
    }

    public function testTimelineRendersNestedContentAndOmitsHiddenStepsAndTheirText(): void
    {
        $document = $this->document('timeline', 3);
        $node = $this->node($document);
        $node->props->marker = 'number';
        $node->props->label_position = 'opposite';
        $node->props->timeline_style = (object) ['line_color' => '#aabbcc', 'padding' => 0];
        $node->zones->steps[0]->props->common = (object) ['visible' => false];
        $node->zones->steps[1]->props->common = (object) ['languages' => ['fr']];
        $html = $this->render($document, 'en');
        self::assertStringContainsString('Step 2', $html);
        self::assertStringContainsString('page-timeline--labels-opposite', $html);
        self::assertStringContainsString('Contenu 2', $html);
        self::assertStringContainsString('--pc-line-color:#aabbcc;', $html);
        self::assertStringContainsString('--pc-padding:0px;', $html);
        self::assertStringContainsString('aria-hidden="true">1</span>', $html);
        foreach (['Contenu 0', 'Contenu 1', 'Step 0', 'Step 1', '<script>'] as $text) self::assertStringNotContainsString($text, $html);
    }

    public function testRoleDeniedOnTimelineStepAlsoHidesItsDescendants(): void
    {
        $document = $this->document('timeline', 2);
        $this->node($document)->zones->steps[0]->props->common = (object) ['role_ids' => [3]];
        $html = $this->render($document);
        self::assertStringNotContainsString('Contenu 0', $html);
        self::assertStringContainsString('Contenu 1', $html);
    }

    public static function collectionTypes(): iterable
    {
        foreach (['timeline', 'tabs', 'accordion', 'row', 'testimonials'] as $type) yield $type => [$type];
    }

    #[DataProvider('collectionTypes')]
    public function testOneHundredEntriesAreAcceptedAndTheNextIsRejected(string $type): void
    {
        $normalized = PageFixtures::normalize($this->document($type, 100));
        $node = $this->node($normalized);
        $entries = $type === 'testimonials' ? $node->props->testimonials : array_values(get_object_vars($node->zones))[0];
        self::assertCount(100, $entries);

        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalize($this->document($type, 101));
    }

    public static function invalidValues(): iterable
    {
        yield 'html object' => ['testimonials', 'entry', 'text', (object) ['fr' => []]];
        yield 'too long' => ['testimonials', 'entry', 'author', str_repeat('a', 301)];
        yield 'fractional rating' => ['testimonials', 'entry', 'rating', 4.5];
        yield 'string rating' => ['testimonials', 'entry', 'rating', '5'];
        yield 'high rating' => ['testimonials', 'entry', 'rating', 6];
        yield 'remote image' => ['testimonials', 'entry', 'portrait', 'https://external.test/a.jpg'];
        yield 'image traversal' => ['testimonials', 'entry', 'portrait', '/medias/a/%2e%2e/secret'];
        yield 'image authority' => ['testimonials', 'entry', 'portrait', '/medias/\\external.test/a.jpg'];
        yield 'unknown entry field' => ['testimonials', 'entry', 'callback', 'doSomething'];
        yield 'column count' => ['testimonials', 'props', 'columns_desktop', 7];
        yield 'autoplay string' => ['testimonials', 'props', 'autoplay', 'false'];
        yield 'too fast' => ['testimonials', 'props', 'interval', 500];
        yield 'css injection' => ['testimonials', 'style', 'color', '#fff;display:none'];
        yield 'css expression' => ['timeline', 'style', 'padding', 'calc(1px)'];
        yield 'unknown style' => ['timeline', 'style', 'position', 'absolute'];
        yield 'unknown theme color' => ['timeline', 'style', 'line_color', 'var(--not-in-this-space)'];
        yield 'invalid layout' => ['timeline', 'props', 'layout', 'horizontal'];
        yield 'invalid label position' => ['timeline', 'props', 'label_position', 'opposite" onclick="evil()'];
        yield 'invalid breakpoint' => ['timeline', 'props', 'mobile_breakpoint', 'any'];
        yield 'pointer string' => ['timeline', 'props', 'show_pointer', 'false'];
    }

    #[DataProvider('invalidValues')]
    public function testUntrustedParametersAreRejected(string $type, string $target, string $field, mixed $value): void
    {
        $document = $this->document($type);
        $node = $this->node($document);
        if ($target === 'entry') $node->props->testimonials[0]->{$field} = $value;
        elseif ($target === 'style') $node->props->{$type . '_style'} = (object) [$field => $value];
        else $node->props->{$field} = $value;
        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalize($document);
    }

    public function testPortraitSelectionRequiresMediaRightsOnTheRealSpace(): void
    {
        $document = $this->document();
        $this->node($document)->props->testimonials[0]->portrait = '/medias/space/photo.png';
        $acl = $this->getMockBuilder(AclHelper::class)->disableOriginalConstructor()->onlyMethods(['hasGlobalPermission'])->getMock();
        $acl->expects($this->once())->method('hasGlobalPermission')->with(Permissions::MEDIA_READ, 7)
            ->willReturn(false);
        $this->expectException(ExceptionResponse::class);
        $this->render($document, services: [AclHelper::class => $acl]);
    }

    public function testValidPortraitIsRenderedAndRemovedPortraitIsOmitted(): void
    {
        $document = $this->document();
        $this->node($document)->props->testimonials[0]->portrait = '/medias/space/photo.png';
        $app = PageFixtures::application();
        $acl = $this->getMockBuilder(AclHelper::class)->disableOriginalConstructor()->onlyMethods(['hasGlobalPermission'])->getMock();
        $acl->expects($this->exactly(2))->method('hasGlobalPermission')->with(Permissions::MEDIA_READ, 7)->willReturn(true);
        $space = new Space($app);
        $space->id = 7;
        $spaces = $this->getMockBuilder(SpaceHelper::class)->disableOriginalConstructor()
            ->onlyMethods(['getSpace', 'getSpacesMatchingRequestHost', 'getAllSpacesByIds'])->getMock();
        $spaces->expects($this->exactly(4))->method('getSpace')->with(7)->willReturn($space);
        $spaces->method('getSpacesMatchingRequestHost')->willReturn([]);
        $spaces->method('getAllSpacesByIds')->willReturn([]);
        $media = $this->getMockBuilder(MediaHelper::class)->disableOriginalConstructor()->onlyMethods(['assertSelectableFile'])->getMock();
        $calls = 0;
        $media->expects($this->exactly(4))->method('assertSelectableFile')->with($space, '/medias/space/photo.png', 'image')
            ->willReturnCallback(static function () use (&$calls): void {
                if (++$calls === 4) throw new \RuntimeException('Image retirée après validation');
            });
        $services = [AclHelper::class => $acl, SpaceHelper::class => $spaces, MediaHelper::class => $media];
        self::assertStringContainsString('src="/medias/space/photo.png"', $this->render($document, services: $services));
        $html = $this->render($document, services: $services);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('Yann', $html);
    }

    public function testPortraitFromAnotherSpaceIsRejectedByTheMediaService(): void
    {
        $document = $this->document();
        $this->node($document)->props->testimonials[0]->portrait = '/medias/foreign/photo.png';
        $acl = $this->getMockBuilder(AclHelper::class)->disableOriginalConstructor()->onlyMethods(['hasGlobalPermission'])->getMock();
        $acl->expects($this->once())->method('hasGlobalPermission')->with(Permissions::MEDIA_READ, 7)->willReturn(true);
        $space = new Space(PageFixtures::application());
        $space->id = 7;
        $space->medias_dir = 'real-space';
        $spaces = $this->getMockBuilder(SpaceHelper::class)->disableOriginalConstructor()
            ->onlyMethods(['getSpace', 'getSpacesMatchingRequestHost', 'getAllSpacesByIds'])->getMock();
        $spaces->expects($this->once())->method('getSpace')->with(7)->willReturn($space);
        $spaces->method('getSpacesMatchingRequestHost')->willReturn([]);
        $spaces->method('getAllSpacesByIds')->willReturn([]);
        $this->expectException(ExceptionResponse::class);
        $this->render($document, services: [AclHelper::class => $acl, SpaceHelper::class => $spaces]);
    }
}
