<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Ai\AiGatewayHelper as CoreAiGatewayHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Models\AiProvider;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AiGatewayTest extends FrameworkTestCase
{
    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl+G0YAAAAASUVORK5CYII=';

    public function testInfomaniakUsesVersionTwoForChatAndVersionOneForImageAndStatus(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'infomaniak', 'https://api.infomaniak.com', ['product_id' => 123]);
        $gateway = $this->gateway($app, [
            ['choices' => [['message' => ['role' => 'assistant', 'content' => 'Bonjour']]]],
            ['data' => [['b64_json' => self::PNG_BASE64]]],
            ['status' => 'success', 'data' => 'Terminé'],
        ]);

        self::assertSame('Bonjour', $gateway->run($provider, 'llm', 'qwen3', [
            'messages' => [['role' => 'user', 'content' => 'Salut']],
        ])['result']['message']['content']);
        self::assertSame(self::PNG_BASE64, $gateway->run($provider, 'image', 'flux', ['prompt' => 'Un chat'])['result']['images'][0]['b64_json']);
        self::assertSame('Terminé', $gateway->poll($provider, 'batch-1', 'llm')['result']);
        self::assertSame('https://api.infomaniak.com/2/ai/123/openai/v1/chat/completions', $gateway->requests[0]['url']);
        self::assertSame('https://api.infomaniak.com/1/ai/123/openai/images/generations', $gateway->requests[1]['url']);
        self::assertSame('https://api.infomaniak.com/1/ai/123/results/batch-1', $gateway->requests[2]['url']);
    }

    public function testInfomaniakPendingResultIsReturnedForStatelessContinuation(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'infomaniak', 'https://api.infomaniak.com/1/ai', ['product_id' => 7]);
        $gateway = $this->gateway($app, [['batch_id' => 'abc-123'], ['status' => 'processing']]);

        self::assertSame(['status' => 'pending', 'providerBatchId' => 'abc-123'],
            $gateway->run($provider, 'image', 'flux', ['prompt' => 'Paysage']));
        self::assertSame(['status' => 'pending'], $gateway->poll($provider, 'abc-123', 'image'));
    }

    public function testDecisionUsesTheTypedOpenRouterEndpoint(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'openrouter', 'https://openrouter.ai/api/v1');
        $gateway = $this->gateway($app, [['answers' => ['route' => ['type' => 'choice', 'choice' => 'design']]]]);

        $response = $gateway->run($provider, 'decision', 'typesafe/jev-1.14', [
            'state' => ['request' => 'Créer une page'],
            'questions' => ['route' => ['type' => 'choice', 'instructions' => 'Choisir', 'criteria' => ['design' => 'Page']]],
        ]);
        self::assertSame('design', $response['result']['answers']['route']['choice']);
        self::assertSame('https://openrouter.ai/api/alpha/decisions', $gateway->requests[0]['url']);
        self::assertSame('typesafe/jev-1.14', $gateway->requests[0]['body']['model']);
    }

    public function testOpenRouterUsesChatCompletionsForLlmAndImageApiForImages(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'openrouter', 'https://openrouter.ai/api/v1');
        $gateway = $this->gateway($app, [
            ['choices' => [['message' => ['role' => 'assistant', 'content' => null,
                'tool_calls' => [['id' => 'call-1', 'type' => 'function',
                    'function' => ['name' => 'get_content', 'arguments' => '{}']]]]]]],
            ['data' => [['b64_json' => self::PNG_BASE64, 'media_type' => 'image/png']]],
        ]);

        $messages = [['role' => 'user', 'content' => 'Corrige ce texte']];
        $tools = [['type' => 'function', 'function' => [
            'name' => 'get_content', 'description' => 'Lit le contenu',
            'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
        ]]];
        $llm = $gateway->run($provider, 'llm', 'mistralai/example-model', [
            'messages' => $messages, 'tools' => $tools,
        ]);
        self::assertSame('get_content', $llm['result']['message']['tool_calls'][0]['function']['name']);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $gateway->requests[0]['url']);
        self::assertSame('mistralai/example-model', $gateway->requests[0]['body']['model']);
        self::assertSame($messages, $gateway->requests[0]['body']['messages']);
        self::assertSame($tools, $gateway->requests[0]['body']['tools']);
        self::assertFalse($gateway->requests[0]['body']['stream']);
        self::assertContains('Authorization: Bearer test-key', $gateway->requests[0]['headers']);

        $image = $gateway->run($provider, 'image', 'image/example-model', ['prompt' => 'Un paysage']);
        self::assertSame(self::PNG_BASE64, $image['result']['images'][0]['b64_json']);
        self::assertSame('image/png', $image['result']['images'][0]['media_type']);
        self::assertSame('https://openrouter.ai/api/v1/images', $gateway->requests[1]['url']);
        self::assertSame(['model' => 'image/example-model', 'prompt' => 'Un paysage'], $gateway->requests[1]['body']);
    }

    public function testDeferredImageUsesTheSameValidatedShapeAsAnImmediateImage(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'infomaniak', 'https://api.infomaniak.com', ['product_id' => 123]);
        $gateway = $this->gateway($app, [['status' => 'success',
            'data' => json_encode(['data' => [['b64_json' => self::PNG_BASE64]]], JSON_THROW_ON_ERROR)]]);

        $result = $gateway->poll($provider, 'batch-image', 'image');
        self::assertSame('completed', $result['status']);
        self::assertSame(self::PNG_BASE64, $result['result']['images'][0]['b64_json']);
        self::assertSame('image/png', $result['result']['images'][0]['media_type']);
    }

    public function testUrlOnlyImageIsRejected(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'openrouter', 'https://openrouter.ai/api/v1');
        $gateway = $this->gateway($app, [['data' => [['url' => 'https://example.org/image.png']]]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('seule une image base64');
        $gateway->run($provider, 'image', 'image-model', ['prompt' => 'Une image']);
    }

    public function testInvalidBase64ImageIsRejected(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'openrouter', 'https://openrouter.ai/api/v1');
        $gateway = $this->gateway($app, [['data' => [['b64_json' => 'not-an-image']]]]);
        $this->expectException(RuntimeException::class);
        $gateway->run($provider, 'image', 'image-model', ['prompt' => 'Une image']);
    }

    public function testAnthropicToolUseIsNormalisedForVue(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'anthropic', 'https://api.anthropic.com/v1');
        $gateway = $this->gateway($app, [[
            'content' => [['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'replace_text', 'input' => ['text' => 'Bonjour']]],
        ]]);

        $response = $gateway->run($provider, 'llm', 'claude-example', [
            'messages' => [['role' => 'user', 'content' => 'Corrige']],
            'tools' => [['type' => 'function', 'function' => [
                'name' => 'replace_text', 'description' => 'Remplace un texte',
                'parameters' => ['type' => 'object', 'properties' => ['text' => ['type' => 'string']]],
            ]]],
        ]);
        self::assertSame('replace_text', $response['result']['message']['tool_calls'][0]['function']['name']);
        self::assertSame('https://api.anthropic.com/v1/messages', $gateway->requests[0]['url']);
        self::assertSame('replace_text', $gateway->requests[0]['body']['tools'][0]['name']);
    }

    public function testInfomaniakVersionTwoPassesToolsToChat(): void
    {
        $app = $this->application();
        $provider = $this->provider($app, 'infomaniak', 'https://api.infomaniak.com', ['product_id' => 7]);
        $gateway = $this->gateway($app, [['choices' => [['message' => ['role' => 'assistant', 'content' => null,
            'tool_calls' => [['id' => 'call-1', 'type' => 'function', 'function' => ['name' => 'save', 'arguments' => '{}']]]]]]]]);
        $gateway->run($provider, 'llm', 'qwen3', [
            'messages' => [['role' => 'user', 'content' => 'Bonjour']],
            'tools' => [['type' => 'function', 'function' => ['name' => 'save', 'parameters' => ['type' => 'object']]]],
        ]);
        self::assertSame('https://api.infomaniak.com/2/ai/7/openai/v1/chat/completions', $gateway->requests[0]['url']);
        self::assertSame('save', $gateway->requests[0]['body']['tools'][0]['function']['name']);
    }

    private function application(): Application
    {
        $app = $this->createApplication();
        $app->getConf()->secret = str_repeat('k', 64);
        return $app;
    }

    /** @param array<string,mixed> $config */
    private function provider(Application $app, string $type, string $baseUrl, array $config = []): AiProvider
    {
        $provider = new AiProvider($app);
        $provider->id = 1;
        $provider->space_id = 2;
        $provider->name = 'Essai';
        $provider->type = $type;
        $provider->base_url = $baseUrl;
        $provider->enabled = true;
        $provider->config = json_encode($config, JSON_THROW_ON_ERROR);
        $provider->secret_data = (new CryptHelper($app))->encrypt('test-key', 'ai-provider-key:v1');
        return $provider;
    }

    /** @param list<array<string,mixed>> $replies */
    private function gateway(Application $app, array $replies): CoreAiGatewayHelper
    {
        return new class($app, $replies) extends CoreAiGatewayHelper {
            /** @var list<array<string,mixed>> */
            public array $requests = [];

            /** @param list<array<string,mixed>> $replies */
            public function __construct(Application $app, private array $replies)
            {
                parent::__construct($app);
            }

            protected function request(string $method, string $url, array $headers, ?array $body = null): array
            {
                $this->requests[] = compact('method', 'url', 'headers', 'body');
                return array_shift($this->replies) ?? [];
            }
        };
    }
}
