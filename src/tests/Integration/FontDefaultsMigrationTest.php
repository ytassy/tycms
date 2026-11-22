<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_11;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;

final class FontDefaultsMigrationTest extends TestCase
{
    public function testMigrationChangesOnlyHistoricalMainFontsAndCanBeRepeated(): void
    {
        $environment = TestEnvironment::load();
        $app = new Application($environment->configuration());
        $db = new Database($app);
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $db);

        // Une table temporaire propre à cette connexion masque la vraie table config.
        // Aucun reset ni modification des données de la base de test n'est nécessaire.
        $db->execute('CREATE TEMPORARY TABLE config (space_id INT, extension VARCHAR(50), params MEDIUMTEXT)');
        try {
            $fixtures = [
                [1, 'tpl_main', ['theme_font_family' => 'nunito', 'theme_heading_font_family' => 'bona_nova', 'logo' => '/logo.png']],
                [2, 'tpl_main', ['theme_font_family' => 'system', 'theme_heading_font_family' => 'google_custom']],
                [3, 'tpl_main', ['theme_font_family' => 'verdana', 'theme_heading_font_family' => 'courier_new']],
                [4, 'tpl_main', ['theme_primary' => '#123456']],
                [1, 'tpl_other', ['theme_font_family' => 'nunito', 'theme_heading_font_family' => 'bona_nova']],
            ];
            foreach ($fixtures as [$spaceId, $extension, $params]) {
                $db->execute('INSERT INTO config VALUES (%, %, %)', [$spaceId, $extension, json_encode($params, JSON_THROW_ON_ERROR)]);
            }
            $migration = new Mig_1_0_11($app);
            $migration->run();
            $rows = $db->select('SELECT * FROM config ORDER BY extension, space_id');
            $actual = array_map(static fn ($row) => json_decode($row->params, true, 512, JSON_THROW_ON_ERROR), $rows);
            self::assertSame(['theme_font_family' => 'arial', 'theme_heading_font_family' => 'georgia', 'logo' => '/logo.png'], $actual[0]);
            self::assertSame(['theme_font_family' => 'arial', 'theme_heading_font_family' => 'google_custom'], $actual[1]);
            self::assertSame($fixtures[2][2], $actual[2]);
            self::assertSame($fixtures[3][2], $actual[3]);
            self::assertSame($fixtures[4][2], $actual[4]);
            $migration->run();
            self::assertEquals($rows, $db->select('SELECT * FROM config ORDER BY extension, space_id'));
        } finally {
            $db->execute('DROP TEMPORARY TABLE config');
        }
    }
}
