<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionReference;

final class BuiltinFunctionReferenceTest extends TestCase
{
    #[Test]
    public function referenceCoversEveryStandardBuiltin(): void
    {
        $catalogIds = array_values(array_filter(
            array_keys(BuiltinFunctionCatalog::create()->all()),
            static fn (string $id): bool => !str_starts_with($id, 'core::'),
        ));
        $referenceIds = array_column(BuiltinFunctionReference::all(), 'id');
        sort($catalogIds);
        sort($referenceIds);

        self::assertSame($catalogIds, $referenceIds);
    }

    #[Test]
    public function everyReferenceIsUniqueAndSelfDescribing(): void
    {
        $reference = BuiltinFunctionReference::all();
        $ids = array_column($reference, 'id');

        self::assertSame(count($ids), count(array_unique($ids)));
        self::assertGreaterThan(100, count($reference));

        foreach ($reference as $builtin) {
            self::assertStringStartsWith($builtin['id'] . '(', $builtin['signature']);
            self::assertNotSame('', trim($builtin['description']));
            self::assertContains($builtin['namespace'], [
                'str',
                'arr',
                'math',
                'date',
                'json',
                'type',
                'util',
                'res',
            ]);
        }
    }
}
