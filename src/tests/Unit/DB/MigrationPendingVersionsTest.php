<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\DB;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\Migrations\IMigration;
use YTassy\Tycms\DB\Migrations\Migration;

/**
 * L'écran de gestion s'appuie sur cette lecture seule : elle doit rester
 * indépendante de la casse stockée et de l'ordre déclaré.
 */
final class MigrationPendingVersionsTest extends TestCase
{
    #[Test]
    public function pendingVersionsKeepDeclarationOrderAndIgnoreStoredCase(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('selectOneOrNull')->willReturn((object) ['present' => 1]);
        $db->expects(self::once())
            ->method('select')
            ->with(self::stringContains('WHERE extension = %'), ['plg_automation'])
            ->willReturn([(object) ['version' => '1.0.0']]);

        self::assertSame(
            ['1.0.1', '1.0.2'],
            Migration::getPendingVersions($db, 'PLG_Automation', $this->migration(['1.0.0', '1.0.1', '1.0.2'])),
        );
    }

    #[Test]
    public function missingMigrationsTableConsidersEveryVersionPending(): void
    {
        $db = $this->createMock(Database::class);
        $db->method('selectOneOrNull')->willReturn(null);
        $db->expects(self::never())->method('select');

        self::assertSame(
            ['1.0.0', '1.0.1'],
            Migration::getPendingVersions($db, 'plg_automation', $this->migration(['1.0.0', '1.0.1'])),
        );
    }

    /** @param list<string> $versions */
    private function migration(array $versions): IMigration
    {
        return new class($versions) implements IMigration {
            /** @param list<string> $versions */
            public function __construct(private array $versions) {}

            public function getMigrations(): array
            {
                return $this->versions;
            }

            public function runMigration(string $version): array
            {
                return [];
            }
        };
    }
}
