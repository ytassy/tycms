<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageComponentRegistry;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class AuthenticationComponentTest extends TestCase
{
    public function testGeneralFormsUseSharedControlsAndRichTextIsPurifiedInEveryLanguage(): void
    {
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $editor = $registry->prepareEditor(7);
        foreach (['core.login', 'core.register'] as $id) {
            $form = $editor['forms']->{$id};
            self::assertTrue($form['hideSingleSectionTab']);
            self::assertNotEmpty($form['sections'][0]['fields']);
        }
        $document = PageFixtures::decode('{"items":[{"id":"r","type":"row","columns":[{"id":"c","items":[{"id":"login","type":"core.login","props":{"form_text":{"fr":"<p>CGU<script>alert(1)</script></p>","en":"<img src=\"/x\" onerror=\"alert(1)\">"}}},{"id":"register","type":"core.register","props":{"terms_enabled":true,"terms_text":"<p>J’accepte <a href=\"javascript:alert(1)\">les CGU</a></p>"}}]}]}]}');
        $document = $registry->normalizeDocument($document, 7);
        [$login, $register] = $document->items[0]->zones->columns[0]->items;
        self::assertStringNotContainsString('<script', $login->props->form_text->fr);
        self::assertStringNotContainsString('onerror', $login->props->form_text->en);
        self::assertStringNotContainsString('javascript:', $register->props->terms_text);
        self::assertTrue($register->props->terms_enabled);
    }

    public function testDevelopmentCredentialsAreNeverExposedInProduction(): void
    {
        $app = PageFixtures::application();
        $app->getConf()->first_admin_email = 'development@example.test';
        $app->getConf()->first_admin_password = 'development-only-password';
        $helper = new \YTassy\Tycms\CoreExts\Core\Helper\AuthenticationHelper($app);
        $app->getConf()->dev = false;
        self::assertSame([], $helper->developmentCredentials());
        $app->getConf()->dev = true;
        self::assertSame(['email' => 'development@example.test', 'password' => 'development-only-password'], $helper->developmentCredentials());
        $app->getConf()->dev = false;
        self::assertSame([], $helper->developmentCredentials());
    }

    public function testAuthenticationSwitchesCannotBeForgedWithStrings(): void
    {
        $document = PageFixtures::decode('{"items":[{"id":"r","type":"row","columns":[{"id":"c","items":[{"id":"login","type":"core.login","props":{"form_enabled":"false"}}]}]}]}');
        $registry = PageFixtures::application()->getOrAddSharedExtensionsClass(PageComponentRegistry::class);
        $this->expectException(ExceptionResponse::class);
        $registry->normalizeDocument($document, 7);
    }
}
