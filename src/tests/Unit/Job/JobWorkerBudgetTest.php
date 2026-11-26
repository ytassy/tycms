<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobWorker;

final class JobWorkerBudgetTest extends TestCase
{
    #[DataProvider('budgets')]
    public function testBudgetIsClampedByTheServerLimit(
        int $policy,
        int $server,
        int $expected,
    ): void {
        self::assertSame($expected, JobWorker::clampBudget($policy, $server));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function budgets(): iterable
    {
        // Limite illimitée (0) : la politique de la porte s'applique seule.
        yield 'limite illimitée' => [JobWorker::CRON_BUDGET_SECONDS, 0, JobWorker::CRON_BUDGET_SECONDS];

        // Une limite de 30 s laisse 20 s de travail et 10 s de marge.
        yield 'limite serveur prioritaire' => [JobWorker::CRON_BUDGET_SECONDS, 30, 20];

        // La politique reste prioritaire quand elle est plus courte.
        yield 'politique plus courte' => [JobWorker::TRAFFIC_BUDGET_SECONDS, 30, 5];

        // Limite serveur serrée : le plancher protège le démarrage d'un job.
        yield 'limite serrée' => [JobWorker::CRON_BUDGET_SECONDS, 12, JobWorker::MIN_BUDGET_SECONDS];

        // Limite inférieure à la marge : le plancher s'applique aussi.
        yield 'limite sous la marge' => [JobWorker::CRON_BUDGET_SECONDS, 5, JobWorker::MIN_BUDGET_SECONDS];
    }

    public function testPolicyIsUsedAsIsWhenServerIsUnlimited(): void
    {
        self::assertSame(
            JobWorker::ADMIN_BUDGET_SECONDS,
            JobWorker::clampBudget(JobWorker::ADMIN_BUDGET_SECONDS, 0),
        );
    }
}
