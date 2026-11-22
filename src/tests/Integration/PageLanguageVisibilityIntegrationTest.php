<?php

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class PageLanguageVisibilityIntegrationTest extends TestCase
{
    public function testOnlyDistinctConfiguredSpaceLanguagesCanControlPageNodes(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));

        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(uri: '/fr/', host: TestFixtures::SITE_HOST));
        $registry = new PageComponentRegistry($application);

        // Tester chaque niveau séparément : un parent invalide ne doit pas
        // masquer une absence de validation sur les colonnes ou les composants.
        foreach (['row', 'column', 'component'] as $target) {
            foreach ([[], ['fr'], ['en'], ['fr', 'en']] as $languages) {
                $registry->validateDocument($this->document($languages, $target), TestFixtures::SITE_SPACE_ID);
            }

            foreach ([['de'], ['fr', 'fr'], ['FR'], [42], null, 'fr'] as $languages) {
                try {
                    $registry->validateDocument($this->document($languages, $target), TestFixtures::SITE_SPACE_ID);
                    self::fail('Une condition de langue invalide a été acceptée.');
                } catch (ExceptionResponse) {
                    self::assertTrue(true);
                }
            }
        }
    }

    public function testDisabledButConfiguredLanguageRemainsValid(): void
    {
        $environment = TestEnvironment::load();
        TestFixtures::assertPrepared(new TestDatabase($environment));
        $database = new TestDatabase($environment);
        $database->connection()->query("UPDATE space_languages SET enabled = 0 WHERE space_id = 2 AND locale = 'en'");

        try {
            $application = new Application($environment->configuration());
            $application->initialiseOrThrow(new Request(uri: '/fr/', host: TestFixtures::SITE_HOST));
            $registry = new PageComponentRegistry($application);

            $registry->validateDocument($this->document(['en'], 'component'), TestFixtures::SITE_SPACE_ID);
            self::assertTrue(true);
        } finally {
            $database->connection()->query("UPDATE space_languages SET enabled = 1 WHERE space_id = 2 AND locale = 'en'");
        }
    }

    private function document(mixed $languages, string $target): object
    {
        $condition = ['common' => ['languages' => $languages]];

        return PageDocument::normalize(PageFixtures::decode(json_encode(['items' => [[
            'type' => 'core.row', 'props' => $target === 'row' ? $condition : [],
            'columns' => [[
                'props' => $target === 'column' ? $condition : [],
                'items' => [[
                    'type' => 'core.heading', 'props' => [
                        'text' => 'Titre', ...($target === 'component' ? $condition : []),
                    ],
                ]],
            ]],
        ]]], JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR));
    }
}
