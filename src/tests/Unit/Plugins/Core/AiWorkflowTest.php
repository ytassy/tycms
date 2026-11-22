<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Ai\AiGatewayHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Ai\AiProviderHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Models\AiProvider;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\WF\WFAi;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AiWorkflowTest extends FrameworkTestCase
{
    public function testInfomaniakImagePromptLimitIsCheckedBeforeCallingTheProvider(): void
    {
        $app = $this->createApplication();
        $session = $this->createMock(Session::class);
        $session->expects(self::once())->method('getUserId')->willReturn(42);
        (new \ReflectionProperty(Application::class, 'session'))->setValue($app, $session);

        $space = new Space($app);
        $space->id = 7;
        $space->params = (object) ['ai_models' => (object) [
            'image_generation' => (object) ['provider_id' => 3, 'model' => 'flux'],
        ]];
        $provider = new AiProvider($app);
        $provider->id = 3;
        $provider->space_id = 7;
        $provider->type = 'infomaniak';
        $provider->enabled = true;
        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->expects(self::once())->method('getAllSpacesByIds')->willReturn([7 => $space]);
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::exactly(2))->method('hasGlobalPermission')->willReturn(true);
        $providers = $this->createMock(AiProviderHelper::class);
        $providers->expects(self::once())->method('get')->willReturn($provider);
        $gateway = $this->createMock(AiGatewayHelper::class);
        $gateway->expects(self::never())->method('run');

        $wf = new class($app, $spaces, $acl, $providers, $gateway) extends WFAi {
            public function __construct(Application $app, private SpaceHelper $spaces,
                private AclHelper $acl, private AiProviderHelper $providers,
                private AiGatewayHelper $gateway) { parent::__construct($app); }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    SpaceHelper::class => $this->spaces,
                    AclHelper::class => $this->acl,
                    AiProviderHelper::class => $this->providers,
                    AiGatewayHelper::class => $this->gateway,
                    default => parent::getSharedExtensionsClass($className),
                };
            }
        };

        $this->expectException(ExceptionResponse::class);
        $wf->run(7, 'image_generation', (object) ['prompt' => str_repeat('A', 401)]);
    }

    public function testToolSchemaKeepsEmptyPropertiesAsJsonObject(): void
    {
        $wf = new WFAi($this->createApplication());
        $input = json_decode('{"messages":[{"role":"user","content":"Corrige"}],"tools":[{"type":"function","function":{"name":"get_content","parameters":{"type":"object","properties":{},"additionalProperties":false}}}]}', false, 32, JSON_THROW_ON_ERROR);

        $payload = (new \ReflectionMethod(WFAi::class, 'validateInput'))->invoke($wf, 'llm', $input);
        $schema = json_encode($payload['tools'][0]['function']['parameters'], JSON_THROW_ON_ERROR);
        self::assertSame('{"type":"object","properties":{},"additionalProperties":false}', $schema);
    }

    public function testImageCapabilityAndExecutionRequireAnAssignedModelAndSpecificPermission(): void
    {
        $app = $this->createApplication();
        $app->getConf()->secret = str_repeat('s', 64);
        $session = $this->createMock(Session::class);
        $session->expects(self::atLeastOnce())->method('getUserId')->willReturn(42);
        (new \ReflectionProperty(Application::class, 'session'))->setValue($app, $session);

        $space = new Space($app);
        $space->id = 7;
        $space->params = (object) ['ai_models' => (object) []];
        $provider = new AiProvider($app);
        $provider->id = 3;
        $provider->space_id = 7;
        $provider->type = 'infomaniak';
        $provider->enabled = true;

        $aiUse = true;
        $imagePermission = false;
        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->expects(self::atLeastOnce())->method('getAllSpacesByIds')->willReturn([7 => $space]);
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::atLeastOnce())->method('hasGlobalPermission')->willReturnCallback(
            static function (string $name, int $spaceId) use (&$aiUse, &$imagePermission): bool {
                if ($spaceId !== 7) return false;
                return match ($name) {
                    Permissions::AI_USE => $aiUse,
                    Permissions::AI_GENERATE_IMAGE => $imagePermission,
                    default => false,
                };
            }
        );
        $providers = $this->createMock(AiProviderHelper::class);
        $providers->expects(self::atLeastOnce())->method('get')->with(3)->willReturn($provider);
        $gateway = $this->createMock(AiGatewayHelper::class);
        $gateway->expects(self::once())->method('run')->willReturn([
            'status' => 'pending', 'providerBatchId' => 'image-provider-batch',
        ]);
        $gateway->expects(self::never())->method('poll');

        $wf = new class($app, $spaces, $acl, $providers, $gateway) extends WFAi {
            public function __construct(Application $app, private SpaceHelper $spaces,
                private AclHelper $acl, private AiProviderHelper $providers,
                private AiGatewayHelper $gateway) { parent::__construct($app); }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    SpaceHelper::class => $this->spaces,
                    AclHelper::class => $this->acl,
                    AiProviderHelper::class => $this->providers,
                    AiGatewayHelper::class => $this->gateway,
                    default => parent::getSharedExtensionsClass($className),
                };
            }
        };

        self::assertSame(['imageGeneration' => false], $wf->getCapabilities(7));
        $imagePermission = true;
        self::assertSame(['imageGeneration' => false], $wf->getCapabilities(7));
        $space->params = (object) ['ai_models' => (object) [
            'image_generation' => (object) ['provider_id' => 3, 'model' => 'flux'],
        ]];
        $provider->enabled = false;
        self::assertSame(['imageGeneration' => false], $wf->getCapabilities(7));
        $provider->enabled = true;
        $provider->space_id = 8;
        self::assertSame(['imageGeneration' => false], $wf->getCapabilities(7));
        $provider->space_id = 7;
        $provider->type = 'anthropic';
        self::assertSame(['imageGeneration' => false], $wf->getCapabilities(7));
        $provider->type = 'infomaniak';
        self::assertSame(['imageGeneration' => true], $wf->getCapabilities(7));

        $imagePermission = false;
        try {
            $wf->run(7, 'image_generation', (object) ['prompt' => 'Un paysage']);
            self::fail('La génération doit être refusée sans AI_GENERATE_IMAGE.');
        } catch (ExceptionResponse) {
            self::assertSame(['imageGeneration' => false], $wf->getCapabilities(7));
        }
        $imagePermission = true;
        $started = $wf->run(7, 'image_generation', (object) ['prompt' => 'Un paysage']);
        self::assertSame('pending', $started['status']);
        $imagePermission = false;
        try {
            $wf->poll($started['batchId']);
            self::fail('Le suivi doit être refusé après révocation du droit image.');
        } catch (ExceptionResponse) {
            $aiUse = false;
        }
        $this->expectException(ExceptionResponse::class);
        $wf->getCapabilities(7);
    }

    public function testEncryptedBatchCanOnlyBePolledByItsUserWithCurrentSpacePermission(): void
    {
        $app = $this->createApplication();
        $app->getConf()->secret = str_repeat('s', 64);
        $userId = 42;
        $permission = true;
        $session = $this->createMock(Session::class);
        $session->expects(self::atLeastOnce())->method('getUserId')->willReturnCallback(static function () use (&$userId): int { return $userId; });
        (new \ReflectionProperty(Application::class, 'session'))->setValue($app, $session);

        $space = new Space($app);
        $space->id = 7;
        $space->params = (object) ['ai_models' => (object) [
            'design' => (object) ['provider_id' => 3, 'model' => 'test-model'],
        ]];
        $provider = new AiProvider($app);
        $provider->id = 3;
        $provider->space_id = 7;
        $provider->type = 'infomaniak';
        $provider->enabled = true;

        $spaces = $this->createMock(SpaceHelper::class);
        $spaces->expects(self::atLeastOnce())->method('getAllSpacesByIds')->willReturn([7 => $space]);
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::atLeastOnce())->method('hasGlobalPermission')->willReturnCallback(
            static function (string $name, int $spaceId) use (&$permission): bool {
                return $permission && $name === Permissions::AI_USE && $spaceId === 7;
            }
        );
        $providers = $this->createMock(AiProviderHelper::class);
        $providers->expects(self::atLeastOnce())->method('get')->with(3)->willReturn($provider);
        $gateway = $this->createMock(AiGatewayHelper::class);
        $gateway->expects(self::once())->method('run')->willReturn([
            'status' => 'pending', 'providerBatchId' => 'provider-secret-id',
        ]);
        $gateway->expects(self::once())->method('poll')->with($provider, 'provider-secret-id', 'llm')
            ->willReturn(['status' => 'completed', 'result' => 'Bonjour']);

        $wf = new class($app, $spaces, $acl, $providers, $gateway) extends WFAi {
            public function __construct(
                Application $app,
                private SpaceHelper $spaces,
                private AclHelper $acl,
                private AiProviderHelper $providers,
                private AiGatewayHelper $gateway,
            ) { parent::__construct($app); }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    SpaceHelper::class => $this->spaces,
                    AclHelper::class => $this->acl,
                    AiProviderHelper::class => $this->providers,
                    AiGatewayHelper::class => $this->gateway,
                    CryptHelper::class => parent::getSharedExtensionsClass($className),
                    default => parent::getSharedExtensionsClass($className),
                };
            }
        };

        $started = $wf->run(7, 'design', (object) ['messages' => [(object) ['role' => 'user', 'content' => 'Bonjour']]]);
        self::assertSame('pending', $started['status']);
        self::assertStringStartsWith('tycmscrypt:v1:', $started['batchId']);
        self::assertStringNotContainsString('provider-secret-id', $started['batchId']);
        self::assertSame('Bonjour', $wf->poll($started['batchId'])['result']);

        $userId = 99;
        try {
            $wf->poll($started['batchId']);
            self::fail('Un autre utilisateur ne peut pas consulter le batch.');
        } catch (ExceptionResponse) {
            $userId = 42;
        }
        $permission = false;
        $this->expectException(ExceptionResponse::class);
        $wf->poll($started['batchId']);
    }
}
