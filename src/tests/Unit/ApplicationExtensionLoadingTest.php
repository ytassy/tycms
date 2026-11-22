<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Security\Plugin as SecurityPlugin;
use YTassy\Tycms\DB\Migrations\Migration;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ApplicationExtensionLoadingTest extends FrameworkTestCase
{
    #[DataProvider('coreExtensionSettings')]
    public function testOptionalExtensionsControlLoadingAndMigrations(array $settings, bool $dev, array $expected): void
    {
        $app = $this->createApplication();
        $app->getConf()->core_extensions_enabled = $settings;
        $app->getConf()->dev = $dev;

        // Le même chargement sert au site et aux migrations ; aucune connexion SQL ici.
        $migrated = [];
        $migration = $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['runMigrations'])
            ->getMock();
        $migration->expects(self::exactly(count($expected)))->method('runMigrations')
            ->willReturnCallback(function (string $identifier) use (&$migrated): void {
                $migrated[] = $identifier;
            });

        $loader = new ReflectionMethod(Application::class, 'loadCoreExtensions');
        $loader->invoke($app, $migration);

        self::assertSame($expected, array_keys($app->getExtensions()));
        self::assertSame($expected, $migrated);

        // Toute extension chargée par la release est identifiée comme Core.
        foreach ($expected as $identifier) {
            self::assertTrue($app->isCoreExtension($identifier), $identifier . ' doit être une extension Core.');
        }

        // Le parcours HTTP, sans migration, applique exactement la même sélection.
        $httpApp = $this->createApplication();
        $httpApp->getConf()->core_extensions_enabled = $settings;
        $httpApp->getConf()->dev = $dev;
        $loader->invoke($httpApp, null);
        self::assertSame($expected, array_keys($httpApp->getExtensions()));
    }

    public static function coreExtensionSettings(): iterable
    {
        yield 'défauts' => [[], false, ['plg_core', 'plg_security', 'plg_tools', 'plg_matrix', 'plg_automation', 'tpl_main']];
        yield 'Tools désactivée' => [['plg_tools' => false], false, ['plg_core', 'plg_security', 'plg_matrix', 'plg_automation', 'tpl_main']];
        yield 'Matrix désactivée' => [['plg_matrix' => false], false, ['plg_core', 'plg_security', 'plg_tools', 'plg_automation', 'tpl_main']];
        yield 'deux désactivées' => [['plg_tools' => false, 'plg_matrix' => false], false, ['plg_core', 'plg_security', 'plg_automation', 'tpl_main']];
        yield 'activation explicite' => [['plg_tools' => true, 'plg_matrix' => true], false, ['plg_core', 'plg_security', 'plg_tools', 'plg_matrix', 'plg_automation', 'tpl_main']];
        yield 'automatisation explicite' => [['plg_automation' => true], false, ['plg_core', 'plg_security', 'plg_tools', 'plg_matrix', 'plg_automation', 'tpl_main']];
        yield 'automatisation désactivée en DEV' => [['plg_automation' => false], true, ['plg_core', 'plg_security', 'plg_tools', 'plg_matrix', 'plg_ty_catalog', 'tpl_main', 'plg_test']];
        yield 'mode DEV conservé' => [['plg_tools' => false, 'plg_matrix' => false], true, ['plg_core', 'plg_security', 'plg_ty_catalog', 'plg_automation', 'tpl_main', 'plg_test']];
    }

    public function testMandatoryExtensionCannotBeDisabledBeforeAnyLoadingOrMigration(): void
    {
        $app = $this->createApplication();
        $app->getConf()->core_extensions_enabled = ['plg_security' => false];
        $migration = $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['runMigrations'])
            ->getMock();
        $migration->expects(self::never())->method('runMigrations');

        try {
            new ReflectionMethod(Application::class, 'loadCoreExtensions')->invoke($app, $migration);
            self::fail('Désactivation du plugin de sécurité acceptée.');
        } catch (\UnexpectedValueException) {
            self::assertSame([], $app->getExtensions());
        }
    }

    #[Test]
    public function duplicateCannotConstructOrMigrateAnExtensionOrReplaceSecurity(): void
    {
        // Charger Security normalement ; une seule migration doit être demandée.
        $app = $this->createApplication();
        $migration = $this->getMockBuilder(Migration::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['runMigrations'])
            ->getMock();
        $migration->expects(self::once())->method('runMigrations')
            ->with('plg_security', self::isInstanceOf(SecurityPlugin::class));
        $loader = new ReflectionMethod(Application::class, 'loadExtention');
        $security = $loader->invoke($app, 'plg_security', SecurityPlugin::class, $migration);

        // Le doublon doit échouer avant le constructeur et garder le plugin de sécurité.
        try {
            $loader->invoke($app, 'plg_security', DuplicateExtensionCandidate::class, $migration);
            self::fail('A duplicate extension identifier must be rejected.');
        } catch (TechException $exception) {
            self::assertStringContainsString('plg_security', $exception->getMessage());
            self::assertStringContainsString(SecurityPlugin::class, $exception->getMessage());
            self::assertStringContainsString(DuplicateExtensionCandidate::class, $exception->getMessage());
        }

        self::assertSame(['plg_security' => $security], $app->getExtensions());
    }
}

final class DuplicateExtensionCandidate extends SecurityPlugin
{
    public function __construct(Application $app)
    {
        throw new \LogicException('The duplicate extension constructor must never run.');
    }
}
