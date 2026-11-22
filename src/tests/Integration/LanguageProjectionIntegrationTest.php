<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use stdClass;
use YTassy\Tycms\Application;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\ORM\DatabaseOrm;
use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Hidden;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\DB\ORM\BaseOrmObject;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageProjectionHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\Page\Renderer\PageRenderer;
use YTassy\Tycms\CoreExts\Core\Models\Page;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class LanguageProjectionIntegrationTest extends TestCase
{
    #[Test]
    public function localizedPageTextSelectsOneLanguageThenResolvesItsKey(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));

        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(uri: '/en/', host: TestFixtures::SITE_HOST));
        $projector = $application->getOrAddSharedExtensionsClass(LanguageProjectionHelper::class);

        $translations = (object) ['fr' => 'Bonjour', 'en' => 'LG_CORE_MENU_HOME'];

        self::assertSame('Bonjour', $projector->projectLocalizedTextValue($translations, 'fr', TestFixtures::SITE_SPACE_ID));
        self::assertSame('Home', $projector->projectLocalizedTextValue($translations, 'en', TestFixtures::SITE_SPACE_ID));
        self::assertSame('', $projector->projectLocalizedTextValue($translations, 'de', TestFixtures::SITE_SPACE_ID));
        self::assertSame('LG_CORE_MENU_HOME', $translations->en);

        $page = new Page($application);
        $page->id = 77;
        $page->space_id = TestFixtures::SITE_SPACE_ID;
        $page->type = 2;
        $page->raw_css = null;
        $page->data = PageDocument::normalize(PageFixtures::decode('{"items":[{"type":"core.row","columns":[{"items":['
            . '{"type":"core.heading","props":{"text":{"fr":"Bienvenue","en":"LG_CORE_MENU_HOME"}}}'
            . ']}]}]}', false, 512, JSON_THROW_ON_ERROR));

        $html = (new PageRenderer($application))->render($page, 'en')->getHtml();

        self::assertStringContainsString('<h2>Home</h2>', $html);
        self::assertSame('LG_CORE_MENU_HOME', $page->data->items[0]->zones->columns[0]->items[0]->props->text->en);
    }

    #[Test]
    public function ormKeepsRawValuesAndProjectionOnlyTranslatesEligibleFields(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));

        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(uri: '/en/', host: TestFixtures::SITE_HOST));

        $objectJson = json_encode([
            'label' => 'LG_CORE_MENU_HOME',
            'id' => 'LG_CORE_MENU_HOME',
            'url' => 'LG_CORE_MENU_HOME',
            'imageUrl' => 'LG_CORE_MENU_HOME',
            'modelId' => 'LG_CORE_MENU_HOME',
            'children' => [[
                'title' => 'LG_CORE_AUTH_LOGIN',
                'css_class' => 'LG_CORE_MENU_HOME',
                'cssClass' => 'LG_CORE_MENU_HOME',
                'details' => (object) ['description' => 'LG_CORE_AUTH_LOGOUT'],
            ]],
            'plain' => 'prefix LG_CORE_MENU_HOME',
        ], JSON_THROW_ON_ERROR);
        $arrayJson = json_encode([
            ['title' => 'LG_CORE_MENU_HOME', 'alias' => 'LG_CORE_MENU_HOME'],
            'LG_CORE_AUTH_LOGIN',
            null,
            42,
        ], JSON_THROW_ON_ERROR);

        // SELECT de littéraux : les types d'hydratation ORM sont réellement
        // exercés sans ajouter de table ou modifier les fixtures.
        $model = $application->getDatabase()->selectOrmOneOrNull(
            LanguageProjectionFixture::class,
            'SELECT 1 AS id, % AS title, % AS plain, % AS object_data, '
                . '% AS array_data, % AS other_data, % AS secret',
            [
                'LG_CORE_MENU_HOME',
                'LG_CORE_MENU_HOME',
                $objectJson,
                $arrayJson,
                $objectJson,
                'LG_CORE_AUTH_LOGIN',
            ],
        );

        self::assertInstanceOf(LanguageProjectionFixture::class, $model);
        self::assertSame('LG_CORE_MENU_HOME', $model->title);
        self::assertInstanceOf(stdClass::class, $model->objectData);
        self::assertIsArray($model->arrayData);
        self::assertSame('LG_CORE_MENU_HOME', $model->objectData->label);

        $projector = $application->getOrAddSharedExtensionsClass(LanguageProjectionHelper::class);
        $projected = $projector->projectOrm($model, 'en', TestFixtures::SITE_SPACE_ID);

        self::assertSame('Home', $projected['title']);
        self::assertSame('LG_CORE_MENU_HOME', $projected['plain']);
        self::assertSame('Home', $projected['objectData']->label);
        self::assertSame('LG_CORE_MENU_HOME', $projected['objectData']->id);
        self::assertSame('LG_CORE_MENU_HOME', $projected['objectData']->url);
        self::assertSame('LG_CORE_MENU_HOME', $projected['objectData']->imageUrl);
        self::assertSame('LG_CORE_MENU_HOME', $projected['objectData']->modelId);
        self::assertSame('Sign in', $projected['objectData']->children[0]->title);
        self::assertSame('LG_CORE_MENU_HOME', $projected['objectData']->children[0]->css_class);
        self::assertSame('LG_CORE_MENU_HOME', $projected['objectData']->children[0]->cssClass);
        self::assertSame('Sign out', $projected['objectData']->children[0]->details->description);
        self::assertSame('prefix LG_CORE_MENU_HOME', $projected['objectData']->plain);
        self::assertSame('Home', $projected['arrayData'][0]['title']);
        self::assertSame('LG_CORE_MENU_HOME', $projected['arrayData'][0]['alias']);
        self::assertSame('Sign in', $projected['arrayData'][1]);
        self::assertNull($projected['arrayData'][2]);
        self::assertSame(42, $projected['arrayData'][3]);
        self::assertSame('LG_CORE_MENU_HOME', $projected['otherData']->label);
        self::assertArrayNotHasKey('secret', $projected);

        // Une projection peut être jetée sans altérer l'objet réutilisable
        // par un formulaire ou un UPDATE ORM.
        self::assertSame('LG_CORE_MENU_HOME', $model->title);
        self::assertSame('LG_CORE_MENU_HOME', $model->objectData->label);
        self::assertSame('LG_CORE_MENU_HOME', $model->arrayData[0]['title']);
        self::assertNotSame($model->objectData, $projected['objectData']);
    }

    #[Test]
    public function deepOrCyclicJsonIsRejectedWithoutChangingTheSource(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));

        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(uri: '/en/', host: TestFixtures::SITE_HOST));
        $projector = $application->getOrAddSharedExtensionsClass(LanguageProjectionHelper::class);

        $deep = ['text' => 'LG_CORE_MENU_HOME'];
        for ($index = 0; $index < 34; $index++) {
            $deep = ['child' => $deep];
        }

        try {
            $projector->projectFieldValue($deep, FieldType::JSON_ARRAY_TEXT, 'en', 2);
            self::fail('Une profondeur excessive doit être refusée.');
        } catch (TechException) {
            $source = $deep;
            for ($index = 0; $index < 34; $index++) {
                $source = $source['child'];
            }

            self::assertSame('LG_CORE_MENU_HOME', $source['text']);
        }

        $cycle = new stdClass();
        $cycle->self = $cycle;
        $this->expectException(TechException::class);
        $projector->projectFieldValue($cycle, FieldType::JSON_TEXT, 'en', 2);
    }

    #[Test]
    public function ormUpdateStillReceivesTheRawValuesAfterProjection(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));

        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(uri: '/en/', host: TestFixtures::SITE_HOST));

        $model = new LanguageProjectionFixture($application);
        $model->id = 1;
        $model->title = 'LG_CORE_MENU_HOME';
        $model->objectData = (object) ['label' => 'LG_CORE_MENU_HOME'];
        $model->arrayData = ['LG_CORE_MENU_HOME'];

        $projector = $application->getOrAddSharedExtensionsClass(LanguageProjectionHelper::class);
        self::assertSame('Home', $projector->projectOrm($model, 'en', 2)['title']);

        // Le tableau projeté n'est jamais donné à l'ORM : la mise à jour reçoit
        // encore la référence et les JSON d'origine du modèle.
        $database = $this->createMock(Database::class);
        $database->expects(self::once())->method('execute')->with(
            self::stringContains('UPDATE `projection_fixture`'),
            self::callback(static function (array $values): bool {
                return $values[0] === 'LG_CORE_MENU_HOME'
                    && $values[1] instanceof stdClass
                    && $values[1]->label === 'LG_CORE_MENU_HOME'
                    && $values[2] === ['LG_CORE_MENU_HOME']
                    && $values[3] === 1;
            }),
        )->willReturn(1);

        self::assertSame(1, (new DatabaseOrm($application, $database))->update($model));
    }
}

#[Table('projection_fixture', 'id')]
final class LanguageProjectionFixture extends BaseOrmObject
{
    #[Field('id', FieldType::INT)]
    public int $id;

    #[Field('title', FieldType::TEXT)]
    public string $title;

    #[Field('plain', FieldType::STRING)]
    public string $plain;

    #[Field('object_data', FieldType::JSON_TEXT)]
    public mixed $objectData;

    #[Field('array_data', FieldType::JSON_ARRAY_TEXT)]
    public mixed $arrayData;

    #[Field('other_data', FieldType::JSON)]
    public mixed $otherData;

    #[Hidden]
    #[Field('secret', FieldType::TEXT)]
    public string $secret;
}
