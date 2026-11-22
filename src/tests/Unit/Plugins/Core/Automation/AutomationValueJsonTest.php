<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Runtime\AutomationValueJson;
use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;

final class AutomationValueJsonTest extends TestCase
{
    #[Test]
    public function encodesSafeValuesAndDates(): void
    {
        $date = new DateValue(new DateTimeImmutable('2026-11-15 10:00:00', new DateTimeZone('UTC')));

        self::assertSame(
            '{"name":"x","when":"' . $date->toIso() . '"}',
            AutomationValueJson::encode(['name' => 'x', 'when' => $date]),
        );
    }

    #[Test]
    public function encodesUnsafeValueAsNull(): void
    {
        self::assertSame('null', AutomationValueJson::encode(new \stdClass()));
    }
}
