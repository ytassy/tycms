<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobBackoff;

final class JobBackoffTest extends TestCase
{
    public function testFirstRetryWaitsAboutTheBaseDelay(): void
    {
        $delay = JobBackoff::seconds(1);

        self::assertGreaterThanOrEqual(JobBackoff::BASE_SECONDS, $delay);
        self::assertLessThanOrEqual($this->withJitter(JobBackoff::BASE_SECONDS), $delay);
    }

    public function testDelayDoublesWithEachAttempt(): void
    {
        // La gigue reste bornée à 20 % : les paliers ne se chevauchent pas.
        self::assertGreaterThanOrEqual(60, JobBackoff::seconds(2));
        self::assertGreaterThanOrEqual(120, JobBackoff::seconds(3));
        self::assertGreaterThanOrEqual(480, JobBackoff::seconds(5));
    }

    public function testDelayIsCapped(): void
    {
        $delay = JobBackoff::seconds(30);

        self::assertGreaterThanOrEqual(JobBackoff::MAX_SECONDS, $delay);
        self::assertLessThanOrEqual($this->withJitter(JobBackoff::MAX_SECONDS), $delay);
    }

    public function testZeroAttemptsBehavesLikeTheFirstRetry(): void
    {
        self::assertGreaterThanOrEqual(JobBackoff::BASE_SECONDS, JobBackoff::seconds(0));
    }

    private function withJitter(int $delay): int
    {
        return (int) round($delay + $delay * (JobBackoff::JITTER_PERCENT / 100));
    }
}
