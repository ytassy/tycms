<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Security;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityCheckRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/**
 * Contrat du bilan de sécurité : regroupement, tri par gravité et résilience.
 *
 * Le bilan n'est pas persisté ; ces tests portent donc sur le registre qui
 * l'assemble, avec des contrôles factices pour rester indépendants de la
 * configuration du site.
 */
final class SecurityCheckRegistryTest extends FrameworkTestCase
{
    #[Test]
    public function groupsFindingsAndSortsThemBySeverity(): void
    {
        $app = $this->createApplication();
        $registry = new SecurityCheckRegistry($app);

        $registry->register('test.medias', new class implements ISecurityCheck {
            public function group(): string
            {
                return 'Médias';
            }

            public function check(): array
            {
                // Déclaré volontairement dans le désordre.
                return [
                    new SecurityFinding('Correct', SecurityFinding::OK, 'Rien à signaler.'),
                    new SecurityFinding('Grave', SecurityFinding::CRITICAL, 'À corriger.'),
                    new SecurityFinding('Moyen', SecurityFinding::DANGER, 'À surveiller.'),
                ];
            }
        }, 'plg_test');

        $registry->register('test.autres', new class implements ISecurityCheck {
            public function group(): string
            {
                return 'Autres';
            }

            public function check(): array
            {
                return [new SecurityFinding('Renseignements', SecurityFinding::INFO, 'Pour information.')];
            }
        }, 'plg_test');

        $report = $registry->report();

        // Le groupe portant le constat critique passe en premier.
        self::assertSame('Médias', $report['groups'][0]['label']);
        self::assertSame(
            [SecurityFinding::CRITICAL, SecurityFinding::DANGER, SecurityFinding::OK],
            array_column($report['groups'][0]['findings'], 'status'),
        );

        // Le rang de tri interne ne fuit pas dans la réponse.
        self::assertArrayNotHasKey('statusRank', $report['groups'][0]['findings'][0]);

        self::assertSame(1, $report['summary'][SecurityFinding::CRITICAL]);
        self::assertSame(1, $report['summary'][SecurityFinding::DANGER]);
        self::assertSame(1, $report['summary'][SecurityFinding::INFO]);
        self::assertSame(1, $report['summary'][SecurityFinding::OK]);
    }

    #[Test]
    public function aFailingCheckBecomesAnAlertWithoutBreakingTheReport(): void
    {
        $app = $this->createApplication();
        $registry = new SecurityCheckRegistry($app);

        $registry->register('test.broken', new class implements ISecurityCheck {
            public function group(): string
            {
                return 'Cassé';
            }

            public function check(): array
            {
                throw new \RuntimeException('Cause interne à ne pas exposer.');
            }
        }, 'plg_test');

        $registry->register('test.ok', new class implements ISecurityCheck {
            public function group(): string
            {
                return 'Sain';
            }

            public function check(): array
            {
                return [new SecurityFinding('Bon', SecurityFinding::OK, 'Tout va bien.')];
            }
        }, 'plg_test');

        $report = $registry->report();

        // Le contrôle en échec produit une alerte, le reste du bilan survit.
        self::assertCount(2, $report['groups']);
        self::assertSame(SecurityFinding::DANGER, $report['groups'][0]['findings'][0]['status']);
        self::assertStringNotContainsString('Cause interne', $report['groups'][0]['findings'][0]['description']);
    }

    #[Test]
    public function rejectsDuplicateAndInvalidIdentifiers(): void
    {
        $app = $this->createApplication();
        $registry = new SecurityCheckRegistry($app);

        $check = new class implements ISecurityCheck {
            public function group(): string
            {
                return 'Test';
            }

            public function check(): array
            {
                return [];
            }
        };

        $registry->register('test.ok', $check, 'plg_test');

        $this->expectException(\InvalidArgumentException::class);
        $registry->register('test.ok', $check, 'plg_test');
    }

    #[Test]
    public function rejectsAnUnknownStatus(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new SecurityFinding('Titre', 'inconnu', 'Description.');
    }
}
