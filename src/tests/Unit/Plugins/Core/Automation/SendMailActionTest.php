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
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Application;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SendMailActionTest extends FrameworkTestCase
{
    #[Test]
    public function purifiesTheBodyAndSendsThroughTheSpaceLayout(): void
    {
        $app = $this->createApplication();
        $messages = [];
        $this->replaceMailHelper($app, $messages);

        $result = (new SendMailAction($app))->execute($this->context(), [
            'to' => 'alice@example.test',
            'subject' => 'Bienvenue',
            'htmlBody' => '<p>Bonjour</p><script>alert(1)</script>'
                . '<iframe src="https://exemple.test"></iframe>'
                . '<a href="javascript:alert(1)">lien</a>',
        ]);

        self::assertTrue($result);
        self::assertCount(1, $messages);

        $message = $messages[0];
        self::assertSame('alice@example.test', $message['to']);
        self::assertSame('Bienvenue', $message['subject']);
        self::assertSame(7, $message['sourceSpace']);
        self::assertSame('default', $message['renderMode']);

        // Les balises actives et les schémas de lien dangereux sont retirés.
        self::assertStringContainsString('<p>Bonjour</p>', $message['html']);
        self::assertStringNotContainsString('<script', $message['html']);
        self::assertStringNotContainsString('<iframe', $message['html']);
        self::assertStringNotContainsString('javascript:', $message['html']);
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