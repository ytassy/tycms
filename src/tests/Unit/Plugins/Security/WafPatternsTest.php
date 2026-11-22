<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use YTassy\Tycms\CoreExts\Security\Configuration;
use YTassy\Tycms\CoreExts\Security\Plugin;
use YTassy\Tycms\CoreExts\Security\Waf\BoundedRegex;
use YTassy\Tycms\CoreExts\Security\Waf\PatternScanner;
use YTassy\Tycms\CoreExts\Security\Waf\WafConfiguration;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class WafPatternsTest extends FrameworkTestCase
{
    private function patterns(array $strings = [], array $regexes = []): object
    {
        return WafConfiguration::patterns(json_encode(['strings' => $strings, 'regexes' => (object) $regexes], JSON_THROW_ON_ERROR));
    }

    public static function invalidConfigurations(): iterable
    {
        yield ['invalid json'];
        yield ['{"strings":[],"regexes":[],"php":"exec"}'];
        yield ['{"strings":[],"regexes":[]}'];
        yield ['{"strings":[""],"regexes":{}}'];
        yield ['{"strings":{},"regexes":{}}'];
        yield ['{"strings":[],"regexes":{"bad":"~[~"}}'];
        yield ['{"strings":[],"regexes":{"bad name":"~ok~"}}'];
        yield [json_encode(['strings' => array_fill(0, 51, 'forbidden'), 'regexes' => (object) []])];
        yield [json_encode(['strings' => [str_repeat('a', 257)], 'regexes' => (object) []])];
        yield [json_encode(['strings' => [], 'regexes' => (object) ['bad' => '~' . str_repeat('a', 1025) . '~']])];
        yield [str_repeat(' ', WafConfiguration::MAX_JSON_BYTES + 1)];
    }

    #[DataProvider('invalidConfigurations')]
    public function testInvalidPatternsAreRejectedBeforeSaving(string $json): void
    {
        $this->expectException(RuntimeException::class);
        WafConfiguration::patterns($json);
    }

    public function testLiteralMatchingIsCaseInsensitiveAndPreservesRequestAndSecrets(): void
    {
        $request = new Request(body: ['nested' => ['password' => 'secret-<?PHP-value']]);
        $before = $request->body;
        $event = (new PatternScanner())->scan($request, $this->patterns(['<?php']));
        self::assertSame('match', $event['kind']);
        self::assertSame('string_0', $event['pattern']);
        self::assertSame($before, $request->body);
        self::assertStringNotContainsString('password', json_encode($event));
        self::assertStringNotContainsString('secret', json_encode($event));
    }

    public function testKeysPathsAndOneDecodedVariantAreChecked(): void
    {
        $patterns = $this->patterns(['../']);
        foreach ([new Request(uri: '/%2e%2e%2ffile'), new Request(query: ['%2e%2e%2fkey' => 'normal']),
            new Request(body: ['field' => '%2e%2e%2fsecret'])] as $request) {
            self::assertSame('match', (new PatternScanner())->scan($request, $patterns)['kind']);
        }
        self::assertNull((new PatternScanner())->scan(new Request(body: ['field' => '%252e%252e%252f']), $patterns));
    }

    public function testRegexesAreNamedAndValuesAreNotConcatenated(): void
    {
        $patterns = $this->patterns([], ['sql_union' => '~\bunion\s+select\b~i']);
        $scanner = new PatternScanner();
        self::assertNull($scanner->scan(new Request(body: ['a' => 'union', 'b' => 'select']), $patterns));
        self::assertSame('sql_union', $scanner->scan(new Request(body: ['a' => 'UNION SELECT']), $patterns)['pattern']);
        self::assertNull($scanner->scan(new Request(body: ['a' => 'on sélectionne une union de groupes']), $patterns));
    }

    public static function defaultDangerousValues(): iterable
    {
        yield ['<?php system("id");'];
        yield ['php://filter/convert.base64-encode/resource=index.php'];
        yield ['data://text/plain;base64,SGVsbG8='];
        yield ['../../etc/passwd'];
        yield ['%2e%2e%2fetc%2fpasswd'];
        yield ['<script>alert(1)</script>'];
        yield ['onerror=alert(1)'];
        yield ["' OR 1=1 --"];
        yield ['O:8:"stdClass":1:{s:1:"a";i:1;}'];
        yield ['{{ config.items }}'];
        yield ['eval(base64_decode("ZWNobyAxOw=="));'];
        yield ['$_GET["cmd"]'];
        yield ["include('template.php');"];
        yield ['file_put_contents("/tmp/x", "y");'];
        yield ['$fn("value");'];
    }

    #[DataProvider('defaultDangerousValues')]
    public function testDefaultPatternsDetectCommonAttacks(string $value): void
    {
        $patterns = WafConfiguration::patterns(WafConfiguration::defaults()['waf_patterns']);
        $event = (new PatternScanner())->scan(new Request(body: ['value' => $value]), $patterns);
        self::assertSame('match', $event['kind'] ?? null, $value);
    }

    public static function defaultBenignValues(): iterable
    {
        yield ['on a = 1'];
        yield ['one = 1'];
        yield ['union de groupes'];
        yield ['javascript frameworks'];
        yield ['data:image/png;base64,iVBORw0KGgo='];
        yield ['O:8 n’est pas un objet sérialisé'];
    }

    #[DataProvider('defaultBenignValues')]
    public function testDefaultPatternsDoNotFlagObviousBenignValues(string $value): void
    {
        $patterns = WafConfiguration::patterns(WafConfiguration::defaults()['waf_patterns']);
        self::assertNull((new PatternScanner())->scan(new Request(body: ['value' => $value]), $patterns), $value);
    }

    public function testRegexErrorsCannotBecomeAnAbsenceOfDetectionAndSettingsAreRestored(): void
    {
        $settings = [ini_get('pcre.jit'), ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')];
        $patterns = $this->patterns([], ['utf8' => '~hello~u']);
        $event = (new PatternScanner())->scan(new Request(body: ['value' => "\xff"]), $patterns);
        self::assertSame('regex_error', $event['kind']);
        self::assertSame($settings, [ini_get('pcre.jit'), ini_get('pcre.backtrack_limit'), ini_get('pcre.recursion_limit')]);
        self::assertFalse(BoundedRegex::match('~^(a+)+$~', str_repeat('a', 100) . '!'));
    }

    public function testOversizedValuesAndDeepTreesStopWithAnExplicitLimit(): void
    {
        $patterns = $this->patterns(['forbidden']);
        $scanner = new PatternScanner();
        self::assertSame('limit', $scanner->scan(new Request(body: ['value' => str_repeat('a', PatternScanner::MAX_VALUE_BYTES + 1)]), $patterns)['kind']);
        $deep = ['value' => 'ordinary'];
        for ($i = 0; $i < 25; $i++) $deep = ['nested' => $deep];
        self::assertSame('limit', $scanner->scan(new Request(body: $deep), $patterns)['kind']);
        self::assertSame('limit', $scanner->scan(new Request(body: array_fill(0, 600, 'ordinary')), $patterns)['kind']);
        self::assertSame('limit', $scanner->scan(new Request(body: array_fill(0, 6, str_repeat('x', 110000))), $patterns)['kind']);
    }

    public function testCookiesHeadersAndUploadedFilesAreNotScanned(): void
    {
        $request = new Request(headers: ['X-Test' => 'forbidden'], cookies: ['token' => 'forbidden'], files: ['file' => ['name' => 'forbidden']]);
        self::assertNull((new PatternScanner())->scan($request, $this->patterns(['forbidden'])));
    }

    public function testFormExposesMessageJsonAndDeveloperDocumentation(): void
    {
        $form = (new Plugin($this->createApplication()))->coreRegisterConfigForm();
        self::assertSame(Configuration::defaults(), (array) $form->getDefaultValues());
        self::assertTrue($form->validateSubmittedValues((object) Configuration::defaults())->isValid());
        $definition = json_encode($form->getDefForRia());
        self::assertStringContainsString('SecurityDoNotCheckPatterns', $definition);
        self::assertStringContainsString('WAF_BLOCKED', $definition);
        self::assertStringContainsString('waf_message', $definition);
        self::assertStringContainsString('core.code', $definition);
        self::assertCount(1, array_filter($form->getDefinition()['sections'], static fn ($section) => $section['id'] === 'waf'));
        self::assertStringNotContainsString('waf_inheritance', $definition);
    }

    public function testRootCannotInheritItselfButOtherSpacesRetainTheirLocalSettings(): void
    {
        $plugin = new Plugin($this->createApplication());
        $values = [...Configuration::defaults(), 'waf_use_root' => true, 'waf_message' => 'Message local conservé.'];
        self::assertSame($values, $plugin->prepareConfigForStorage(2, $values));
        $this->expectException(RuntimeException::class);
        $plugin->prepareConfigForStorage(1, $values);
    }
}
