<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\DiagramShortCode;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\ShortCodeHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Config\FormsHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Helper\UserHelper;
use YTassy\Tycms\CoreExts\Core\Models\User;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ShortCodeTest extends FrameworkTestCase
{
    private function helper(?User $user = null): ShortCodeTestHelper
    {
        $app = $this->createApplication();
        $helper = new ShortCodeTestHelper($app);
        $users = $this->createStub(UserHelper::class);
        $users->method('getUser')->willReturn($user);
        $helper->services = [UserHelper::class => $users, CryptHelper::class => new CryptHelper($app)];
        return $helper;
    }

    public function testConnectedUserValuesAreEscapedAndNameCombinesBothParts(): void
    {
        $user = new User($this->createApplication());
        $user->id = 12;
        $user->first_name = ' <Ada> ';
        $user->last_name = ' Lovelace ';
        $user->email = 'ada@example.test';
        $html = $this->helper($user)->applyShortCodes('<p>[[USER_name]] / [[USER_id]] / [[USER_email]]</p>', 7);
        self::assertSame('<p>&lt;Ada&gt; Lovelace / 12 / ada@example.test</p>', $html);
    }

    public function testAnonymousUserShortcodesAreEmpty(): void
    {
        self::assertSame('<p>//</p>', $this->helper()->applyShortCodes('<p>[[USER_name]]/[[USER_id]]/[[USER_email]]</p>', 7));
    }

    public function testAttributesCodeCommentsAndUnknownShortcodesStayLiteral(): void
    {
        $source = '<p title="[[USER_name]]">[[VAR_test]] {{USER_name}}</p>'
            . '<pre>[[USER_id]]</pre><code>[[USER_name]]</code><!-- [[USER_email]] -->';
        $html = $this->helper()->applyShortCodes($source, 7);
        self::assertStringContainsString('title="[[USER_name]]"', $html);
        self::assertStringContainsString('[[VAR_test]] {{USER_name}}', $html);
        self::assertStringContainsString('<pre>[[USER_id]]</pre>', $html);
        self::assertStringContainsString('<code>[[USER_name]]</code>', $html);
        self::assertStringContainsString('<!-- [[USER_email]] -->', $html);
    }

    public function testDiagramKeepsIndentationAndDoesNotExpandUserInsideItsSource(): void
    {
        $helper = $this->helper();
        $helper->params = (object) ['mermaid_default_theme' => 'forest', 'mermaid_default_sketch' => '0'];
        $html = $helper->applyShortCodes('<p>[[DIAGRAM,width=600px]]</p><p>mindmap</p>'
            . '<p>&nbsp; root(([[USER_name]]))</p><p>[[/DIAGRAM]]</p>', 7);
        self::assertStringContainsString('data-shortcode-diagram="1"', $html);
        self::assertStringContainsString('data-mermaid-theme="forest"', $html);
        self::assertStringContainsString('width:600px', $html);
        self::assertStringContainsString("mindmap\n  root(([[USER_name]]))", $html);
        self::assertSame($html, $helper->applyShortCodes($html, 7));
    }

    public function testDiagramRejectsUnknownAndRepeatedOptions(): void
    {
        foreach (['space=2', 'width=10px,width=20px', 'theme=invalid', 'wrapwidth=1'] as $options) {
            $html = DiagramShortCode::replace('[[DIAGRAM,' . $options . ']]flowchart LR A-->B[[/DIAGRAM]]');
            self::assertStringContainsString('Impossible de préparer', $html);
            self::assertStringNotContainsString('data-shortcode-diagram', $html);
        }
    }

    public function testEachDiagramHasADistinctIdAndEscapedSource(): void
    {
        $html = $this->helper()->applyShortCodes('[[DIAGRAM]]flowchart LR A[&lt;img src=x onerror=alert(1)&gt;]--&gt;B[[/DIAGRAM]]'
            . '[[DIAGRAM]]flowchart LR C--&gt;D[[/DIAGRAM]]', 7);
        preg_match_all('/id="(tytools-mermaid-[a-f0-9]+)"/', $html, $matches);
        self::assertCount(2, array_unique($matches[1]));
        self::assertStringContainsString('&lt;img', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    public function testBunnyTokensAreStoredAsJsonAndPreservedForTheAuthorizedEditor(): void
    {
        $helper = $this->helper();
        $helper->params = (object) [];
        $saved = $helper->prepareConfiguration(7, ['video_bunny_tokens' => '{"123":"test-secret"}']);
        self::assertSame('{"123":"test-secret"}', $saved['video_bunny_tokens']);
        self::assertArrayNotHasKey('video_bunny_tokens_encrypted', $saved);
        $helper->params = (object) $saved;
        self::assertSame($saved, $helper->prepareConfiguration(7, ['video_bunny_tokens' => '']));
        $editable = $helper->configurationForEditor(7, $saved);
        self::assertArrayNotHasKey('video_bunny_tokens_encrypted', $editable);
        self::assertSame(['123' => 'test-secret'], json_decode($editable['video_bunny_tokens'], true, 32, JSON_THROW_ON_ERROR));
        self::assertSame('{}', $helper->prepareConfiguration(7, ['video_bunny_tokens' => '{}'])['video_bunny_tokens']);
    }

    public function testPreviouslyEncryptedBunnyTokensAreReadableAndMigrated(): void
    {
        $helper = $this->helper();
        $legacy = ['video_bunny_tokens_encrypted' => $helper->services[CryptHelper::class]
            ->encrypt('{"123":"test-secret"}', 'shortcodes:bunny:7')];
        $helper->params = (object) $legacy;

        $editable = $helper->configurationForEditor(7, $legacy);
        self::assertSame(['123' => 'test-secret'], json_decode($editable['video_bunny_tokens'], true, 32, JSON_THROW_ON_ERROR));
        self::assertSame('{"123":"test-secret"}', $helper->prepareConfiguration(7, ['video_bunny_tokens' => ''])['video_bunny_tokens']);
        self::assertStringContainsString('token=', $helper->applyShortCodes('[[VIDEO, bunny:123/abc-def]]', 7));
    }

    public function testBunnySignatureUsesOnlyTheConfiguredLibraryAndNeverLeaksItsSecret(): void
    {
        $helper = $this->helper();
        $helper->params = (object) $helper->prepareConfiguration(7, ['video_bunny_tokens' => '{"123":"test-secret"}']);
        $html = $helper->applyShortCodes('[[VIDEO, bunny:123/abc-def]]', 7);
        self::assertStringNotContainsString('test-secret', $html);
        preg_match('/token=([a-f0-9]+)&amp;expires=([0-9]+)/', $html, $matches);
        self::assertSame(hash('sha256', 'test-secret' . 'abc-def' . $matches[2]), $matches[1]);
        self::assertStringNotContainsString('token=', $helper->applyShortCodes('[[VIDEO, bunny:456/abc-def]]', 7));
        self::assertStringContainsString('[[VIDEO, bunny:123/abc" onload=bad]]', $helper->applyShortCodes('[[VIDEO, bunny:123/abc" onload=bad]]', 7));
    }

    public function testFormProvidesOnlyPublicDefaultsAndKeepsSecretsEmpty(): void
    {
        $form = (new FormsHelper($this->createApplication()))->getConfigForm();
        $defaults = $form->getDefaultValues();
        self::assertSame('95%', $defaults['mermaid_default_width']);
        self::assertSame('', $defaults['video_bunny_tokens']);
        self::assertTrue($form->validateSubmittedValues((object) $defaults)->isValid());
        $defaults['mermaid_default_wrapwidth'] = 40.5;
        self::assertFalse($form->validateSubmittedValues((object) $defaults)->isValid());
    }
}

class ShortCodeTestHelper extends ShortCodeHelper
{
    public object $params;
    public array $services = [];

    public function configuration(int $spaceId): object { return $this->params ?? (object) []; }
    public function getSharedExtensionsClass(string $className): BaseApplicationItem { return $this->services[$className]; }
}
