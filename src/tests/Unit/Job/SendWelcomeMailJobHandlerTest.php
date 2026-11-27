<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\UserWelcomeMailHelper;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobQueue;
use YTassy\Tycms\CoreExts\Core\Mail\SendMailHtmlJobHandler;
use YTassy\Tycms\CoreExts\Core\Mail\SendMailTemplateJobHandler;
use YTassy\Tycms\CoreExts\Core\Mail\SendWelcomeMailJobHandler;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SendWelcomeMailJobHandlerTest extends FrameworkTestCase
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

        $result = (new SendWelcomeMailJobHandler($app))->handle($this->context(), $payload);

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
        $request = SendWelcomeMailJobHandler::create(UserWelcomeMailHelper::TEMPLATE_LOCAL, 1200, 2, 'fr');

        self::assertSame(SendWelcomeMailJobHandler::JOB_TYPE, $request->type);
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
        $request = SendWelcomeMailJobHandler::create(UserWelcomeMailHelper::TEMPLATE_SSO, 1200, 2);

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

        SendWelcomeMailJobHandler::create(UserWelcomeMailHelper::TEMPLATE_ADMIN, 1200, 2);
    }

    /**
     * Le type porte la priorité : sans demande explicite, la fabrique ne fixe
     * rien, sinon le type ne serait plus la source de vérité.
     */
    public function testFactoryLeavesTheTypePriorityByDefault(): void
    {
        $request = SendWelcomeMailJobHandler::create(UserWelcomeMailHelper::TEMPLATE_LOCAL, 1200, 2);

        self::assertArrayNotHasKey('priority', $request->options);
    }

    public function testFactoryCanOverrideThePriority(): void
    {
        $request = SendWelcomeMailJobHandler::create(
            UserWelcomeMailHelper::TEMPLATE_LOCAL,
            1200,
            2,
            null,
            JobQueue::PRIORITY_LOW,
        );

        self::assertSame(JobQueue::PRIORITY_LOW, $request->options['priority']);
    }

    /** Les trois types d'e-mail Core classent leur envoi attendu. */
    public function testCoreMailTypesDeclareTheirPriority(): void
    {
        // La bienvenue est attendue par son destinataire ; les envois de
        // scénario peuvent patienter.
        self::assertSame(JobQueue::PRIORITY_HIGH, JobType::for(SendWelcomeMailJobHandler::class)->priority);
        self::assertSame(JobQueue::PRIORITY_LOW, JobType::for(SendMailTemplateJobHandler::class)->priority);
        self::assertSame(JobQueue::PRIORITY_LOW, JobType::for(SendMailHtmlJobHandler::class)->priority);
    }

    private function context(): JobContext
    {
        return new JobContext(
            jobId: 1,
            type: SendWelcomeMailJobHandler::JOB_TYPE,
            spaceId: 1,
            principal: 'none',
            execUserId: null,
            producer: 'plg_core',
            attempts: 1,
            maxAttempts: SendWelcomeMailJobHandler::JOB_MAX_ATTEMPTS,
        );
    }
}
