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
 * Le contrôle lit l'état effectif de la requête : il doit signaler un mauvais
 * réglage de `configuration.php` et un hébergement qui refuse de l'appliquer.
 */
final class PhpErrorSettingsCheckTest extends FrameworkTestCase
{
    private string|false $previousDisplay = false;
    private string|false $previousLog = false;
    private int $previousLevel = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (!function_exists('ini_set') || !function_exists('error_reporting')) {
            self::markTestSkipped('Les réglages PHP ne sont pas observables dans cet environnement.');
        }

        // Conserver l'environnement PHP du test pour ne pas influencer les autres cas.
        $this->previousDisplay = ini_get('display_errors');
        $this->previousLog = ini_get('log_errors');
        $this->previousLevel = error_reporting();
    }

    protected function tearDown(): void
    {
        if (function_exists('ini_set')) {
            if ($this->previousDisplay !== false) {
                ini_set('display_errors', $this->previousDisplay);
            }

            if ($this->previousLog !== false) {
                ini_set('log_errors', $this->previousLog);
            }
        }

        if (function_exists('error_reporting')) {
            error_reporting($this->previousLevel);
        }

        parent::tearDown();
    }

    /**
     * @return array<string, SecurityFinding>
     */
    private function findings(
        bool $configuredDisplay,
        bool $configuredLog,
        int $configuredLevel,
        bool $effectiveDisplay,
        bool $effectiveLog,
        int $effectiveLevel,
    ): array {
        $app = $this->createApplication();
        $configuration = $app->getConf();
        $configuration->display_errors = $configuredDisplay;
        $configuration->log_errors = $configuredLog;
        $configuration->error_reporting_level = $configuredLevel;

        // Simuler ce que le bootstrap ou l'hébergement a réellement appliqué.
        ini_set('display_errors', $effectiveDisplay ? '1' : '0');
        ini_set('log_errors', $effectiveLog ? '1' : '0');
        error_reporting($effectiveLevel);

        $findings = [];

        foreach ((new PhpErrorSettingsCheck($app))->check() as $finding) {
            $findings[$finding->title] = $finding;
        }

        return $findings;
    }

    #[Test]
    public function safeProductionSettingsProduceOnlyOkFindings(): void
    {
        $findings = $this->findings(
            false,
            true,
            E_ALL & ~E_DEPRECATED,
            false,
            true,
            E_ALL & ~E_DEPRECATED,
        );

        self::assertCount(2, $findings);
        self::assertSame(SecurityFinding::OK, $findings['Affichage des erreurs PHP']->status);
        self::assertSame(SecurityFinding::OK, $findings['Journalisation des erreurs PHP']->status);
    }

    #[Test]
    public function displayedErrorsAreReportedAsDanger(): void
    {
        $findings = $this->findings(true, true, E_ALL, true, true, E_ALL);
        $finding = $findings['Affichage des erreurs PHP'];

        self::assertSame(SecurityFinding::DANGER, $finding->status);
        self::assertStringContainsString('Passez `display_errors` à false', (string) $finding->fix);
        self::assertSame('configuration.php — display_errors', $finding->location);
    }

    #[Test]
    public function displayedErrorsDespiteSafeConfigurationPointToTheHost(): void
    {
        $findings = $this->findings(
            false,
            true,
            E_ALL & ~E_DEPRECATED,
            true,
            true,
            E_ALL & ~E_DEPRECATED,
        );
        $finding = $findings['Affichage des erreurs PHP'];

        self::assertSame(SecurityFinding::DANGER, $finding->status);
        self::assertStringContainsString('L’hébergement impose l’affichage', (string) $finding->fix);
        self::assertSame('php.ini — display_errors', $finding->location);
    }

    #[Test]
    public function missingServerLogDespiteSafeConfigurationPointsToTheHost(): void
    {
        $findings = $this->findings(
            false,
            true,
            E_ALL & ~E_DEPRECATED,
            false,
            false,
            E_ALL & ~E_DEPRECATED,
        );
        $finding = $findings['Journalisation des erreurs PHP incomplète'];

        self::assertSame(SecurityFinding::INFO, $finding->status);
        self::assertStringContainsString('log_errors', $finding->description);
        self::assertStringContainsString('L’hébergement n’applique pas la configuration', (string) $finding->fix);
        self::assertStringStartsWith('php.ini', (string) $finding->location);
    }

    #[Test]
    public function aLevelWithoutErrorsOrWarningsIsReported(): void
    {
        $findings = $this->findings(
            false,
            true,
            E_ALL & ~E_DEPRECATED,
            false,
            true,
            0,
        );
        $finding = $findings['Journalisation des erreurs PHP incomplète'];

        self::assertSame(SecurityFinding::INFO, $finding->status);
        self::assertStringContainsString('error_reporting', $finding->description);
        self::assertStringContainsString('L’hébergement n’applique pas la configuration', (string) $finding->fix);
    }

    #[Test]
    public function aWrongLocalSettingKeepsTheConfigurationAsTheFix(): void
    {
        $findings = $this->findings(false, false, 0, false, false, 0);
        $finding = $findings['Journalisation des erreurs PHP incomplète'];

        self::assertSame(SecurityFinding::INFO, $finding->status);
        self::assertStringContainsString('Activez `log_errors`', (string) $finding->fix);
        self::assertStringContainsString('E_ALL & ~E_DEPRECATED', (string) $finding->fix);
        self::assertSame('configuration.php — log_errors, error_reporting_level', $finding->location);
    }
}