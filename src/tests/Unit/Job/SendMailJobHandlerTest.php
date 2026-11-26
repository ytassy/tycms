<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\UserWelcomeMailHelper;
use YTassy\Tycms\CoreExts\Core\Mail\SendMailJobHandler;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SendMailJobHandlerTest extends FrameworkTestCase
{
    #[DataProvider('invalidPayloads')]
    public function testInvalidPayloadIsRefusedWithoutTouchingTheDatabase(array $payload): void
    {
        $app = $this->createApplication();
        $db = $this->createMock(Database::class);

        // Le refus intervient avant toute lecture : un payload douteux ne doit
        // pas déclencher de requête, encore moins d'envoi.
        $db->expects(self::never())->method('selectOneOrNull');
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $result = (new SendMailJobHandler($app))->handle($this->context(), $payload);

        self::assertFalse($result->success);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidPayloads(): iterable
    {
        yield 'payload vide' => [[]];

        yield 'modèle inconnu' => [[
            'template' => 'plg_core__inconnu',
            'user_id' => 1200,
            'space_id' => 1,
        ]];

        // Ce modèle existe mais porte le mot de passe initial : la file ne doit
        // jamais l'accepter, même si un producteur le demandait.
        yield 'modèle porteur du mot de passe initial' => [[
            'template' => UserWelcomeMailHelper::TEMPLATE_ADMIN,
            'user_id' => 1200,
            'space_id' => 1,
        ]];

        yield 'compte absent' => [[
            'template' => UserWelcomeMailHelper::TEMPLATE_LOCAL,
            'space_id' => 1,
        ]];

        yield 'espace absent' => [[
            'template' => UserWelcomeMailHelper::TEMPLATE_LOCAL,
            'user_id' => 1200,
        ]];
    }

    public function testFactoryCarriesReferencesOnly(): void
    {
        $request = SendMailJobHandler::create(UserWelcomeMailHelper::TEMPLATE_LOCAL, 1200, 2, 'fr');

        self::assertSame(SendMailJobHandler::JOB_TYPE, $request->type);
        self::assertSame(2, $request->options['space_id']);
        self::assertSame(
            'welcome:' . UserWelcomeMailHelper::TEMPLATE_LOCAL . ':1200',
            $request->options['dedup_key'],
        );

        // Le payload ne transporte aucune donnée de compte : ni adresse, ni
        // prénom, encore moins un mot de passe.
        self::assertSame(['template', 'user_id', 'space_id', 'locale'], array_keys($request->payload));
        self::assertSame(1200, $request->payload['user_id']);
        self::assertSame(2, $request->payload['space_id']);
    }

    public function testFactoryDistinguishesTheSsoWelcome(): void
    {
        $request = SendMailJobHandler::create(UserWelcomeMailHelper::TEMPLATE_SSO, 1200, 2);

        self::assertSame(UserWelcomeMailHelper::TEMPLATE_SSO, $request->payload['template']);
        self::assertNull($request->payload['locale']);
        self::assertStringContainsString(
            'registration_sso_welcome',
            (string) $request->options['dedup_key'],
        );
    }

    public function testFactoryRefusesATemplateThatCarriesAPassword(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non diffusable');

        SendMailJobHandler::create(UserWelcomeMailHelper::TEMPLATE_ADMIN, 1200, 2);
    }

    private function context(): JobContext
    {
        return new JobContext(
            jobId: 1,
            type: SendMailJobHandler::JOB_TYPE,
            spaceId: 1,
            principal: 'none',
            execUserId: null,
            producer: 'plg_core',
            attempts: 1,
            maxAttempts: SendMailJobHandler::JOB_MAX_ATTEMPTS,
        );
    }
}
