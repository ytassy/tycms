<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_13;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;

final class SpaceMemberMigrationTest extends TestCase
{
    public function testMigrationAddsJsonBooleanAndPreservesExistingChoicesAndOtherParameters(): void
    {
        $app = new Application(TestEnvironment::load()->configuration());
        $db = new Database($app);
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        // Masquer la table réelle sur cette seule connexion pour éprouver la migration complète.
        $db->execute('CREATE TEMPORARY TABLE spaces (
            id INT PRIMARY KEY, medias_dir VARCHAR(50), params LONGTEXT
        )');
        try {
            $fixtures = [
                1 => ['registration_enabled' => false, 'custom' => ['value' => 42]],
                2 => ['registration_auto_member_role' => false, 'custom' => 'conservé'],
                3 => ['registration_auto_member_role' => true],
            ];
            foreach ($fixtures as $id => $params) {
                $db->execute('INSERT INTO spaces VALUES (%, %, %)', [$id, 'space-' . $id, json_encode($params, JSON_THROW_ON_ERROR)]);
            }

            new Mig_1_0_13($app)->run();

            foreach ($db->select('SELECT id, params FROM spaces ORDER BY id') as $row) {
                $expected = $fixtures[$row->id];
                $expected['registration_auto_member_role'] = (int) $row->id !== 2;
                self::assertSame($expected, json_decode($row->params, true, flags: JSON_THROW_ON_ERROR));
            }
        } finally {
            $db->execute('DROP TEMPORARY TABLE spaces');
        }
    }
}
