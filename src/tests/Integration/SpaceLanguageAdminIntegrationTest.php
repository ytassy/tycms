<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class SpaceLanguageAdminIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private string $sessionKey;
    private string $csrfToken;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);

        $this->sessionKey = bin2hex(random_bytes(24));
        $this->csrfToken = bin2hex(random_bytes(32));
        $data = serialize([
            'user_id' => 1,
            'current_space_id' => TestFixtures::SITE_SPACE_ID,
            'csrf_token' => $this->csrfToken,
        ]);
        $statement = $this->database->connection()->prepare(
            'INSERT INTO sessions (`key`, `user_id`, `data`) VALUES (?, 1, ?)'
        );
        $statement->bind_param('ss', $this->sessionKey, $data);
        $statement->execute();
    }

    protected function tearDown(): void
    {
        $connection = $this->database->connection();
        $connection->query("DELETE FROM space_languages WHERE space_id = 2 AND locale = 'de'");
        $connection->query('UPDATE space_languages SET is_default = 0 WHERE space_id = 2');
        $connection->query("UPDATE space_languages SET enabled = 1, is_default = 1, sort_order = 0 WHERE space_id = 2 AND locale = 'fr'");
        $connection->query("UPDATE space_languages SET enabled = 1, is_default = 0, sort_order = 10 WHERE space_id = 2 AND locale = 'en'");
        $statement = $connection->prepare('DELETE FROM sessions WHERE `key` = ?');
        $statement->bind_param('s', $this->sessionKey);
        $statement->execute();
    }

    #[Test]
    public function adminCanReadAddReorderAndChangeDefaultThroughApi(): void
    {
        $initial = $this->api('languages', RequestMethod::GET);
        self::assertFalse($initial['error']);
        self::assertTrue($initial['canEdit']);
        self::assertSame(['fr', 'en'], array_column($initial['languages'], 'locale'));

        $saved = $this->api('languages/save', RequestMethod::POST, [
            ['locale' => 'en', 'enabled' => true, 'is_default' => true],
            ['locale' => 'fr', 'enabled' => false, 'is_default' => false],
            ['locale' => 'de', 'enabled' => true, 'is_default' => false],
        ]);
        self::assertFalse($saved['error']);
        self::assertSame(['en', 'fr', 'de'], array_column($saved['languages'], 'locale'));
        self::assertSame([true, false, false], array_column($saved['languages'], 'is_default'));
        self::assertSame([true, false, true], array_column($saved['languages'], 'enabled'));

        $reread = $this->api('languages', RequestMethod::GET);
        self::assertSame($saved['languages'], $reread['languages']);
    }

    #[Test]
    public function apiRejectsDeletingStoredLanguageAndKeepsRowsUntouched(): void
    {
        $before = $this->api('languages', RequestMethod::GET)['languages'];
        $response = $this->api('languages/save', RequestMethod::POST, [
            ['locale' => 'fr', 'enabled' => true, 'is_default' => true],
        ]);
        self::assertTrue($response['is_message']);
        self::assertStringContainsString('ne peut pas être supprimée', $response['message']);
        self::assertSame($before, $this->api('languages', RequestMethod::GET)['languages']);
    }

    #[Test]
    public function apiRejectsInvalidDefaultAndMissingCsrfToken(): void
    {
        $invalid = $this->api('languages/save', RequestMethod::POST, [
            ['locale' => 'fr', 'enabled' => false, 'is_default' => true],
            ['locale' => 'en', 'enabled' => true, 'is_default' => false],
        ]);
        self::assertTrue($invalid['is_message']);
        self::assertStringContainsString('doit être active', $invalid['message']);

        $noCsrf = $this->api('languages/save', RequestMethod::POST, [
            ['locale' => 'fr', 'enabled' => true, 'is_default' => true],
            ['locale' => 'en', 'enabled' => true, 'is_default' => false],
        ], false);
        self::assertTrue($noCsrf['error']);
        self::assertSame(['fr', 'en'], array_column($this->api('languages', RequestMethod::GET)['languages'], 'locale'));
    }

    #[Test]
    public function guestCannotReadOrChangeSpaceLanguages(): void
    {
        $request = new Request(
            uri: '/administrator/api/spaces/languages',
            query: ['spaceId' => '2'],
            host: TestFixtures::SITE_HOST,
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);
        $response = json_decode($application->executeAndGetResponse()->body ?? '', true, 512, JSON_THROW_ON_ERROR);

        self::assertTrue($response['is_message']);
        self::assertStringContainsString('droits', $response['message']);
    }

    /** @param list<array<string,mixed>>|null $languages */
    private function api(string $path, RequestMethod $method, ?array $languages = null, bool $withCsrf = true): array
    {
        $request = new Request(
            method: $method,
            uri: '/administrator/api/spaces/' . $path,
            query: $method === RequestMethod::GET ? ['spaceId' => '2'] : [],
            headers: $withCsrf ? ['X-CSRF-Token' => $this->csrfToken] : [],
            cookies: ['session_key' => $this->sessionKey],
            body: $languages === null ? [] : [
                'spaceId' => '2',
                'languages' => json_encode($languages, JSON_THROW_ON_ERROR),
            ],
            host: TestFixtures::SITE_HOST,
        );
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);
        $response = $application->executeAndGetResponse();

        self::assertNotNull($response);
        return json_decode($response->body ?? '', true, 512, JSON_THROW_ON_ERROR);
    }
}
