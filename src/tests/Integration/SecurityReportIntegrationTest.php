<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityCheckRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

/**
 * Bilan de sécurité réel : les contrôles de Core tournent sur une application
 * complète, avec la configuration et la base de test.
 *
 * Ce test vérifie le contrat observable — groupes présents, constats triés,
 * aucune exception — sans figer les valeurs, qui dépendent de la configuration.
 */
final class SecurityReportIntegrationTest extends TestCase
{
    #[Test]
    public function theRegistryProducesAGroupedReportOnARealApplication(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));

        $app = new Application($environment->configuration());
        $app->initialiseOrThrow(registerRoutes: false);

        /** @var SecurityCheckRegistry $registry */
        $registry = $app->getOrAddSharedExtensionsClass(SecurityCheckRegistry::class);

        // Les contrôles de Core sont enregistrés pendant la préparation des
        // catalogues, donc disponibles sans servir de page.
        self::assertArrayHasKey('plg_core.media-root-only', $registry->all());
        self::assertArrayHasKey('plg_core.debug-mode', $registry->all());
        self::assertArrayHasKey('plg_core.cron-token', $registry->all());

        $report = $registry->report();

        self::assertNotSame([], $report['groups']);

        $statuses = [];

        foreach ($report['groups'] as $group) {
            self::assertArrayHasKey('label', $group);
            self::assertArrayHasKey('findings', $group);
            self::assertNotSame([], $group['findings']);

            foreach ($group['findings'] as $finding) {
                self::assertArrayHasKey('title', $finding);
                self::assertArrayHasKey('description', $finding);
                self::assertContains($finding['status'], [
                    SecurityFinding::CRITICAL,
                    SecurityFinding::DANGER,
                    SecurityFinding::INFO,
                    SecurityFinding::OK,
                ]);

                $statuses[] = $finding['status'];
            }
        }

        // Le bilan est exploitable : au moins un constat a été produit.
        self::assertNotSame([], $statuses);

        // Aucun contrôle n'a échoué : l'alerte générique serait présente.
        foreach ($report['groups'] as $group) {
            foreach ($group['findings'] as $finding) {
                self::assertNotSame(
                    'Contrôle indisponible',
                    $finding['title'],
                    'Un contrôle a levé une exception : ' . $group['label'],
                );
            }
        }
    }
}
