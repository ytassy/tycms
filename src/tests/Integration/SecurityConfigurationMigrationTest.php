<?php

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\CoreExts\Core\Helper\Admin\ExtensionsHelper;
use YTassy\Tycms\CoreExts\Security\Plugin;
use YTassy\Tycms\CoreExts\Security\Configuration;
use YTassy\Tycms\CoreExts\Security\Migration\Mig_1_0_2;
use YTassy\Tycms\CoreExts\Security\Migration\Mig_1_0_1;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;

final class SecurityConfigurationMigrationTest extends TestCase
{
    public function testMigrationPreservesChoicesRemovesEnabledAndInitializesNewConfigurations(): void
    {
        $app = new Application(TestEnvironment::load()->configuration());
        $db = new Database($app);
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $db);

        // Tables temporaires de cette seule connexion, sur la base isolée contrôlée par TestEnvironment.
        $db->execute('CREATE TEMPORARY TABLE config (space_id INT, extension VARCHAR(50), params LONGTEXT, PRIMARY KEY (space_id, extension))');
        $db->execute('CREATE TEMPORARY TABLE spaces (id INT PRIMARY KEY)');
        try {
            $fixtures = [
                1 => ['enabled' => false],
                2 => ['enabled' => true, 'iframe_mode' => 'allowlist', 'iframe_origins' => 'https://docs.google.com',
                    'waf_use_root' => true, 'waf_mode' => 'observe', 'waf_skip_root' => false, 'waf_message' => 'Conservé.'],
            ];
            foreach ($fixtures as $id => $params) {
                $db->execute('INSERT INTO config VALUES (%, %, %)', [$id, 'plg_security', (object) $params]);
            }
            $db->execute('INSERT INTO config VALUES (1, %, %)', ['other_plugin', (object) ['enabled' => false]]);
            $db->execute('INSERT INTO spaces VALUES (1), (2), (3)');
            (new Mig_1_0_1($app))->run();
            (new Mig_1_0_2($app))->run();
            // Le rejeu est sans effet sur les choix ; les nouvelles configurations utilisent le formulaire courant.
            (new Mig_1_0_1($app))->run();
            (new Mig_1_0_2($app))->run();
            $plugin = new Plugin($app);
            (new ReflectionProperty(Application::class, 'extensions'))->setValue($app, ['plg_security' => $plugin]);
            $helper = new ExtensionsHelper($app);
            $helper->setFormsByExtension(['plg_security' => $plugin->coreRegisterConfigForm()]);
            $helper->checkAndCreateExtensionConfig();

            foreach ($db->select('SELECT space_id, params FROM config WHERE extension = % ORDER BY space_id', ['plg_security']) as $row) {
                $expected = array_replace(Configuration::defaults(), $fixtures[$row->space_id] ?? []);
                unset($expected['enabled']);
                $actual = json_decode($row->params, true, flags: JSON_THROW_ON_ERROR);
                self::assertSame(Configuration::normalize($expected), Configuration::normalize($actual));
                self::assertIsBool($actual['waf_use_root']);
                self::assertIsBool($actual['waf_skip_root']);
            }
            $other = $db->selectOneOrNull('SELECT params FROM config WHERE extension = %', ['other_plugin']);
            self::assertEquals((object) ['enabled' => false], json_decode($other->params, flags: JSON_THROW_ON_ERROR));
        } finally {
            $db->execute('DROP TEMPORARY TABLE config');
            $db->execute('DROP TEMPORARY TABLE spaces');
        }
    }
}
