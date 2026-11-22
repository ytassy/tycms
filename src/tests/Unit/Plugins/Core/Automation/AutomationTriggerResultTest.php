<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationRunOutcome;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerResult;

final class AutomationTriggerResultTest extends TestCase
{
    #[Test]
    public function emptyResultsAreNotSuccessful(): void
    {
        $result = new AutomationTriggerResult();

        self::assertFalse($result->hasRuns());
        self::assertFalse($result->isSuccessful());
    }

    #[Test]
    public function successAndSkippedRunsAreSuccessful(): void
    {
        $result = new AutomationTriggerResult([
            new AutomationRunOutcome(1, 10, 'success', ['ok' => true], null),
            new AutomationRunOutcome(null, 11, 'skipped', null, 'Déjà exécuté.'),
        ]);

        self::assertTrue($result->isSuccessful());
        self::assertFalse($result->hasFailures());
    }

    #[Test]
    public function anyFailedRunMakesTheTriggerFail(): void
    {
        $result = new AutomationTriggerResult([
            new AutomationRunOutcome(1, 10, 'success', null, null),
            new AutomationRunOutcome(2, 11, 'failed', null, 'Erreur.'),
        ]);

        self::assertTrue($result->hasFailures());
        self::assertFalse($result->isSuccessful());
    }
}
