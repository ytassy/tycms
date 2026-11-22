<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use YTassy\Tycms\CoreExts\Security\SecurityPolicy;
use YTassy\Tycms\CoreExts\Security\ResponseHeaders;
use YTassy\Tycms\CoreExts\Security\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SecurityPolicyTest extends FrameworkTestCase
{
    public static function invalidOrigins(): iterable
    {
        foreach (["https://good.test; script-src https:", "https://good.test\r\nX-Evil: bad", 'http://example.test',
            'https://*.example.test', 'https:', '*', "'self'", 'https://user:secret@example.test',
            'https://example.test/path', 'https://example.test?x=1', 'https://example.test#fragment',
            'https://example.test\\@evil.test', 'https://example.test:0', 'https://127.1', 'https://0177.0.0.1',
            'https://0x7f000001', 'https://example.test.', 'data:text/plain,hi', 'https://éxample.test', 'https://a.test https://b.test', 'https://a.test,https://b.test', 'https://a.test;https://b.test',
        ] as $origin) yield [$origin];
    }

    #[DataProvider('invalidOrigins')]
    public function testUntrustedOriginsCannotInjectOrBroadenCsp(string $origin): void
    {
        $this->expectException(RuntimeException::class);
        SecurityPolicy::origins($origin);
    }

    public function testOriginsAreCanonicalDeduplicatedAndExact(): void
    {
        self::assertSame(['https://docs.google.com', 'https://media.test:8443'],
            SecurityPolicy::origins(" HTTPS://Docs.Google.com:443/ \r\nhttps://docs.google.com\nhttps://media.test:8443"));
        self::assertSame(['https://[::1]:8443'], SecurityPolicy::origins('https://[0:0:0:0:0:0:0:1]:8443'));
        $values = SecurityPolicy::defaults();
        $values['iframe_mode'] = 'allowlist';
        $values['iframe_origins'] = 'https://docs.google.com';
        $policy = new SecurityPolicy($values);
        self::assertTrue($policy->allowsFrame('https://docs.google.com/presentation/embed?x=1', null));
        self::assertFalse($policy->allowsFrame('https://docs.google.com.evil.test/embed', null));
        self::assertFalse($policy->allowsFrame('https://sub.docs.google.com/embed', null));
        self::assertFalse($policy->allowsFrame('https://docs.google.com:8443/embed', null));
        self::assertFalse($policy->allowsFrame('https://docs.google.com@evil.test/embed', null));
        self::assertFalse($policy->allowsFrame('http://docs.google.com/embed', null));
        self::assertTrue($policy->allowsFrame('https://site.test/embed', 'https://site.test'));
        self::assertStringContainsString('https://docs.google.com', $policy->iframeDescription());
    }

    public function testSelfModeDoesNotUseTheRetainedExternalList(): void
    {
        $values = SecurityPolicy::defaults();
        $values['iframe_mode'] = 'self';
        $values['iframe_origins'] = 'https://docs.google.com';
        $policy = new SecurityPolicy($values);
        self::assertSame([], $policy->sources('iframe'));
        self::assertFalse($policy->allowsFrame('https://docs.google.com/embed', 'https://site.test'));
        self::assertTrue($policy->allowsFrame('https://site.test/embed', 'https://site.test'));
    }

    public function testResourceSettingsDoNotAuthorizeScriptsOrExternalConnections(): void
    {
        $values = SecurityPolicy::defaults();
        foreach (SecurityPolicy::LIST_FIELDS as $field) $values[$field] = 'https://external.test';
        $values['iframe_mode'] = 'allowlist';
        $csp = ResponseHeaders::build((new SecurityPolicy($values))->previewSources(), false, false)['Content-Security-Policy'];
        self::assertStringContainsString("script-src 'self';", $csp);
        self::assertStringContainsString("connect-src 'self';", $csp);
        self::assertStringContainsString("img-src 'self' data: https://external.test;", $csp);
        self::assertStringContainsString("media-src 'self' blob: https://external.test;", $csp);
        self::assertStringContainsString("style-src 'self' 'unsafe-inline' https://external.test;", $csp);
        self::assertStringContainsString("font-src 'self' data: https://external.test;", $csp);
        self::assertStringContainsString("frame-src 'self' https://external.test", $csp);
    }

    public function testFormActionsOnlyAcceptValidHttpsOrigins(): void
    {
        $sources = (new SecurityPolicy(SecurityPolicy::defaults()))->previewSources();
        $csp = ResponseHeaders::build($sources, false, false, formActions: [
            'https://identity.example/authorize?x=1',
            'https://identity.example:443',
            'http://plain.example',
            "https://evil.example' unsafe-inline",
            'https:',
        ])['Content-Security-Policy'];

        // Les chemins sont réduits à l’origine et les valeurs invalides sont ignorées.
        self::assertStringContainsString("form-action 'self' https://identity.example;", $csp);
        self::assertStringNotContainsString('plain.example', $csp);
        self::assertStringNotContainsString('evil.example', $csp);
    }

    public function testFormDoesNotExposeEnabledAndRejectsUnknownFields(): void
    {
        $form = (new Plugin($this->createApplication()))->coreRegisterConfigForm();
        self::assertSame(\YTassy\Tycms\CoreExts\Security\Configuration::defaults(), (array) $form->getDefaultValues());
        $values = (object) \YTassy\Tycms\CoreExts\Security\Configuration::defaults();
        self::assertTrue($form->validateSubmittedValues($values)->isValid());
        $values->enabled = false;
        self::assertFalse($form->validateSubmittedValues($values)->isValid());
    }

    public function testListsAreBounded(): void
    {
        $this->expectException(RuntimeException::class);
        SecurityPolicy::origins(implode("\n", array_map(static fn ($i) => "https://site$i.test", range(1, 101))));
    }
}
