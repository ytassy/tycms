<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\SendMailAction;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobTypeRegistry;
use YTassy\Tycms\CoreExts\Core\Mail\SendMailHtmlJobHandler;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Application;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SendMailActionTest extends FrameworkTestCase
{
    #[Test]
    public function purifiesTheBodyBeforeEnqueueingIt(): void
    {
        $app = $this->createApplication();
        $inserted = null;

        // L'action ne parle plus au SMTP : elle écrit une ligne de file.
        $db = $this->createMock(Database::class);
        $db->expects(self::once())
            ->method('execute')
            ->willReturnCallback(static function (string $query, ?array $values) use (&$inserted): int {
                $inserted = $values;

                return 1;
            });
        new \ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        /** @var JobTypeRegistry $registry */
        $registry = $app->getOrAddSharedExtensionsClass(JobTypeRegistry::class);
        $registry->register(JobType::for(SendMailHtmlJobHandler::class));

        $result = (new SendMailAction($app))->execute($this->context(), [
            'to' => 'alice@example.test',
            'subject' => 'Bienvenue',
            'htmlBody' => '<p>Bonjour</p><script>alert(1)</script>'
                . '<iframe src="https://exemple.test"></iframe>'
                . '<a href="javascript:alert(1)">lien</a>',
        ]);

        self::assertTrue($result);
        self::assertNotNull($inserted);

        // Colonnes : space_id, type, producteur, payload…
        self::assertSame(7, $inserted[0]);
        self::assertSame(SendMailHtmlJobHandler::JOB_TYPE, $inserted[1]);

        $payload = json_decode((string) $inserted[3], true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('alice@example.test', $payload['to']);
        self::assertSame('Bienvenue', $payload['subject']);

        // Les balises actives et les schémas de lien dangereux sont retirés
        // avant l'écriture : la ligne de file ne contient pas de HTML actif.
        self::assertStringContainsString('<p>Bonjour</p>', $payload['html']);
        self::assertStringNotContainsString('<script', $payload['html']);
        self::assertStringNotContainsString('<iframe', $payload['html']);
        self::assertStringNotContainsString('javascript:', $payload['html']);
    }

    #[Test]
    public function refusesABodyThatIsEmptyAfterPurification(): void
    {
        $app = $this->createApplication();
        $messages = [];
        $this->replaceMailHelper($app, $messages);

        $this->assertDiagnostic($app, [
            'to' => 'alice@example.test',
            'subject' => 'Bienvenue',
            'htmlBody' => '<script>alert(1)</script>',
        ], DiagnosticCode::INVALID_ARGUMENT);

        self::assertSame([], $messages);
    }

    #[Test]
    public function refusesAMultiLineSubject(): void
    {
        $app = $this->createApplication();
        $messages = [];
        $this->replaceMailHelper($app, $messages);

        $this->assertDiagnostic($app, [
            'to' => 'alice@example.test',
            'subject' => "Bienvenue\r\nBcc: intrus@example.test",
            'htmlBody' => '<p>Bonjour</p>',
        ], DiagnosticCode::INVALID_ARGUMENT);

        self::assertSame([], $messages);
    }

    private function assertDiagnostic(Application $app, array $params, string $code): void
    {
        try {
            (new SendMailAction($app))->execute($this->context(), $params);
            self::fail('L’action devait refuser ces paramètres.');
        } catch (ExecutionException $exception) {
            self::assertSame($code, $exception->diagnostic->code);
        }
    }

    /** @param list<array<string, mixed>> $messages */
    private function replaceMailHelper(Application $app, array &$messages): void
    {
        $mailer = new class($app, $messages) extends MailHelper {
            /** @var list<array<string, mixed>> */
            private array $messages;

            /** @param list<array<string, mixed>> $messages */
            public function __construct(Application $app, array &$messages)
            {
                parent::__construct($app);
                $this->messages = &$messages;
            }

            public function sendHtml(
                string|array $to,
                string $subject,
                string $html,
                Space|int|null $sourceSpace = null,
                string|array $cc = [],
                string|array $bcc = [],
                string|array $replyTo = [],
                array $attachments = [],
                string $renderMode = 'default',
                ?string $emailLocale = null,
                array $secrets = [],
                ?int $jobId = null,
                ?int $userId = null,
            ): bool {
                $this->messages[] = [
                    'to' => $to,
                    'subject' => $subject,
                    'html' => $html,
                    'sourceSpace' => $sourceSpace,
                    'renderMode' => $renderMode,
                ];

                return true;
            }
        };

        // Remplacer uniquement le transport : le reste du helper reste intact.
        $property = new \ReflectionProperty(Application::class, 'sharedExtensionsClasses');
        $shared = $property->getValue($app);
        $shared[MailHelper::class] = $mailer;
        $property->setValue($app, $shared);
    }

    private function context(): ActionContext
    {
        $position = new SourcePosition(0, 1, 1);

        return new ActionContext(
            principalType: 'user',
            principalId: 42,
            spaceId: 7,
            runId: 10,
            scenarioId: 1,
            versionId: 1,
            span: new SourceSpan($position, $position),
        );
    }
}