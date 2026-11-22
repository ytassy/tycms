<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageDocumentTest extends TestCase
{
    public function testV3KeepsGenericZonesAndPurifiesEveryHtmlLanguage(): void
    {
        $input = PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"col","items":[{"id":"html","type":"core.html","props":{"content":{"fr":"<p>Bonjour</p><script>alert(1)</script>","en":""}}}]}]}]}');
        $document = PageFixtures::normalize($input);
        self::assertSame(3, $document->version);
        self::assertSame('<p>Bonjour</p>', $document->items[0]->zones->columns[0]->items[0]->props->content->fr);
        self::assertSame('', $document->items[0]->zones->columns[0]->items[0]->props->content->en);
        self::assertStringContainsString('<script>', $input->items[0]->zones->columns[0]->items[0]->props->content->fr);
    }

    public function testUnavailableContainerKeepsItsChildren(): void
    {
        $input = PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"col","items":[{"id":"missing","type":"lms.missing","props":{"value":4},"zones":{"lessons":[{"id":"lesson","props":{},"items":[{"id":"hr","type":"core.hr"}]}]}}]}]}]}');
        $document = PageFixtures::normalize($input);
        $container = $document->items[0]->zones->columns[0]->items[0];
        self::assertSame(4, $container->props->value);
        self::assertSame('hr', $container->zones->lessons[0]->items[0]->id);
    }

    public function testFindNodeTraversesNestedZones(): void
    {
        $document = PageFixtures::normalize(PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"col","items":[{"id":"target","type":"core.html","props":{"content":"ok"}}]}]}]}'));

        self::assertSame('target', PageDocument::findNode($document, 'target')?->id);
        self::assertNull(PageDocument::findNode($document, 'missing'));
    }

    public function testOldDocumentsAreRejectedWithoutConversion(): void
    {
        $this->expectException(ExceptionResponse::class);
        PageDocument::normalize((object) ['version' => 2, 'items' => []]);
    }

    public function testDuplicateIdAcrossDifferentZonesIsRejected(): void
    {
        $input = PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"same","items":[]},{"id":"same","items":[]}]}]}');
        $this->expectException(ExceptionResponse::class);
        PageDocument::normalize($input);
    }

    public function testLocalizedTextRejectsNonStringVariant(): void
    {
        $input = PageFixtures::decode('{"items":[{"id":"row","type":"core.row","columns":[{"id":"col","items":[{"id":"h","type":"core.heading","props":{"text":{"fr":"Oui","en":{"url":"/bad"}}}}]}]}]}');
        $this->expectException(ExceptionResponse::class);
        PageFixtures::normalize($input);
    }
}
