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

final class PublicLocaleRoutingIntegrationTest extends TestCase
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
    public function browserLanguageRedirectsPublicHomeAndKeepsQueryString(): void
    {
        $request = new Request(
            uri: '/?source=mail&item=2',
            headers: ['Accept-Language' => 'fr-FR;q=0.4,en-US;q=0.9'],
            host: TestFixtures::SITE_HOST,
        );

        $application = $this->application($request);
        $response = $application->executeAndGetResponse();

        self::assertSame(TestFixtures::SITE_SPACE_ID, $application->getSession()->getSpaceId());
        self::assertSame(302, $response->code);
        self::assertSame('/en/?source=mail&item=2', $response->httpHeaders['Location']);
        self::assertSame('Accept-Language', $response->httpHeaders['Vary']);
        self::assertSame('private, no-store', $response->httpHeaders['Cache-Control']);
    }

    #[Test]
    public function explicitPrefixWinsOverBrowserLanguage(): void
    {
        $request = new Request(
            uri: '/fr/',
            headers: ['Accept-Language' => 'en-US,en;q=0.9'],
            host: TestFixtures::SITE_HOST,
        );

        $application = $this->application($request);
        $route = $application->getRouter()->resolve($request);

        self::assertNotNull($route);
        self::assertSame(TestFixtures::SITE_HOME_ITEM_ID, $route->routeData['menu_item']->id);
        self::assertSame('/fr/', $application->getRouter()->url($route->name));
        self::assertNull($application->getRouter()->resolve(new Request(uri: '/en/', host: TestFixtures::SITE_HOST)));
    }

    #[Test]
    public function publicLanguageSurvivesTechnicalPagesAndUnprefixedUrls(): void
    {
        $key = $this->insertSession([]);

        $public = new Request(
            uri: '/en/',
            headers: ['Accept-Language' => 'fr-FR'],
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );

        self::assertSame(200, $this->application($public)->executeAndGetResponse()->code);

        $technical = new Request(
            uri: '/administrator',
            headers: ['Accept-Language' => 'fr-FR'],
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );
        $application = $this->application($technical);

        self::assertSame('en', $application->getSession()->get('public_locale'));
        self::assertSame('/administrator', $application->getRouter()->url('AdministratorHome'));
        self::assertSame('/en/', $application->getRouter()->url('core_menu_' . TestFixtures::SITE_HOME_ITEM_ID . '_get'));

        $unprefixed = new Request(
            uri: '/',
            headers: ['Accept-Language' => 'fr-FR'],
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );

        self::assertSame('/en/', $this->application($unprefixed)->executeAndGetResponse()->httpHeaders['Location']);
    }

    #[Test]
    public function anotherExplicitPrefixUpdatesTheRememberedLanguage(): void
    {
        $key = $this->insertSession(['public_locale' => 'en']);
        $request = new Request(
            uri: '/fr/',
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );

        self::assertSame(200, $this->application($request)->executeAndGetResponse()->code);

        $technical = new Request(
            uri: '/administrator',
            headers: ['Accept-Language' => 'en-US'],
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );
        $application = $this->application($technical);

        self::assertSame('fr', $application->getSession()->get('public_locale'));
        self::assertSame('/fr/', $application->getRouter()->url('core_menu_' . TestFixtures::SITE_HOME_ITEM_ID . '_get'));
    }

    #[Test]
    public function rememberedLanguageFallsBackWhenInactiveInCurrentSpace(): void
    {
        $key = $this->insertSession([
            'current_space_id' => TestFixtures::ROOT_SPACE_ID,
            'public_locale' => 'en',
        ]);
        $request = new Request(
            uri: '/administrator',
            headers: ['Accept-Language' => 'de-DE'],
            cookies: ['session_key' => $key],
            host: TestFixtures::SITE_HOST,
        );
        $application = $this->application($request);

        self::assertSame(TestFixtures::ROOT_SPACE_ID, $application->getSession()->getSpaceId());
        self::assertSame('/fr/', $application->getRouter()->url('core_menu_' . TestFixtures::ROOT_HOME_ITEM_ID . '_get'));
        self::assertSame('en', $application->getSession()->get('public_locale'));
    }

    #[Test]
    public function englishPageUsesEnglishHtmlLanguageAndHomeLink(): void
    {
        $request = new Request(
            uri: '/en/',
            headers: ['Accept' => 'text/html', 'Accept-Language' => 'fr-FR'],
            host: TestFixtures::SITE_HOST,
        );

        $response = $this->application($request)->executeAndGetResponse();

        self::assertSame(200, $response->code);
        self::assertStringContainsString('<html lang="en">', $response->body);
        self::assertStringContainsString('navbar-brand d-flex align-items-center" href="/en/"', $response->body);
    }

    #[Test]
    public function inactiveOrUnknownPrefixHasNoPublicRoute(): void
    {
        $unknown = new Request(uri: '/zz/', host: TestFixtures::SITE_HOST);
        $application = $this->application($unknown);

        self::assertNull($application->getRouter()->resolve($unknown));

        $key = $this->insertSession(['current_space_id' => TestFixtures::ROOT_SPACE_ID]);

        // La session choisit le Space racine, où l'anglais n'est pas activé.
        $inactive = new Request(uri: '/en/', host: TestFixtures::SITE_HOST, cookies: ['session_key' => $key]);
        $application = $this->application($inactive);

        self::assertSame(TestFixtures::ROOT_SPACE_ID, $application->getSession()->getSpaceId());
        self::assertNull($application->getRouter()->resolve($inactive));
    }

    #[Test]
    public function technicalRoutesKeepTheirUnprefixedUrls(): void
    {
        $request = new Request(uri: '/administrator', host: TestFixtures::SITE_HOST);
        $application = $this->application($request);

        self::assertSame('/administrator', $application->getRouter()->url('AdministratorHome'));
        self::assertNotNull($application->getRouter()->resolve($request));
        self::assertSame('/space/choose', $application->getRouter()->url('SpaceChoose'));
    }

    #[Test]
    public function oldPublicPagePathRedirectsToItsPrefixedRoute(): void
    {
        $application = $this->application(new Request(uri: '/fr/', host: TestFixtures::SITE_HOST));
        $pagePath = null;

        foreach ($application->getRouter()->getAllRoutes() as $route) {
            if (($route['route_data']['menu_item']->type ?? null) === 'page'
                && $route['path'] !== '/fr/') {
                $pagePath = $route['path'];
                break;
            }
        }

        self::assertNotNull($pagePath);

        $oldPath = substr($pagePath, strlen('/fr'));
        $response = $this->application(new Request(uri: $oldPath, host: TestFixtures::SITE_HOST))
            ->executeAndGetResponse();

        self::assertSame(302, $response->code);
        self::assertSame($pagePath, $response->httpHeaders['Location']);
    }

    #[Test]
    public function defaultLanguageIsUsedWhenBrowserHasNoActiveMatch(): void
    {
        $request = new Request(
            uri: '/',
            headers: ['Accept-Language' => 'de-DE,de;q=0.8'],
            host: TestFixtures::SITE_HOST,
        );

        $response = $this->application($request)->executeAndGetResponse();

        self::assertSame('/fr/', $response->httpHeaders['Location']);
    }

    #[Test]
    public function prefixedHomeWithoutTrailingSlashRedirectsToCanonicalPath(): void
    {
        $request = new Request(uri: '/en?source=bookmark', host: TestFixtures::SITE_HOST);
        $response = $this->application($request)->executeAndGetResponse();

        self::assertSame(302, $response->code);
        self::assertSame('/en/?source=bookmark', $response->httpHeaders['Location']);
    }

    private function application(Request $request): Application
    {
        $application = new Application($this->environment->configuration());
        $application->initialiseOrThrow($request);

        return $application;
    }

    private function insertSession(array $values): string
    {
        $key = bin2hex(random_bytes(24));
        $data = serialize($values);
        $statement = $this->database->connection()->prepare('INSERT INTO sessions (`key`, `data`) VALUES (?, ?)');
        $statement->bind_param('ss', $key, $data);
        $statement->execute();

        return $key;
    }
}
