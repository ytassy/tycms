<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use mysqli_sql_exception;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceLanguageHelper;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class SpaceLanguageIntegrationTest extends TestCase
{
    private TestEnvironment $environment;
    private TestDatabase $database;
    private Application $application;
    private SpaceLanguageHelper $languages;

    protected function setUp(): void
    {
        $this->environment = TestEnvironment::load();
        $this->database = new TestDatabase($this->environment);
        TestFixtures::assertPrepared($this->database);
        $this->application = new Application($this->environment->configuration());
        $this->application->initialiseOrThrow(new Request(host: TestFixtures::SITE_HOST));
        $this->languages = $this->application->getOrAddSharedExtensionsClass(SpaceLanguageHelper::class);
    }

    #[Test]
    public function migrationBackfillsFrenchAndFixtureAddsEnglishOnlyToSite(): void
    {
        $root = $this->languages->listActiveForSpace(TestFixtures::ROOT_SPACE_ID);
        $site = $this->languages->listActiveForSpace(TestFixtures::SITE_SPACE_ID);

        self::assertSame([TestFixtures::DEFAULT_LOCALE], array_map(static fn ($item) => $item->locale, $root));
        self::assertSame([TestFixtures::DEFAULT_LOCALE, TestFixtures::SITE_SECOND_LOCALE],
            array_map(static fn ($item) => $item->locale, $site));
        self::assertSame(TestFixtures::DEFAULT_LOCALE,
            $this->languages->getDefaultLocale(TestFixtures::ROOT_SPACE_ID));
        self::assertSame(TestFixtures::DEFAULT_LOCALE,
            $this->languages->getDefaultLocale(TestFixtures::SITE_SPACE_ID));
        self::assertTrue($site[0]->is_default);
        self::assertFalse($site[1]->is_default);
    }

    #[Test]
    public function creatingSpaceAddsFrenchAndDeletingItCascadesToLanguage(): void
    {
        $spaces = $this->application->getOrAddSharedExtensionsClass(SpaceAdminHelper::class);
        $space = $spaces->createSpace((object) [
            'parent_id' => TestFixtures::ROOT_SPACE_ID,
            'template' => 'tpl_main',
            'medias_dir' => 'test-language-' . bin2hex(random_bytes(8)),
            'hosts' => [],
            'title' => 'Integration language fixture',
            'params' => [],
        ]);

        try {
            self::assertSame(TestFixtures::DEFAULT_LOCALE, $this->languages->getDefaultLocale($space->id));
            self::assertCount(1, $this->languages->listActiveForSpace($space->id));
        } finally {
            $spaces->deleteSpace($space->id);
        }

        $remaining = $this->database->connection()->query(
            'SELECT COUNT(*) AS total FROM space_languages WHERE space_id = ' . (int) $space->id
        )->fetch_assoc();
        self::assertSame(0, (int) $remaining['total']);
    }

    #[Test]
    public function disabledLanguageRemainsListedButIsNotActive(): void
    {
        $connection = $this->database->connection();
        $connection->query('UPDATE space_languages SET enabled = 0 WHERE space_id = 2 AND locale = \'en\'');

        try {
            $all = $this->languages->listForSpace(TestFixtures::SITE_SPACE_ID);
            $active = $this->languages->listActiveForSpace(TestFixtures::SITE_SPACE_ID);

            self::assertSame(['fr', 'en'], array_map(static fn ($item) => $item->locale, $all));
            self::assertFalse($all[1]->enabled);
            self::assertSame(['fr'], array_map(static fn ($item) => $item->locale, $active));
        } finally {
            $connection->query('UPDATE space_languages SET enabled = 1 WHERE space_id = 2 AND locale = \'en\'');
        }
    }

    #[Test]
    public function databaseRejectsSecondDefaultAndDisabledDefault(): void
    {
        $connection = $this->database->connection();

        $secondDefaultRejected = false;
        try {
            $connection->query('UPDATE space_languages SET is_default = 1 WHERE space_id = 2 AND locale = \'en\'');
        } catch (mysqli_sql_exception) {
            $secondDefaultRejected = true;
        }
        self::assertTrue($secondDefaultRejected);

        $disabledDefaultRejected = false;
        try {
            $connection->query('UPDATE space_languages SET enabled = 0 WHERE space_id = 2 AND locale = \'fr\'');
        } catch (mysqli_sql_exception) {
            $disabledDefaultRejected = true;
        }
        self::assertTrue($disabledDefaultRejected);
        self::assertSame('fr', $this->languages->getDefaultLocale(TestFixtures::SITE_SPACE_ID));
    }
}
