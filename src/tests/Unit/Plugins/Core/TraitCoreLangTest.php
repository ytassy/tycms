<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageCatalogRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Language\PublicLocaleHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceLanguageHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class TraitCoreLangTest extends FrameworkTestCase
{
    #[DataProvider('contexts')]
    public function testTranslationUsesThePresentationSpaceWithoutSelectingIt(
        ?int $selectedSpaceId,
        ?string $publicLocale,
        string $expected,
    ): void {
        $app = $this->createApplication();
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('getSpaceId')->willReturn($selectedSpaceId);
        $session->expects(self::never())->method('setSpaceId');
        (new ReflectionProperty(Application::class, 'session'))->setValue($app, $session);

        // Charger les deux Spaces sans base ; le vrai helper conserve son repli vers Root.
        $root = new Space($app);
        $root->id = 1;
        $root->parent_id = null;
        $site = new Space($app);
        $site->id = 2;
        $site->parent_id = 1;
        $spaces = (new ReflectionClass(SpaceHelper::class))->newInstanceWithoutConstructor();
        (new ReflectionProperty(BaseApplicationItem::class, '_app'))->setValue($spaces, $app);
        (new ReflectionProperty(SpaceHelper::class, 'spaces'))->setValue($spaces, [1 => $root, 2 => $site]);

        $locale = new PublicLocaleHelper($app);
        (new ReflectionProperty(PublicLocaleHelper::class, 'locale'))->setValue($locale, $publicLocale);
        $languages = $this->createMock(SpaceLanguageHelper::class);
        $languages->expects($publicLocale === null ? self::once() : self::never())
            ->method('getDefaultLocale')->with($selectedSpaceId ?? 1)->willReturn('fr');

        // Les surcharges distinguent le Space et la langue effectivement transmis au traducteur.
        $database = $this->createStub(Database::class);
        $database->method('select')->willReturnCallback(static function (string $query, ?array $values): array {
            if (!str_contains($query, 'language_overrides')) return [];

            return array_map(static fn (int $id): object => (object) [
                'space_id' => $id,
                'lang_key' => 'LG_CORE_EXEMPLE',
                'value' => $id . ':' . $values[0],
                'updated_at' => '2026-10-30 00:00:00',
                'updated_by' => null,
            ], [1, 2]);
        });
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $database);
        $catalogue = new LanguageCatalogRegistry($app);
        $catalogue->registerExtension('plg_core', [
            'fr' => APP_ROOT . '/releases/vdev/core_extensions/Core/Languages/fr.php',
        ]);
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [
            SpaceHelper::class => $spaces,
            SpaceLanguageHelper::class => $languages,
            PublicLocaleHelper::class => $locale,
            LanguageCatalogRegistry::class => $catalogue,
        ]);

        self::assertSame($expected, $this->consumer($app)->translate('LG_CORE_EXEMPLE'));
    }

    public static function contexts(): iterable
    {
        yield 'sans Space sélectionné' => [null, 'en', '1:en'];
        yield 'Space sélectionné' => [2, 'en', '2:en'];
        yield 'sans Space ni langue résolue' => [null, null, '1:fr'];
        yield 'langue par défaut du Space sélectionné' => [2, null, '2:fr'];
    }

    public function testEmptyCodesNeedNoSpaceOrLocale(): void
    {
        $consumer = $this->consumer($this->createApplication());

        foreach ([null, '', '   '] as $code) {
            self::assertSame('', $consumer->translate($code));
        }
    }

    private function consumer(Application $app): BaseHelper
    {
        return new class($app) extends BaseHelper {
            public function translate(?string $code): string
            {
                return $this->_($code);
            }
        };
    }
}
