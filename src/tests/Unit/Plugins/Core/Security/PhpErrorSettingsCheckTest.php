<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Security;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\CoreExts\Core\Security\Checks\PhpErrorSettingsCheck;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/**
 * Contrat du contrôle des réglages PHP d'erreurs.
 *
 * Le contrôle lit la configuration appliquée par le bootstrap : afficher les
 * erreurs est dangereux, tandis qu'une trace serveur incomplète reste une
 * information à corriger.
 */
final class PhpErrorSettingsCheckTest extends FrameworkTestCase
{
    /** @return array<string, SecurityFinding> */
    private function findings(bool $displayErrors, bool $logErrors, int $level): array
    {
        $app = $this->createApplication();
        $configuration = $app->getConf();
        $configuration->display_errors = $displayErrors;
        $configuration->log_errors = $logErrors;
        $configuration->error_reporting_level = $level;

        $findings = [];

        foreach ((new PhpErrorSettingsCheck($app))->check() as $finding) {
            $findings[$finding->title] = $finding;
        }

        return $findings;
    }

    #[Test]
    public function safeProductionSettingsProduceOnlyOkFindings(): void
    {
        $findings = $this->findings(false, true, E_ALL & ~E_DEPRECATED);

        self::assertCount(2, $findings);
        self::assertSame(SecurityFinding::OK, $findings['Affichage des erreurs PHP']->status);
        self::assertSame(SecurityFinding::OK, $findings['Journalisation des erreurs PHP']->status);
    }

    #[Test]
    public function displayedErrorsAreReportedAsDanger(): void
    {
        $findings = $this->findings(true, true, E_ALL);
        $finding = $findings['Affichage des erreurs PHP'];

        self::assertSame(SecurityFinding::DANGER, $finding->status);
        self::assertStringContainsString('display_errors', (string) $finding->fix);
        self::assertSame('configuration.php — display_errors', $finding->location);
    }

    #[Test]
    public function missingServerLogIsReported(): void
    {
        $findings = $this->findings(false, false, E_ALL);
        $finding = $findings['Journalisation des erreurs PHP incomplète'];

        self::assertSame(SecurityFinding::INFO, $finding->status);
        self::assertStringContainsString('log_errors', $finding->description);
    }

    #[Test]
    public function aLevelWithoutErrorsOrWarningsIsReported(): void
    {
        $findings = $this->findings(false, true, 0);
        $finding = $findings['Journalisation des erreurs PHP incomplète'];

        self::assertSame(SecurityFinding::INFO, $finding->status);
        self::assertStringContainsString('error_reporting_level', $finding->description);
    }
}
