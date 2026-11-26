<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobTrafficTrigger;

final class JobTrafficTriggerTest extends TestCase
{
    public function testScriptCallsThePingRouteOnItsOwn(): void
    {
        $html = JobTrafficTrigger::render('nonce-de-test');

        self::assertStringContainsString('nonce="nonce-de-test"', $html);
        self::assertStringContainsString(JobTrafficTrigger::PING_PATH, $html);
        self::assertStringContainsString("method: 'POST'", $html);
        self::assertStringContainsString("credentials: 'omit'", $html);
        self::assertStringContainsString('keepalive: true', $html);
    }

    public function testScriptLimitsItsOwnFrequency(): void
    {
        $html = JobTrafficTrigger::render('nonce', 1234);

        self::assertStringContainsString(JobTrafficTrigger::STORAGE_KEY, $html);
        self::assertStringContainsString('1234', $html);
        self::assertStringContainsString('sessionStorage.setItem', $html);
    }

    public function testScriptNeverBlocksThePage(): void
    {
        $html = JobTrafficTrigger::render('nonce');

        // Aucun chargement bloquant, aucun await, et les erreurs sont avalées.
        self::assertStringNotContainsString('<script src', $html);
        self::assertStringContainsString('requestIdleCallback', $html);
        self::assertStringContainsString('.catch', $html);
    }

    public function testNonceIsEscaped(): void
    {
        $html = JobTrafficTrigger::render('nonce" onload="alert(1)');

        self::assertStringNotContainsString('onload="alert(1)"', $html);
        self::assertStringContainsString('&quot;', $html);
    }
}
