<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Security;

use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminController;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminFontController;
use YTassy\Tycms\CoreExts\Security\Plugin as SecurityPlugin;
use YTassy\Tycms\Response\CssResponse;
use YTassy\Tycms\CoreExts\Security\SecurityPolicy;
use YTassy\Tycms\CoreExts\Security\Helper\SecurityPolicyHelper;
use YTassy\Tycms\Response\HtmlResponse;
use YTassy\Tycms\Response\RedirectResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PluginTest extends FrameworkTestCase
{
    protected function createApplication(): Application
    {
        $app = parent::createApplication();
        $policy = $this->createStub(SecurityPolicyHelper::class);
        $policy->method('responseSources')->willReturn((new SecurityPolicy(SecurityPolicy::defaults()))->previewSources());
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [SecurityPolicyHelper::class => $policy]);
        return $app;
    }

    #[Test]
    public function itAddsAProductionPolicyBlockingThirdPartyStyleResources(): void
    {
        $application = $this->createApplication();
        $application->getConf()->dev = false;
        $response = new HtmlResponse('');

        (new SecurityPlugin($application))->onBeforeSendResponse($response);

        self::assertSame(
            "default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; script-src 'self'; script-src-attr 'none'; worker-src 'self' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; frame-src 'self' https:",
            $response->httpHeaders['Content-Security-Policy'],
        );
        self::assertSame('nosniff', $response->httpHeaders['X-Content-Type-Options']);
        self::assertSame('0', $response->httpHeaders['X-XSS-Protection']);
    }

    #[Test]
    public function itAllowsGoogleFontsOnlyForTheResolvedAdministrationPage(): void
    {
        foreach ([false, true] as $development) {
            $app = $this->createApplication();
            $app->getConf()->dev = $development;
            $route = new Route('AdministratorHome', new AdminController($app), 'home', false, [], [], [], false, false, false, false, []);
            (new ReflectionProperty(Application::class, 'route'))->setValue($app, $route);
            $response = new HtmlResponse('');
            (new SecurityPlugin($app))->onBeforeSendResponse($response);

            $csp = $response->httpHeaders['Content-Security-Policy'];
            self::assertStringContainsString("style-src 'self' 'unsafe-inline' https://fonts.googleapis.com", $csp);
            self::assertStringContainsString("font-src 'self' data: https://fonts.gstatic.com", $csp);
            self::assertStringNotContainsString("script-src 'self' https://fonts", $csp);
        }
    }

    #[Test]
    public function anAdministrationApiDoesNotEnableGoogleFontSources(): void
    {
        $app = $this->createApplication();
        $route = new Route('AdminFontsinstall', new AdminFontController($app), 'install', true, [], [], [], true, false, false, false, []);
        (new ReflectionProperty(Application::class, 'route'))->setValue($app, $route);
        $response = new HtmlResponse('');
        (new SecurityPlugin($app))->onBeforeSendResponse($response);

        self::assertStringNotContainsString('fonts.googleapis.com', $response->httpHeaders['Content-Security-Policy']);
        self::assertStringNotContainsString('fonts.gstatic.com', $response->httpHeaders['Content-Security-Policy']);
    }

    #[Test]
    public function itAllowsTheViteDevelopmentServerOnlyInDevelopment(): void
    {
        $response = new HtmlResponse('');

        (new SecurityPlugin($this->createApplication()))->onBeforeSendResponse($response);

        self::assertStringContainsString(
            "script-src 'self' http://localhost:3000",
            $response->httpHeaders['Content-Security-Policy'],
        );
        self::assertStringContainsString(
            "worker-src 'self' blob: http://localhost:3000",
            $response->httpHeaders['Content-Security-Policy'],
        );
        self::assertStringNotContainsString(
            "script-src 'self' blob:",
            $response->httpHeaders['Content-Security-Policy'],
        );
        self::assertStringContainsString(
            "media-src 'self' blob:",
            $response->httpHeaders['Content-Security-Policy'],
        );
        self::assertStringContainsString(
            "connect-src 'self' http://localhost:3000 ws://localhost:3000",
            $response->httpHeaders['Content-Security-Policy'],
        );
    }

    #[Test]
    public function itDoesNotDuplicateExistingResponseHeaders(): void
    {
        $response = new CssResponse('');

        (new SecurityPlugin($this->createApplication()))->onBeforeSendResponse($response);

        self::assertSame('nosniff', $response->httpHeaders['X-Content-Type-Options']);
    }

    #[Test]
    public function itAllowsSsoRedirectTargetsInFormActionsOnlyForHtmlResponses(): void
    {
        $app = parent::createApplication();
        $policy = $this->createStub(SecurityPolicyHelper::class);
        $policy->method('responseSources')->willReturn((new SecurityPolicy(SecurityPolicy::defaults()))->previewSources());
        $policy->method('formActionsForCurrentSpace')->willReturn(['https://identity.example']);
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [SecurityPolicyHelper::class => $policy]);

        $document = new HtmlResponse('');
        (new SecurityPlugin($app))->onBeforeSendResponse($document);
        self::assertStringContainsString(
            "form-action 'self' https://identity.example;",
            $document->httpHeaders['Content-Security-Policy'],
        );

        // Seuls les documents portent le formulaire ; une redirection n’a pas besoin de cette ouverture.
        $redirect = new RedirectResponse('/login');
        (new SecurityPlugin($app))->onBeforeSendResponse($redirect);
        self::assertStringContainsString("form-action 'self';", $redirect->httpHeaders['Content-Security-Policy']);
    }
}
