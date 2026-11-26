<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit;

use ConfigurationBase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class ConfigurationTest extends TestCase
{
    public function testInheritedDefaultsPreserveLocalSettingsAfterRepeatedNormalization(): void
    {
        $configuration = new class extends ConfigurationBase {
            public $offline = true;
            public $secret = 'secret-local-factice';
            public array $update_trusted_public_keys = [];
        };

        $configuration->normalize();
        $configuration->normalize();

        self::assertTrue($configuration->offline);
        self::assertSame('secret-local-factice', $configuration->secret);
        self::assertSame([], $configuration->update_trusted_public_keys);
        self::assertSame(APP_ROOT . '/public/medias', $configuration->medias_root);
        self::assertSame(ConfigurationBase::CURRENT_CONFIG_VERSION, $configuration->configVersion);
    }

    #[DataProvider('unsupportedVersions')]
    public function testUnsupportedVersionIsRejectedByTheParentConstructor(int $version): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('Version de configuration non prise en charge.');

        new class($version) extends ConfigurationBase {
            public function __construct(int $version)
            {
                $this->configVersion = $version;

                parent::__construct();
            }
        };
    }

    public function testPhpErrorDefaultsAreSafeForProduction(): void
    {
        $configuration = new ConfigurationBase();

        // Une production non renseignée ne doit ni afficher les erreurs ni perdre leur trace.
        self::assertFalse($configuration->display_errors);
        self::assertTrue($configuration->log_errors);
        self::assertSame(E_ALL & ~E_DEPRECATED, $configuration->error_reporting_level);
    }

    public function testPhpErrorSettingsAreAppliedToTheProcess(): void
    {
        if (!function_exists('ini_set') || !function_exists('error_reporting')) {
            self::markTestSkipped('Les réglages PHP ne sont pas modifiables dans cet environnement.');
        }

        // Conserver l'environnement PHP du test pour ne pas influencer les autres cas.
        $previousDisplay = ini_get('display_errors');
        $previousLog = ini_get('log_errors');
        $previousLevel = error_reporting();

        $configuration = new class extends ConfigurationBase {
            public bool $display_errors = true;
            public bool $log_errors = false;
            public int $error_reporting_level = E_ALL;
        };

        try {
            $configuration->applyPhpErrorSettings();

            self::assertSame('1', ini_get('display_errors'));
            self::assertSame('0', ini_get('log_errors'));
            self::assertSame(E_ALL, error_reporting());
        } finally {
            if ($previousDisplay !== false) {
                ini_set('display_errors', $previousDisplay);
            }

            if ($previousLog !== false) {
                ini_set('log_errors', $previousLog);
            }

            error_reporting($previousLevel);
        }
    }

    public static function unsupportedVersions(): array
    {
        return [[0], [-1], [ConfigurationBase::CURRENT_CONFIG_VERSION + 1]];
    }

    public function testPartialExtensionSettingsPreserveExplicitFalseAndDefaultToTrue(): void
    {
        $configuration = new class extends ConfigurationBase {
            public array $core_extensions_enabled = ['plg_tools' => false];
        };

        $configuration->normalize();

        self::assertSame(
            ['plg_tools' => false, 'plg_matrix' => true, 'plg_automation' => true],
            $configuration->core_extensions_enabled,
        );
    }

    public function testAutomationIsEnabledByDefault(): void
    {
        $configuration = new ConfigurationBase();

        self::assertTrue($configuration->core_extensions_enabled['plg_automation']);
    }

    public function testAutomationExplicitFalseIsPreserved(): void
    {
        $configuration = new class extends ConfigurationBase {
            public array $core_extensions_enabled = ['plg_automation' => false];
        };

        $configuration->normalize();

        self::assertSame([
            'plg_automation' => false,
            'plg_tools' => true,
            'plg_matrix' => true,
        ], $configuration->core_extensions_enabled);
    }

    #[DataProvider('invalidExtensionSettings')]
    public function testOnlyOptionalCoreIdentifiersAndBooleansAreAccepted(array $settings): void
    {
        $configuration = new ConfigurationBase();
        $configuration->core_extensions_enabled = $settings;
        $this->expectException(UnexpectedValueException::class);
        $configuration->normalize();
    }

    public static function invalidExtensionSettings(): iterable
    {
        foreach (['plg_core', 'plg_security', 'tpl_main', 'plg_test', 'unknown'] as $identifier) {
            yield $identifier => [[$identifier => false]];
        }

        foreach (['false', 0, null, []] as $index => $value) {
            yield 'valeur non booléenne ' . $index => [['plg_tools' => $value]];
        }
    }
}
