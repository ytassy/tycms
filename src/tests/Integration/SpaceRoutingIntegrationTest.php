<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class SpaceRoutingIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->database->connection()->query('DELETE FROM sessions');
    }

    #[Test]
    public function firstVisitBuildsHomeRouteForHostSpace(): void
    {
        $migrationsBefore = (int) $this->database->connection()
            ->query('SELECT COUNT(*) AS total FROM migrations')->fetch_assoc()['total'];
        $request = new Request(uri: '/fr/', host: TestFixtures::SITE_HOST);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        $migrationsAfter = (int) $this->database->connection()
            ->query('SELECT COUNT(*) AS total FROM migrations')->fetch_assoc()['total'];
        self::assertSame($migrationsBefore, $migrationsAfter);
        self::assertStringStartsWith(str_replace('\\', '/', $this->environment->runtimeRoot()),
            str_replace('\\', '/', $application->getMediasRoot()));
        self::assertSame(TestFixtures::SITE_SPACE_ID, $application->getSession()->getSpaceId());
        $route = $application->getRouter()->resolve($request);
        self::assertNotNull($route);
        self::assertSame(TestFixtures::SITE_HOME_ITEM_ID, $route->routeData['menu_item']->id);
        self::assertSame(TestFixtures::SITE_SPACE_ID, $route->routeData['menu']->space_id);
    }

    #[Test]
    public function sessionSpaceWinsOverHostAndBuildsItsHomeRoute(): void
    {
        $key = bin2hex(random_bytes(24));
        $this->insertSession($key, TestFixtures::ROOT_SPACE_ID);

        $request = new Request(uri: '/fr/', host: TestFixtures::SITE_HOST, cookies: ['session_key' => $key]);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        self::assertSame(TestFixtures::ROOT_SPACE_ID, $application->getSession()->getSpaceId());
        $route = $application->getRouter()->resolve($request);
        self::assertNotNull($route);
        self::assertSame(TestFixtures::ROOT_HOME_ITEM_ID, $route->routeData['menu_item']->id);
        self::assertSame(TestFixtures::ROOT_SPACE_ID, $route->routeData['menu']->space_id);
    }

    #[Test]
    public function deletedSessionSpaceFallsBackToHost(): void
    {
        $key = bin2hex(random_bytes(24));
        $this->insertSession($key, 999999);

        $request = new Request(uri: '/fr/', host: TestFixtures::SITE_HOST, cookies: ['session_key' => $key]);
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        self::assertSame(TestFixtures::SITE_SPACE_ID, $application->getSession()->getSpaceId());
        self::assertSame(TestFixtures::SITE_HOME_ITEM_ID,
            $application->getRouter()->resolve($request)?->routeData['menu_item']->id);
    }

    private function insertSession(string $key, int $spaceId): void
    {
        $statement = $this->database->connection()->prepare('INSERT INTO sessions (`key`, `data`) VALUES (?, ?)');
        $data = serialize(['current_space_id' => $spaceId]);
        $statement->bind_param('ss', $key, $data);
        $statement->execute();
    }
}
