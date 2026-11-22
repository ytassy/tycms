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
