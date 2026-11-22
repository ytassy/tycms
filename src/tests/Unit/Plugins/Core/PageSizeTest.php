<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageDataMode;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageSize;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageSizeTest extends TestCase
{
    public function testConfigurationDefaultsAndAbsoluteCeiling(): void
    {
        self::assertSame(1_000_000, PageSize::pageMaximum((object) []));
        self::assertSame(250_000, PageSize::diagramMaximum((object) []));
        self::assertSame(5_000_000, PageSize::pageMaximum((object) ['page_max_size' => PHP_INT_MAX]));
        self::assertSame(1_000_000, PageSize::pageMaximum((object) ['page_max_size' => -1]));
        self::assertSame(100, PageSize::diagramMaximum((object) ['page_max_size' => 100]));
        self::assertSame(450_000, PageSize::diagramMaximum((object) ['page_diagram_max_size' => 450_000]));
    }

    public function testMeasurementUsesThePersistedUtf8AndEscapedSlash(): void
    {
        $data = (object) ['svg' => 'été / 🌻', 'separator' => "\u{2028}"];
        $json = PageSize::encodeDocument($data);
        self::assertStringContainsString('été \\/ 🌻', $json);
        self::assertStringContainsString('\\u2028', $json);
        PageSize::assertDocument($data, strlen($json));
        $this->expectException(ExceptionResponse::class);
        PageSize::assertDocument($data, strlen($json) - 1);
    }

    public function testNormalizationCannotPushTheFinalDocumentOverTheConfiguredLimit(): void
    {
        $app = PageFixtures::application();
        $registry = $app->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $document = PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"col","items":[{"id":"d","type":"core.diagram"}]}]}]}');
        $app->getConf()->page_max_size = strlen(PageSize::encodeDocument($document));

        // La lecture conserve le document ; l'entrée enrichie des défauts doit respecter la limite.
        $registry->normalizeDocument($document, 7, PageDataMode::EDITOR);
        $this->expectException(ExceptionResponse::class);
        $registry->normalizeDocument($document, 7);
    }

    public function testLoweringTheConfiguredLimitDoesNotBreakReadingExistingPages(): void
    {
        $app = PageFixtures::application();
        $app->getConf()->page_max_size = 100;
        $document = PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"col","items":[]}]}]}');
        $registry = $app->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        self::assertSame(3, $registry->normalizeDocument($document, 7, PageDataMode::RENDER)->version);
        $this->expectException(ExceptionResponse::class);
        $registry->normalizeDocument($document, 7);
    }

    public function testStructuralReadingAlwaysRejectsDocumentsOverFiveMegabytes(): void
    {
        $document = (object) ['version' => 3, 'container' => (object) ['fluid' => false], 'items' => [],
            'extra' => str_repeat('x', 5_000_000)];
        $this->expectException(ExceptionResponse::class);
        PageDocument::normalize($document);
    }
}
