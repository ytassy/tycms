<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use InvalidArgumentException;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\Frontend\FrontendAssets;
use YTassy\Tycms\CoreExts\Security\ResponseHeaders;
use YTassy\Tycms\CoreExts\Security\SecurityPolicy;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class FrontendAssetsTest extends FrameworkTestCase
{
    private array $environment = [];

    protected function setUp(): void
    {
        foreach (['DEV_CORE_VITE' => 'true', 'DEV_EXTENSION' => '', 'DEV_EXTENSION_VITE_PORT' => '3001'] as $key => $value) {
            $this->environment[$key] = getenv($key);
            putenv($key . '=' . $value);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) putenv($value === false ? $key : $key . '=' . $value);
    }

    public function testProductionIgnoresEvenInvalidDevelopmentEnvironment(): void
    {
        putenv('DEV_EXTENSION=../../private');
        putenv('DEV_EXTENSION_VITE_PORT=invalid');
        $app = $this->createApplication();
        $app->getConf()->dev = false;
        $helper = new FrontendAssets($app);
        self::assertSame([], $helper->developmentOrigins());
        self::assertSame(['core' => false, 'extension' => '', 'port' => 3001], $helper->developmentSettings());
    }

    public function testDevelopmentRejectsAParentPath(): void
    {
        putenv('DEV_EXTENSION=plugins/../../private');
        $this->expectException(RuntimeException::class);
        (new FrontendAssets($this->createApplication()))->developmentSettings();
    }

    public function testOnlyTheSelectedDevelopmentOriginsAreAllowed(): void
    {
        putenv('DEV_CORE_VITE=false');
        putenv('DEV_EXTENSION=plugins/Lms');
        putenv('DEV_EXTENSION_VITE_PORT=3011');
        $helper = new FrontendAssets($this->createApplication());
        $sources = (new SecurityPolicy(SecurityPolicy::defaults()))->previewSources();
        $csp = ResponseHeaders::build($sources, true, false, $helper->developmentOrigins())['Content-Security-Policy'];
        self::assertStringContainsString("script-src 'self' http://localhost:3011;", $csp);
        self::assertStringContainsString('ws://localhost:3011', $csp);
        self::assertStringNotContainsString('localhost:3000', $csp);
        self::assertStringNotContainsString('localhost:3011', ResponseHeaders::build($sources, false, false, $helper->developmentOrigins())['Content-Security-Policy']);
    }

    public function testImportMapNonceIsAddedToCspWithoutAllowingInlineScripts(): void
    {
        $helper = new FrontendAssets($this->createApplication());
        self::assertNull($helper->cspNonce());
        $html = $helper->renderTags();
        $nonce = $helper->cspNonce();
        self::assertStringContainsString('type="importmap" nonce="' . $nonce . '"', $html);
        preg_match('~<script type="importmap"[^>]*>(.*?)</script>~s', $html, $match);
        $map = json_decode($match[1], true, 512, JSON_THROW_ON_ERROR)['imports'];
        self::assertSame($map['vue'], $map['https://esm.tycms.invalid/vue']);

        $sources = (new SecurityPolicy(SecurityPolicy::defaults()))->previewSources();
        $csp = ResponseHeaders::build($sources, true, false, $helper->developmentOrigins(), $nonce)['Content-Security-Policy'];
        self::assertStringContainsString("'nonce-" . $nonce . "'", $csp);
        preg_match('~script-src ([^;]+)~', $csp, $script);
        self::assertStringNotContainsString('unsafe-inline', $script[1]);
    }

    public function testNonceCannotInjectAnAdditionalCspDirective(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $sources = (new SecurityPolicy(SecurityPolicy::defaults()))->previewSources();
        ResponseHeaders::build($sources, false, false, [], "x'; script-src *");
    }
}
