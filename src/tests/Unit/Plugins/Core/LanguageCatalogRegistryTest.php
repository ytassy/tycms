<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageCatalogRegistry;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class LanguageCatalogRegistryTest extends FrameworkTestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) unlink($file);

        parent::tearDown();
    }

    public function testBothFormatsProduceStringTranslationsAndSourceMetadata(): void
    {
        $registry = new LanguageCatalogRegistry($this->createApplication());
        $registry->registerExtension('test', [
            'fr' => $this->catalogue([
                'LG_TEST_PLAIN' => 'Bonjour',
                'LG_TEST_RECOMMENDED' => [
                    'value' => '',
                    'recommended' => true,
                    'description' => 'Avant le formulaire de connexion.',
                ],
                'LG_TEST_MINIMAL' => ['value' => 'Source'],
            ]),
            'en' => $this->catalogue(['LG_TEST_PLAIN' => 'Hello', 'LG_TEST_RECOMMENDED' => '']),
        ]);

        self::assertSame(['fr' => 'Bonjour', 'en' => 'Hello'], $registry->get('LG_TEST_PLAIN')['translations']);
        self::assertFalse($registry->get('LG_TEST_PLAIN')['recommended']);
        self::assertSame('', $registry->get('LG_TEST_PLAIN')['description']);
        self::assertSame('', $registry->get('LG_TEST_RECOMMENDED')['source']);
        self::assertSame(['fr' => '', 'en' => ''], $registry->get('LG_TEST_RECOMMENDED')['translations']);
        self::assertTrue($registry->get('LG_TEST_RECOMMENDED')['recommended']);
        self::assertSame('Avant le formulaire de connexion.', $registry->get('LG_TEST_RECOMMENDED')['description']);
        self::assertFalse($registry->get('LG_TEST_MINIMAL')['recommended']);
        self::assertSame('', $registry->get('LG_TEST_MINIMAL')['description']);
    }

    #[DataProvider('invalidDeclarations')]
    public function testMalformedDeclarationsAreRejectedWithoutRegisteringPartialEntries(string $locale, mixed $value): void
    {
        $registry = new LanguageCatalogRegistry($this->createApplication());
        $files = ['fr' => $this->catalogue(['LG_TEST_VALID' => 'Valide', 'LG_TEST_INVALID' => 'Source'])];
        $files[$locale] = $this->catalogue(['LG_TEST_VALID' => 'Valide', 'LG_TEST_INVALID' => $value]);

        try {
            $registry->registerExtension('test', $files);
            self::fail('Une déclaration invalide doit être refusée.');
        } catch (TechException) {
            self::assertSame([], $registry->all());
        }
    }

    public static function invalidDeclarations(): iterable
    {
        yield 'valeur manquante' => ['fr', ['recommended' => true]];
        yield 'valeur null' => ['fr', ['value' => null]];
        yield 'valeur tableau' => ['fr', ['value' => []]];
        yield 'recommandation numérique' => ['fr', ['value' => '', 'recommended' => 1]];
        yield 'recommandation null' => ['fr', ['value' => '', 'recommended' => null]];
        yield 'description tableau' => ['fr', ['value' => '', 'description' => []]];
        yield 'description null' => ['fr', ['value' => '', 'description' => null]];
        yield 'champ inconnu' => ['fr', ['value' => '', 'recommanded' => true]];
        yield 'métadonnées dans une traduction' => ['en', ['value' => '', 'description' => 'Help']];
        yield 'chaîne obligatoire' => ['fr', false];
    }

    public function testActualCustomizationPlaceholdersRemainEmptyInBothLanguages(): void
    {
        $registry = new LanguageCatalogRegistry($this->createApplication());
        $directory = APP_ROOT . '/releases/vdev/core_extensions/Core/Languages/';
        $registry->registerExtension('plg_core', ['fr' => $directory . 'fr.php', 'en' => $directory . 'en.php']);
        $recommended = array_filter($registry->all(), static fn (array $entry): bool => $entry['recommended']);

        self::assertNotEmpty($recommended);
        foreach ($recommended as $entry) {
            self::assertSame('', $entry['source']);
            self::assertSame(['fr' => '', 'en' => ''], $entry['translations']);
            self::assertNotSame('', $entry['description']);
        }
    }

    private function catalogue(array $values): string
    {
        $file = tempnam(sys_get_temp_dir(), 'ty-language-catalogue-');
        if ($file === false) throw new \RuntimeException('Impossible de créer le catalogue de test.');

        $this->files[] = $file;
        file_put_contents($file, '<?php return ' . var_export($values, true) . ';');

        return $file;
    }
}
