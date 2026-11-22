<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class ValueToolsTest extends TestCase
{
    #[Test]
    public function rejectsObjectsAndNestedObjects(): void
    {
        foreach ([new \stdClass(), ['nested' => new \stdClass()]] as $value) {
            try {
                ValueTools::assertSafe($value, new Limits(), $this->span());
                self::fail('Un objet PHP doit être refusé.');
            } catch (ExecutionException $exception) {
                self::assertSame('INVALID_VALUE', $exception->diagnostic->code);
            }
        }
    }

    #[Test]
    public function equalityComparesNumbersArraysAndDates(): void
    {
        $date = new DateValue(new DateTimeImmutable('2026-11-15 10:00:00', new DateTimeZone('UTC')));

        self::assertTrue(ValueTools::equals(1, 1.0));
        self::assertFalse(ValueTools::equals(1, '1'));
        self::assertTrue(ValueTools::equals(['a' => 1, 'b' => [2, 3]], ['b' => [2, 3], 'a' => 1]));
        self::assertFalse(ValueTools::equals(['a' => 1], ['a' => 2]));
        self::assertTrue(ValueTools::equals($date, $date));
    }

    #[Test]
    public function concatRejectsArraysButAcceptsScalars(): void
    {
        self::assertSame('a1true', ValueTools::toConcatString('a', $this->span())
            . ValueTools::toConcatString(1, $this->span())
            . ValueTools::toConcatString(true, $this->span()));

        $this->expectException(ExecutionException::class);
        ValueTools::toConcatString([], $this->span());
    }

    #[Test]
    public function jsonSafeConvertsDatesToIsoStrings(): void
    {
        $date = new DateValue(new DateTimeImmutable('2026-11-15 10:00:00', new DateTimeZone('UTC')));

        self::assertSame(
            ['when' => $date->toIso()],
            ValueTools::toJsonSafe(['when' => $date], $this->span()),
        );
    }

    #[Test]
    public function strictConversionsRejectIncompatibleValues(): void
    {
        self::assertSame(1, ValueTools::toInt(1.0, $this->span()));
        self::assertSame(2, ValueTools::toInt('2', $this->span()));

        $this->expectException(ExecutionException::class);
        ValueTools::toInt('2.5', $this->span());
    }

    private function span(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}
