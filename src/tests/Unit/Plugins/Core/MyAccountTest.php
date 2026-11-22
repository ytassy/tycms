<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Helper\AccountReturnPath;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\CoreExts\Core\WF\WFMyAccount;
use YTassy\Tycms\CoreExts\Core\WF\WFSso;
use YTassy\Tycms\CoreExts\Core\PageComponents\MyAccount;
use YTassy\Tycms\Application;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Tests\Support\PageFixtures;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\{PageRenderContext, PageRenderMode};
use YTassy\Tycms\Response\ExceptionResponse;

final class MyAccountTest extends TestCase
{
    public function testAnonymousPublicRenderingIsEmptyAndHasNoPersonalData(): void
    {
        $session = $this->createStub(Session::class);
        $session->method('getUserId')->willReturn(null);
        $app = $this->createMock(Application::class);
        $app->method('getSession')->willReturn($session);
        $app->expects(self::never())->method('getDatabase');
        $component = new MyAccount($app);
        $node = (object) ['props' => (object) MyAccount::getDefaultData()];
        $context = PageFixtures::renderContext();
        self::assertSame('', $component->render($node, $context));
        self::assertSame(['settings', 'authenticated'], array_keys((array) $component->getPublicProps($node, $context)));
        self::assertFalse($component->getPublicProps($node, $context)->authenticated);
    }

    public function testLinkCallbackConsumesItsStateAndRejectsAnotherSessionUserBeforeAnyNetworkOrSql(): void
    {
        $session = $this->createMock(Session::class);
        $session->method('get')->willReturn(['expected-state' => [
            'purpose' => 'link', 'user_id' => 10, 'space_id' => 2, 'created' => time(),
        ]]);
        $session->method('getUserId')->willReturn(11);
        $session->expects(self::once())->method('set')->with('sso_login_flows', []);
        $app = $this->createMock(Application::class);
        $app->method('getSession')->willReturn($session);
        $app->expects(self::never())->method('getDatabase');
        $this->expectException(\RuntimeException::class);
        (new WFSso($app))->finishLogin(new Request(query: ['state' => 'expected-state', 'code' => 'unused']));
    }

    public function testReturnPathsRejectExternalAndEncodedRedirects(): void
    {
        foreach (['https://evil.test', '//evil.test', '/\\evil.test', '/%2fevil.test', '/%5cevil.test', "/\r\nevil", ''] as $path) {
            try {
                AccountReturnPath::validate($path);
                self::fail('Destination acceptée : ' . $path);
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        self::assertSame('/fr/mon-compte?tab=sso', AccountReturnPath::validate('/fr/mon-compte?tab=sso'));
    }

    public function testAnUnknownSsoPasswordIsNeverCountedAsALocalLogin(): void
    {
        $user = (object) ['params' => '{}', 'password' => password_hash('mot-de-passe-test', PASSWORD_BCRYPT, ['cost' => 4])];
        self::assertTrue(WFMyAccount::hasLocalPassword($user, []));
        self::assertFalse(WFMyAccount::hasLocalPassword($user, [(object) ['id' => 1]]));
        $user->params = '{"local_password_enabled":0}';
        self::assertFalse(WFMyAccount::hasLocalPassword($user, []));
        $user->params = '{"local_password_enabled":1}';
        self::assertTrue(WFMyAccount::hasLocalPassword($user, [(object) ['id' => 1]]));
        $user->params = '{"local_password_enabled":"true"}';
        self::assertFalse(WFMyAccount::hasLocalPassword($user, []));
        $user->params = '{"local_password_enabled":1}';
        $user->password = '';
        self::assertFalse(WFMyAccount::hasLocalPassword($user, []));
    }

    public function testTheEditorRegistersTheComponentAndNormalizesGranularBooleanOptions(): void
    {
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $editor = $registry->prepareEditor(7);
        self::assertFalse(property_exists($editor['forms'], 'core.my-account'));
        $entry = array_values(array_filter($registry->catalogue(), static fn ($item) => $item['id'] === 'core.my-account'))[0];
        self::assertSame('core/my-account', $entry['formVue']);
        $document = PageFixtures::decode('{"items":[{"type":"row","columns":[{"items":[{"type":"core.my-account","props":{"readonly":true,"edit_email":false,"remove_sso":false}}]}]}]}');
        $document = $registry->normalizeDocument($document, 7);
        $props = $document->items[0]->zones->columns[0]->items[0]->props;
        self::assertTrue($props->readonly);
        self::assertFalse($props->edit_email);
        self::assertFalse($props->remove_sso);
        self::assertTrue($props->show_first_name);
        self::assertSame('simple', $props->layout);
    }

    public function testUnknownLayoutsAreRejectedAtTheDocumentBoundary(): void
    {
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $document = PageFixtures::decode('{"items":[{"type":"row","columns":[{"items":[{"type":"core.my-account","props":{"layout":"<script>"}}]}]}]}');
        $this->expectException(ExceptionResponse::class);
        $registry->normalizeDocument($document, 7);
    }

    public function testEachPreviewLayoutUsesFictitiousDataAndHonoursHiddenFields(): void
    {
        $component = new MyAccount(PageFixtures::application());
        $public = PageFixtures::renderContext();
        $context = new PageRenderContext($public->page, 'fr', PageRenderMode::CANVAS,
            static fn (array $items): string => '', static fn (object $props): bool => true,
            static fn (object $props): string => '', static fn (mixed $value): string => (string) $value);

        foreach (['simple', 'list', 'table'] as $layout) {
            $props = (object) [...MyAccount::getDefaultData(), 'layout' => $layout, 'show_email' => false];
            $html = $component->render((object) ['props' => $props], $context);
            self::assertStringContainsString('Camille', $html);
            self::assertStringNotContainsString('camille@example.com', $html);
            self::assertSame($layout === 'table', str_contains($html, '<table '));
            self::assertStringNotContainsString('<thead>', $html);
            self::assertSame($layout === 'list', str_contains($html, 'Prénom :</dt>'));
        }
    }
}
