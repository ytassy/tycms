<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\CoreExts\Core\PageComponents\Mermaid;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\ShortCodeHelper;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PageMermaidTest extends FrameworkTestCase
{
    public function testInheritanceIsResolvedAtEachRenderWithoutPersistingTheDefaults(): void
    {
        $config = (object) ['mermaid_default_theme' => 'forest', 'mermaid_default_width' => '80%'];
        $helper = $this->createMock(ShortCodeHelper::class);
        $helper->expects(self::exactly(2))->method('configuration')->with(7)->willReturn($config);
        $renderer = new Mermaid(PageFixtures::application([ShortCodeHelper::class => $helper]));
        $source = "flowchart LR\n  A[\"<b>[[USER_name]]</b>\"] --> B";
        $props = (object) ['diagram' => $source, 'options' => (object) ['width' => '600px']];
        $before = serialize($props);
        $first = $renderer->render((object) ['props' => $props], PageFixtures::renderContext());
        $config->mermaid_default_theme = 'dark';
        $second = $renderer->render((object) ['props' => $props], PageFixtures::renderContext());

        self::assertStringContainsString('data-mermaid-theme="forest"', $first);
        self::assertStringContainsString('data-mermaid-theme="dark"', $second);
        self::assertStringContainsString('width:600px', $second);
        self::assertStringContainsString('&lt;b&gt;[[USER_name]]&lt;/b&gt;', $second);
        self::assertStringNotContainsString('<b>', $second);
        self::assertSame($before, serialize($props));
    }

    public static function invalidOptions(): iterable
    {
        yield [(object) ['diagram' => []]];
        yield [(object) ['diagram' => str_repeat('x', 30001)]];
        yield [(object) ['options' => []]];
        yield [(object) ['options' => (object) ['securityLevel' => 'loose']]];
        yield [(object) ['options' => (object) ['width=100px,theme' => 'forest']]];
        yield [(object) ['options' => (object) ['width' => '10px;color:red']]];
        yield [(object) ['options' => (object) ['theme' => 'invalid']]];
        yield [(object) ['options' => (object) ['wrapwidth' => 39]]];
        yield [(object) ['options' => (object) ['look' => 'HANDDRAWN']]];
    }

    #[DataProvider('invalidOptions')]
    public function testUnknownUnsafeAndOversizedValuesAreRejected(object $props): void
    {
        $this->expectException(ExceptionResponse::class);
        Mermaid::validate($props);
    }
}
