<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageTranslator;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class LanguageTranslationIntegrationTest extends TestCase
{
    private const KEY = 'LG_CORE_MENU_HOME';
    private const CUSTOM_KEY = 'LG_TEST_CUSTOM_HELLO';
    private const RIA_KEY = 'LG_RIA_TEST_HTML';

    private TestEnvironment $environment;
    private TestDatabase $database;
    private string $sessionKey;
    private string $csrfToken;

    protected function setUp(): void
    {
        // Utiliser la base dédiée et créer une session administrateur propre au test.
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->sessionKey = bin2hex(random_bytes(24));
        $this->csrfToken = bin2hex(random_bytes(32));

        $data = serialize(['user_id' => 1, 'current_space_id' => 2, 'csrf_token' => $this->csrfToken]);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, 1, ?)');
        $statement->bind_param('ss', $this->sessionKey, $data);
        $statement->execute();
    }

    protected function tearDown(): void
    {
        // Nettoyer les surcharges et la session sans réinitialiser la base entière.
        $connection = $this->database->connection();
        $connection->query("DELETE FROM language_overrides WHERE lang_key IN ('LG_CORE_MENU_HOME', 'LG_TEST_CUSTOM_HELLO', 'LG_RIA_TEST_HTML', 'LG_CORE_EXEMPLE')");
        $connection->query("DELETE FROM language_custom_keys WHERE lang_key IN ('LG_TEST_CUSTOM_HELLO', 'LG_RIA_TEST_HTML')");
        $statement = $connection->prepare('DELETE FROM sessions WHERE `key` = ?');
        $statement->bind_param('s', $this->sessionKey);
        $statement->execute();
    }

    #[Test]
    public function extensionCataloguesAndSpaceInheritanceResolveInOrder(): void
    {
        // Les catalogues servent de repli tant qu'aucune surcharge n'existe.
        $application = $this->application();
        $translator = $application->getOrAddSharedExtensionsClass(LanguageTranslator::class);
        self::assertSame('Accueil', $translator->resolve(self::KEY, 'fr', 2));
        self::assertSame('Home', $translator->resolve(self::KEY, 'en', 2));
        self::assertSame('LG_CORE_MENU_HOME extra', $translator->resolve('LG_CORE_MENU_HOME extra', 'en', 2));

        // La racine transmet sa surcharge au Space site.
        $this->save(1, 'en', self::KEY, 'Root home');
        self::assertSame('Root home', $this->entry(2, 'en', self::KEY)['effective']);
        self::assertSame(1, $this->entry(2, 'en', self::KEY)['inheritedSpaceId']);

        // Une surcharge plus proche remplace celle de la racine.
        $this->save(2, 'en', self::KEY, 'Site home');
        self::assertSame('Site home', $this->entry(2, 'en', self::KEY)['effective']);
        self::assertSame('space', $this->entry(2, 'en', self::KEY)['origin']);
        self::assertNotNull($this->entry(2, 'en', self::KEY)['localUpdatedAt']);
        self::assertSame('Root home', $this->entry(1, 'en', self::KEY)['effective']);

        // Une valeur vide masque le contenu ; sa suppression restaure l'héritage.
        $this->save(2, 'en', self::KEY, '');
        self::assertSame('', $this->entry(2, 'en', self::KEY)['effective']);
        $deleted = $this->api('overrides/delete', RequestMethod::POST, 2,
            ['locale' => 'en', 'key' => self::KEY]);
        self::assertTrue($deleted['deleted']);
        self::assertSame('Root home', $this->entry(2, 'en', self::KEY)['effective']);

        // L'API unitaire purifie le HTML avant de l'enregistrer.
        $this->save(2, 'en', self::KEY, '<script>alert(1)</script><strong>Safe</strong>');
        self::assertSame('<strong>Safe</strong>', $this->entry(2, 'en', self::KEY)['effective']);
    }

    #[Test]
    public function vueDictionaryMatchesPhpResolutionForEachSpaceAndLocale(): void
    {
        // Le catalogue anglais et la source française servent de valeurs de base.
        $translator = $this->application()->getOrAddSharedExtensionsClass(LanguageTranslator::class);
        self::assertSame('Accueil', $translator->dictionaryForSpace(2, 'fr')[self::KEY]);
        self::assertSame('Home', $translator->dictionaryForSpace(2, 'en')[self::KEY]);
        self::assertSame('Accueil', $translator->dictionaryForSpace(2, 'de')[self::KEY]);
        self::assertSame('All rights reserved',
            $translator->dictionaryForSpace(2, 'en')['LG_MAIN_FOOTER_RIGHTS']);

        // Une clé déclarée à la racine entre aussi dans le dictionnaire du site.
        $created = $this->api('custom/create', RequestMethod::POST, 1,
            ['key' => self::CUSTOM_KEY, 'sourceValue' => 'Root custom']);
        self::assertTrue($created['created']);
        $translator = $this->application()->getOrAddSharedExtensionsClass(LanguageTranslator::class);
        self::assertSame('Root custom', $translator->dictionaryForSpace(2, 'en')[self::CUSTOM_KEY]);

        // La surcharge de la racine se transmet au Space site, puis la valeur
        // locale prend le pas, même lorsqu'elle est une chaîne vide.
        $this->save(1, 'en', self::KEY, 'Root home');
        $translator = $this->application()->getOrAddSharedExtensionsClass(LanguageTranslator::class);
        self::assertSame('Root home', $translator->dictionaryForSpace(2, 'en')[self::KEY]);

        $this->save(2, 'en', self::KEY, '');
        $translator = $this->application()->getOrAddSharedExtensionsClass(LanguageTranslator::class);
        self::assertSame('', $translator->dictionaryForSpace(2, 'en')[self::KEY]);
        self::assertSame('', $translator->resolve(self::KEY, 'en', 2));
        self::assertSame('Root home', $translator->dictionaryForSpace(1, 'en')[self::KEY]);
        self::assertSame('Accueil', $translator->dictionaryForSpace(2, 'fr')[self::KEY]);
    }

    #[Test]
    public function mainTemplateEmbedsSafeJsonForTheCurrentSpaceAndLanguage(): void
    {
        // Déclarer une clé RIA uniquement pour le test : aucune clé de production
        // ne porte encore ce préfixe.
        $created = $this->api('custom/create', RequestMethod::POST, 2,
            ['key' => self::RIA_KEY, 'sourceValue' => 'Test RIA']);
        self::assertTrue($created['created']);

        // Écrire directement une valeur hostile pour éprouver la frontière
        // JSON, même si l'API d'édition purifie normalement ce HTML avant stockage.
        $payload = '</script><script>alert(1)</script>';
        $statement = $this->database->connection()->prepare(<<<SQL
            INSERT INTO language_overrides (space_id, locale, lang_key, value, updated_by)
            VALUES (2, 'en', ?, ?, 1)
            SQL);
        $key = self::RIA_KEY;
        $statement->bind_param('ss', $key, $payload);
        $statement->execute();

        $request = new Request(
            uri: '/en/',
            host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $this->sessionKey],
        );
        $html = $this->application($request)->executeAndGetResponse()->body;

        self::assertSame(1, preg_match(
            '~<script id="app-bootstrap" type="application/json">\s*(.*?)</script>~s',
            $html,
            $match,
        ));

        // La fermeture de script ne doit exister qu'après le JSON, jamais
        // dans une traduction intégrée au bootstrap.
        self::assertStringNotContainsString('</script>', $match[1]);
        self::assertStringContainsString('\\u003C/script\\u003E', $match[1]);

        $bootstrap = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $bootstrap['space']['id']);
        // Le bootstrap garde les identifiants IANA comme clés et expose leur décalage.
        self::assertSame('UTC+00:00 — UTC', $bootstrap['timezones']['UTC']);
        self::assertMatchesRegularExpression(
            '~^UTC[+-][0-9]{2}:[0-9]{2} — Europe/Paris$~u',
            $bootstrap['timezones']['Europe/Paris'],
        );
        $offsets = array_map(static function (string $label): int {
            preg_match('~^UTC([+-])([0-9]{2}):([0-9]{2}) — ~u', $label, $parts);

            return ($parts[1] === '-' ? -1 : 1) * ((int) $parts[2] * 60 + (int) $parts[3]);
        }, array_values($bootstrap['timezones']));
        $sortedOffsets = $offsets;
        sort($sortedOffsets);
        self::assertSame($sortedOffsets, $offsets);

        $identifiersByOffset = [];
        foreach (array_keys($bootstrap['timezones']) as $index => $identifier) {
            $identifiersByOffset[$offsets[$index]][] = $identifier;
        }
        $alphabeticalGroups = array_map(static function (array $identifiers): array {
            sort($identifiers, SORT_STRING);

            return $identifiers;
        }, $identifiersByOffset);
        self::assertSame($alphabeticalGroups, $identifiersByOffset);

        self::assertSame('en', $bootstrap['language']['locale']);
        self::assertSame('fr', $bootstrap['language']['defaultLocale']);
        self::assertSame(['fr', 'en'], $bootstrap['language']['availableLocales']);
        self::assertSame($payload, $bootstrap['language']['messages'][self::RIA_KEY]);
        self::assertArrayNotHasKey(self::KEY, $bootstrap['language']['messages']);
        self::assertArrayNotHasKey('LG_MAIN_FOOTER_RIGHTS', $bootstrap['language']['messages']);
    }

    #[Test]
    public function customKeyMustBeDeclaredBeforeOverrideAndIsInherited(): void
    {
        // Rejeter les noms hors convention et les surcharges sans déclaration.
        $missingPrefix = $this->api('custom/create', RequestMethod::POST, 2,
            ['key' => 'SITE_MENU_CONTACT', 'sourceValue' => 'Contact']);
        self::assertTrue($missingPrefix['is_message']);
        $lowercase = $this->api('custom/create', RequestMethod::POST, 2,
            ['key' => 'lg_site_menu_contact', 'sourceValue' => 'Contact']);
        self::assertTrue($lowercase['is_message']);

        $unknown = $this->api('overrides/save', RequestMethod::POST, 2,
            ['locale' => 'en', 'key' => self::CUSTOM_KEY, 'value' => 'Hello']);
        self::assertTrue($unknown['is_message']);
        self::assertStringContainsString('pas déclarée', $unknown['message']);

        // Une clé déclarée à la racine est visible chez ses descendants.
        $created = $this->api('custom/create', RequestMethod::POST, 1,
            ['key' => self::CUSTOM_KEY, 'sourceValue' => '<strong>Bonjour</strong><script>alert(1)</script>']);
        self::assertTrue($created['created']);
        $duplicate = $this->api('custom/create', RequestMethod::POST, 2,
            ['key' => self::CUSTOM_KEY, 'sourceValue' => 'Autre texte']);
        self::assertTrue($duplicate['is_message']);
        $catalogueCollision = $this->api('custom/create', RequestMethod::POST, 2,
            ['key' => self::KEY, 'sourceValue' => 'Autre texte']);
        self::assertTrue($catalogueCollision['is_message']);
        self::assertSame('<strong>Bonjour</strong>', $this->entry(2, 'en', self::CUSTOM_KEY)['effective']);
        self::assertSame(1, $this->entry(2, 'en', self::CUSTOM_KEY)['declaredSpaceId']);

        $this->save(2, 'en', self::CUSTOM_KEY, 'Hello');
        self::assertSame('Hello', $this->entry(2, 'en', self::CUSTOM_KEY)['effective']);
        self::assertSame('<strong>Bonjour</strong>', $this->entry(1, 'fr', self::CUSTOM_KEY)['effective']);
    }

    #[Test]
    public function recommendedMetadataRemainsVisibleWhenThePlaceholderIsOverridden(): void
    {
        $key = 'LG_CORE_EXEMPLE';
        $matrix = $this->api('matrix', RequestMethod::GET, 2, []);
        $rows = array_column($matrix['entries'], null, 'key');
        self::assertFalse($rows[self::KEY]['recommended']);
        self::assertSame('', $rows[self::KEY]['description']);
        self::assertTrue($rows[$key]['recommended']);
        self::assertNotSame('', $rows[$key]['description']);
        self::assertSame('', $rows[$key]['source']);
        self::assertSame('', $rows[$key]['languages']['fr']['effective']);
        self::assertSame('', $rows[$key]['languages']['en']['effective']);

        // La recommandation documente la clé, indépendamment de sa valeur locale.
        $this->save(2, 'fr', $key, '<p>Bienvenue sur notre site.</p>');
        $updated = $this->entry(2, 'fr', $key);
        self::assertSame('<p>Bienvenue sur notre site.</p>', $updated['effective']);
        self::assertTrue($updated['recommended']);
        self::assertSame($rows[$key]['description'], $updated['description']);

        $this->save(2, 'fr', $key, '');
        self::assertSame('', $this->entry(2, 'fr', $key)['effective']);
        $matrix = $this->api('matrix', RequestMethod::GET, 2, []);
        $rows = array_column($matrix['entries'], null, 'key');
        self::assertTrue($rows[$key]['recommended']);
    }

    #[Test]
    public function matrixShowsEveryLanguageAndSavesChangedLanguagesTogether(): void
    {
        // Le tableau doit contenir une colonne par langue active du Space.
        $matrix = $this->api('matrix', RequestMethod::GET, 2, []);
        self::assertSame(['fr', 'en'], $matrix['locales']);
        $row = current(array_filter($matrix['entries'], static fn (array $entry) => $entry['key'] === self::KEY));
        self::assertSame('Accueil', $row['languages']['fr']['effective']);
        self::assertSame('Home', $row['languages']['en']['effective']);

        // Les deux colonnes sont enregistrées dans le même appel.
        $saved = $this->api('overrides/save-many', RequestMethod::POST, 2, [
            'key' => self::KEY,
            'changes' => [
                ['locale' => 'fr', 'action' => 'save', 'value' => 'Bienvenue'],
                ['locale' => 'en', 'action' => 'save', 'value' => 'Welcome'],
            ],
        ]);
        self::assertTrue($saved['saved']);
        self::assertSame('Bienvenue', $this->entry(2, 'fr', self::KEY)['effective']);
        self::assertSame('Welcome', $this->entry(2, 'en', self::KEY)['effective']);

        // Une langue invalide doit empêcher toutes les écritures du lot.
        $invalid = $this->api('overrides/save-many', RequestMethod::POST, 2, [
            'key' => self::KEY,
            'changes' => [
                ['locale' => 'fr', 'action' => 'save', 'value' => 'À ne pas écrire'],
                ['locale' => 'de', 'action' => 'save', 'value' => 'Nein'],
            ],
        ]);
        self::assertTrue($invalid['is_message']);
        self::assertSame('Bienvenue', $this->entry(2, 'fr', self::KEY)['effective']);

        // Le lot accepte aussi une surcharge vide et le HTML purifié.
        $blank = $this->api('overrides/save-many', RequestMethod::POST, 2, [
            'key' => self::KEY,
            'changes' => [['locale' => 'en', 'action' => 'save', 'value' => '']],
        ]);
        self::assertTrue($blank['saved']);
        self::assertSame('', $this->entry(2, 'en', self::KEY)['effective']);

        $html = $this->api('overrides/save-many', RequestMethod::POST, 2, [
            'key' => self::KEY,
            'changes' => [['locale' => 'en', 'action' => 'save',
                'value' => '<strong>Welcome</strong><script>alert(1)</script>']],
        ]);
        self::assertTrue($html['saved']);
        self::assertSame('<strong>Welcome</strong>', $this->entry(2, 'en', self::KEY)['effective']);

        // Retirer la surcharge restaure la traduction du catalogue.
        $deleted = $this->api('overrides/save-many', RequestMethod::POST, 2, [
            'key' => self::KEY,
            'changes' => [['locale' => 'en', 'action' => 'delete']],
        ]);
        self::assertTrue($deleted['saved']);
        self::assertSame('Home', $this->entry(2, 'en', self::KEY)['effective']);
    }

    #[Test]
    public function inactiveLanguageAndGuestCannotEdit(): void
    {
        $inactive = $this->api('overrides/save', RequestMethod::POST, 1,
            ['locale' => 'de', 'key' => self::KEY, 'value' => 'No']);
        self::assertTrue($inactive['is_message']);
        self::assertStringContainsString('n’est active', $inactive['message']);

        $request = new Request(uri: '/administrator/api/languages/spaces', host: TestFixtures::SITE_HOST);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);
        $response = json_decode($application->executeAndGetResponse()->body ?? '', true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $response['spaces']);
    }

    #[Test]
    public function permissionGrantedOnSiteDoesNotAllowEditingRoot(): void
    {
        // Ce compte ne reçoit le droit que dans le Space site.
        $connection = $this->database->connection();
        $connection->query("INSERT INTO users (id, space_id, first_name, last_name, email, params) VALUES (3000, 2, 'Test', 'Langue', 'langue-scope@integration.test', '{}')");
        $connection->query("INSERT INTO user_groups (id, space_id, name) VALUES (3000, 2, 'Langue scope test')");
        $connection->query('INSERT INTO user_groups_map (user_id, group_id) VALUES (3000, 3000)');
        $connection->query('INSERT INTO acl_map (role_id, group_id, space_id) VALUES (2, 3000, 2)');

        $key = bin2hex(random_bytes(24));
        $csrf = bin2hex(random_bytes(32));
        $data = serialize(['user_id' => 3000, 'current_space_id' => 2, 'csrf_token' => $csrf]);
        $statement = $connection->prepare('INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, 3000, ?)');
        $statement->bind_param('ss', $key, $data);
        $statement->execute();

        try {
            $spaces = $this->scopedApi('spaces', RequestMethod::GET, null, [], $key, $csrf);
            self::assertSame([2], array_column($spaces['spaces'], 'id'));

            $denied = $this->scopedApi('overrides/save', RequestMethod::POST, 1,
                ['locale' => 'fr', 'key' => self::KEY, 'value' => 'Interdit'], $key, $csrf);
            self::assertTrue($denied['is_message']);
            self::assertStringContainsString('droits', $denied['message']);

            $bulkDenied = $this->scopedApi('overrides/save-many', RequestMethod::POST, 1,
                ['key' => self::KEY, 'changes' => [['locale' => 'fr', 'action' => 'save', 'value' => 'Interdit']]], $key, $csrf);
            self::assertTrue($bulkDenied['is_message']);

            $allowed = $this->scopedApi('overrides/save', RequestMethod::POST, 2,
                ['locale' => 'fr', 'key' => self::KEY, 'value' => 'Permis'], $key, $csrf);
            self::assertTrue($allowed['saved']);
        } finally {
            $statement = $connection->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $key);
            $statement->execute();
            $connection->query('DELETE FROM acl_map WHERE group_id = 3000');
            $connection->query('DELETE FROM user_groups_map WHERE user_id = 3000');
            $connection->query('DELETE FROM user_groups WHERE id = 3000');
            $connection->query('DELETE FROM users WHERE id = 3000');
        }
    }

    #[Test]
    public function pageEditorCanSelectEffectiveKeysWithoutTranslationRights(): void
    {
        // Le rôle 4 édite les pages, mais ne possède pas LANG_OVERRIDE_CRUD.
        $connection = $this->database->connection();
        $connection->query("INSERT INTO users (id, space_id, first_name, last_name, email, params) VALUES (3001, 2, 'Test', 'Pages', 'pages-langue@integration.test', '{}')");
        $connection->query("INSERT INTO user_groups (id, space_id, name) VALUES (3001, 2, 'Pages langue test')");
        $connection->query('INSERT INTO user_groups_map (user_id, group_id) VALUES (3001, 3001)');
        $connection->query('INSERT INTO acl_map (role_id, group_id, space_id) VALUES (4, 3001, 2)');

        $sessionKey = bin2hex(random_bytes(24));
        $csrfToken = bin2hex(random_bytes(32));
        $data = serialize(['user_id' => 3001, 'current_space_id' => 2, 'csrf_token' => $csrfToken]);
        $statement = $connection->prepare('INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, 3001, ?)');
        $statement->bind_param('ss', $sessionKey, $data);
        $statement->execute();

        try {
            // La liste doit projeter la surcharge héritée pour la langue du champ.
            $this->save(1, 'en', self::KEY, '<strong>Welcome</strong>');
            $response = $this->scopedApi('selectable-keys', RequestMethod::GET, 2,
                ['locale' => 'en'], $sessionKey, $csrfToken);
            $entries = array_column($response['entries'], 'value', 'key');

            self::assertSame('<strong>Welcome</strong>', $entries[self::KEY]);
            self::assertArrayHasKey('LG_MAIN_FOOTER_RIGHTS', $entries);

            // Une langue inactive ou un Space sans droit ne révèle aucune liste.
            $inactive = $this->scopedApi('selectable-keys', RequestMethod::GET, 2,
                ['locale' => 'de'], $sessionKey, $csrfToken);
            self::assertTrue($inactive['is_message']);

            $forbidden = $this->scopedApi('selectable-keys', RequestMethod::GET, 1,
                ['locale' => 'fr'], $sessionKey, $csrfToken);
            self::assertTrue($forbidden['is_message']);

            $translations = $this->scopedApi('matrix', RequestMethod::GET, 2,
                [], $sessionKey, $csrfToken);
            self::assertTrue($translations['is_message']);
        } finally {
            $statement = $connection->prepare('DELETE FROM sessions WHERE `key` = ?');
            $statement->bind_param('s', $sessionKey);
            $statement->execute();
            $connection->query('DELETE FROM acl_map WHERE group_id = 3001');
            $connection->query('DELETE FROM user_groups_map WHERE user_id = 3001');
            $connection->query('DELETE FROM user_groups WHERE id = 3001');
            $connection->query('DELETE FROM users WHERE id = 3001');
        }
    }

    /** @return array<string,mixed> */
    private function entry(int $spaceId, string $locale, string $key): array
    {
        $response = $this->api('entries', RequestMethod::GET, $spaceId, ['locale' => $locale]);
        foreach ($response['entries'] as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        self::fail("Clé {$key} absente de la réponse.");
    }

    private function save(int $spaceId, string $locale, string $key, string $value): void
    {
        $response = $this->api('overrides/save', RequestMethod::POST, $spaceId,
            ['locale' => $locale, 'key' => $key, 'value' => $value]);
        self::assertTrue($response['saved']);
    }

    private function application(?Request $request = null): Application
    {
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request ?? new Request(
            host: TestFixtures::SITE_HOST,
            cookies: ['session_key' => $this->sessionKey],
        ));
        return $application;
    }

    /** @param array<string,mixed> $data */
    private function api(string $path, RequestMethod $method, int $spaceId, array $data): array
    {
        return $this->scopedApi($path, $method, $spaceId, $data, $this->sessionKey, $this->csrfToken);
    }

    /** @param array<string,mixed> $data */
    private function scopedApi(string $path, RequestMethod $method, ?int $spaceId,
        array $data, string $sessionKey, string $csrfToken): array
    {
        $request = new Request(
            method: $method,
            uri: '/administrator/api/languages/' . $path,
            query: $method === RequestMethod::GET && $spaceId !== null ? ['spaceId' => (string) $spaceId, ...$data] : [],
            headers: ['X-CSRF-Token' => $csrfToken],
            cookies: ['session_key' => $sessionKey],
            body: $method === RequestMethod::POST ? [
                'spaceId' => (string) $spaceId,
                'data' => json_encode($data, JSON_THROW_ON_ERROR),
            ] : [],
            host: TestFixtures::SITE_HOST,
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);
        $response = $application->executeAndGetResponse();
        return json_decode($response->body ?? '', true, 512, JSON_THROW_ON_ERROR);
    }
}
