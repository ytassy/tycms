<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use InvalidArgumentException;
use PHPMailer\PHPMailer\PHPMailer;
use YTassy\Tycms\Exceptions\ApplicationException;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailAdminHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailLogHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Template\TemplateHelper;
use YTassy\Tycms\CoreExts\Core\Models\Config;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\MainTemplate\Template as MainTemplate;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class MailHelperTest extends FrameworkTestCase
{
    public function testCurrentSpaceConfiguresHtmlAndAllAddressTypes(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $space, [$space->id => $space], $mailer);

        self::assertTrue($helper->sendRawHtml(
            ['to@example.test' => 'Destinataire', 'second@example.test'],
            'Sujet éè',
            '<p>Bonjour <strong>éè</strong></p>',
            cc: 'cc@example.test',
            bcc: ['bcc@example.test'],
            replyTo: ['reply@example.test' => 'Réponse'],
            attachments: [['path' => __FILE__, 'name' => 'preuve.php']],
        ));

        self::assertTrue($mailer->sent);
        self::assertSame('smtp.example.test', $mailer->Host);
        self::assertSame(465, $mailer->Port);
        self::assertSame(4, $mailer->Timeout);
        self::assertSame(PHPMailer::ENCRYPTION_SMTPS, $mailer->SMTPSecure);
        self::assertFalse($mailer->SMTPAutoTLS);
        self::assertTrue($mailer->SMTPAuth);
        self::assertSame('smtp-user', $mailer->Username);
        self::assertSame('smtp-pass', $mailer->Password);
        self::assertSame(PHPMailer::CHARSET_UTF8, $mailer->CharSet);
        self::assertSame('source@example.test', $mailer->From);
        self::assertSame('Source', $mailer->FromName);
        self::assertSame('Sujet éè', $mailer->Subject);
        self::assertSame('<p>Bonjour <strong>éè</strong></p>', $mailer->Body);
        self::assertNotEmpty($mailer->AltBody);
        self::assertCount(2, $mailer->getToAddresses());
        self::assertCount(1, $mailer->getCcAddresses());
        self::assertCount(1, $mailer->getBccAddresses());
        self::assertCount(1, $mailer->getReplyToAddresses());
        self::assertCount(1, $mailer->getAttachments());
    }

    public function testExplicitSpaceAndDisabledSending(): void
    {
        $app = $this->createMailApplication();
        $current = $this->space($app, 1);
        $source = $this->space($app, 2);
        $source->params = array_merge((array) $source->params, [
            'mail_smtp_host' => 'other.example.test',
            'mail_smtp_secure' => 'tls',
            'mail_smtp_port' => 587,
            'mail_smtp_auth' => false,
        ]);
        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $current, [1 => $current, 2 => $source], $mailer);

        self::assertTrue($helper->sendRawHtml('to@example.test', 'Sujet', '<p>HTML</p>', 2));
        self::assertSame('other.example.test', $mailer->Host);
        self::assertSame(PHPMailer::ENCRYPTION_STARTTLS, $mailer->SMTPSecure);
        self::assertFalse($mailer->SMTPAuth);

        $source->params = (object) array_merge($source->params, ['mail_smtp_secure' => '', 'mail_smtp_port' => 25]);
        $plainMailer = new RecordingMailer();
        $helper = $this->helper($app, $current, [1 => $current, 2 => $source], $plainMailer);
        self::assertTrue($helper->sendRawHtml('to@example.test', 'Sujet', '<p>HTML</p>', $source));
        self::assertSame('', $plainMailer->SMTPSecure);
        self::assertFalse($plainMailer->SMTPAutoTLS);

        $source->params = (object) array_merge((array) $source->params, ['mail_online' => false]);
        $disabledMailer = new RecordingMailer();
        $helper = $this->helper($app, $current, [1 => $current, 2 => $source], $disabledMailer);
        self::assertFalse($helper->sendHtml('to@example.test', 'Sujet', '<p>HTML</p>', $source));
        self::assertFalse($disabledMailer->sent);
    }

    public function testUnknownSpaceAndIncompleteConfigurationFailBeforeSending(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $space, [1 => $space], $mailer);

        try {
            $helper->sendHtml('to@example.test', 'Sujet', '<p>HTML</p>', 99);
            self::fail('Un espace inconnu doit être refusé.');
        } catch (InvalidArgumentException) {
            self::assertFalse($mailer->sent);
        }

        $space->params = array_merge((array) $space->params, ['mail_smtp_host' => '']);
        $this->expectException(ApplicationException::class);
        $helper->sendRawHtml('to@example.test', 'Sujet', '<p>HTML</p>');
    }

    public function testSmtpPasswordIsEncryptedOnSaveAndDecryptedForSending(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $space, [1 => $space], $mailer);
        $adminHelper = $this->adminHelper($app, $mailer);

        $params = $adminHelper->protectSmtpPassword((array) $space->params, 'nouveau-secret');
        self::assertSame(1, $params['mail_smtp_pass_key_version']);
        self::assertNotSame('nouveau-secret', $params['mail_smtp_pass']);
        self::assertSame($params, $adminHelper->protectSmtpPassword($params, ''));
        $space->params = $params;
        self::assertTrue($helper->sendRawHtml('to@example.test', 'Sujet', '<p>HTML</p>'));
        self::assertSame('nouveau-secret', $mailer->Password);

        $legacy = (array) $space->params;
        $legacy['mail_smtp_pass'] = 'ancien-secret';
        unset($legacy['mail_smtp_pass_key_version']);
        $upgraded = $adminHelper->protectSmtpPassword($legacy, '');
        self::assertSame(1, $upgraded['mail_smtp_pass_key_version']);
        self::assertNotSame('ancien-secret', $upgraded['mail_smtp_pass']);
        $space->params = $upgraded;
        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $space, [1 => $space], $mailer);
        self::assertTrue($helper->sendRawHtml('to@example.test', 'Sujet', '<p>HTML</p>'));
        self::assertSame('ancien-secret', $mailer->Password);
    }

    public function testAdminMailUsesUnsavedSettingsAndKeepsStoredPassword(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 2);
        $space->title = '<Espace test>';
        $mailer = new RecordingMailer();
        $adminHelper = $this->adminHelper($app, $mailer);
        $stored = $adminHelper->protectSmtpPassword((array) $space->params, 'secret-stocke');
        $space->params = $stored;

        self::assertTrue($adminHelper->sendTestHtml($space, 'test@example.test', [
            'mail_online' => true,
            'mail_smtp_host' => 'brouillon.example.test',
            'mail_smtp_port' => 587,
            'mail_smtp_secure' => 'tls',
            'mail_smtp_pass' => '',
        ]));
        self::assertSame('brouillon.example.test', $mailer->Host);
        self::assertSame(587, $mailer->Port);
        self::assertSame('secret-stocke', $mailer->Password);
        self::assertSame($stored, $space->params);
        self::assertStringContainsString('&lt;Espace test&gt;', $mailer->Body);

        $newMailer = new RecordingMailer();
        $adminHelper = $this->adminHelper($app, $newMailer);
        self::assertTrue($adminHelper->sendTestHtml($space, 'test@example.test', [
            'mail_smtp_pass' => 'secret-nouveau',
        ]));
        self::assertSame('secret-nouveau', $newMailer->Password);
        self::assertSame($stored, $space->params);
    }

    public function testGlobalMailSwitchBlocksRegularAndAdminSending(): void
    {
        $app = $this->createApplication();
        $app->getConf()->mailonline = false;
        $space = $this->space($app, 1);
        $space->title = 'Espace de test';

        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $space, [1 => $space], $mailer);
        self::assertFalse($helper->sendHtml('to@example.test', 'Sujet', '<p>HTML</p>'));
        self::assertFalse($mailer->sent);

        $adminMailer = new RecordingMailer();
        $adminHelper = $this->adminHelper($app, $adminMailer);
        self::assertFalse($adminHelper->sendTestHtml($space, 'to@example.test', [
            'mail_online' => true,
            'mail_smtp_host' => 'brouillon.example.test',
        ]));
        self::assertFalse($adminHelper->sendTemplateTestHtml($space, 'to@example.test', (object) [], 'default'));
        self::assertFalse($adminMailer->sent);
    }

    public function testDefaultBlankAndRawModesUseTheSourceSpaceTemplate(): void
    {
        $app = $this->createMailApplication();
        $current = $this->space($app, 1);
        $source = $this->space($app, 2);
        $source->title = 'Espace source';
        $source->params['main_host'] = 'source.example.test';
        $config = new Config($app);
        $config->params = (object) [
            'logo_email' => '/medias/source/logo.png',
            'email_default_outer_background' => '#EAEAEA',
            'email_footer_text' => '© {space} {year}',
        ];
        $template = new MainTemplate($app);
        $templateHelper = $this->createStub(TemplateHelper::class);
        $selected = [];
        $templateHelper->method('getTemplateAndExtension')->willReturnCallback(
            static function (string $name, ?int $spaceId) use ($config, $template, &$selected): array {
                $selected[] = [$name, $spaceId];
                return [$config, $template];
            }
        );
        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $current, [1 => $current, 2 => $source], $mailer, $templateHelper);

        self::assertTrue($helper->sendHtml('to@example.test', 'Sujet', '<p>Bonjour</p>', 2));
        self::assertSame([['tpl_main', 2]], $selected);
        self::assertStringContainsString('https://source.example.test/medias/source/logo.png', $mailer->Body);
        self::assertStringContainsString('© Espace source ' . date('Y'), $mailer->Body);
        self::assertStringContainsString('#EAEAEA', $mailer->Body);
        self::assertStringContainsString('<p>Bonjour</p>', $mailer->Body);
        self::assertStringNotContainsString('.email-content', $mailer->AltBody);

        self::assertTrue($helper->sendBlankHtml('to@example.test', 'Sujet', '<p>Simple</p>', 2));
        self::assertSame([['tpl_main', 2], ['tpl_main', 2]], $selected);
        self::assertStringContainsString('<p>Simple</p>', $mailer->Body);
        self::assertStringNotContainsString('logo.png', $mailer->Body);
        self::assertStringNotContainsString('<table', $mailer->Body);

        self::assertTrue($helper->sendRawHtml('to@example.test', 'Sujet', '<p>Brut</p>', 2));
        self::assertSame('<p>Brut</p>', $mailer->Body);
        self::assertCount(2, $selected);
    }

    public function testTemplatedModesAbsolutizeRelativeMediaUrlsButRawModeDoesNot(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $space->title = 'Espace source';
        $space->params['main_host'] = 'source.example.test';
        $config = new Config($app);
        $config->params = (object) [
            'email_default_outer_background' => '#EAEAEA',
        ];
        $templateHelper = $this->createStub(TemplateHelper::class);
        $templateHelper->method('getTemplateAndExtension')
            ->willReturn([$config, new MainTemplate($app)]);
        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $space, [1 => $space], $mailer, $templateHelper);
        $html = '<p><img src="/medias/source/photo.jpg" alt="Photo"></p>';

        self::assertTrue($helper->sendDefaultHtml('to@example.test', 'Sujet', $html));
        self::assertStringContainsString(
            'https://source.example.test/medias/source/photo.jpg',
            $mailer->Body,
        );

        // Le mode raw ne doit pas réécrire le HTML fourni.
        self::assertTrue($helper->sendRawHtml('to@example.test', 'Sujet', $html));
        self::assertSame($html, $mailer->Body);
    }

    public function testTemplateMailPreviewUsesDraftPresentationAndSavedSmtpSettings(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 3);
        $space->title = 'Espace test';
        $space->params['main_host'] = 'preview.example.test';
        $templateHelper = $this->createStub(TemplateHelper::class);
        $templateHelper->method('getTemplateExtension')->willReturn(new MainTemplate($app));
        $mailer = new RecordingMailer();
        $adminHelper = $this->adminHelper($app, $mailer, $templateHelper);

        self::assertTrue($adminHelper->sendTemplateTestHtml($space, 'preview@example.test', (object) [
            'email_default_outer_background' => '#AABBCC',
            'email_blank_background' => '#DDEEFF',
        ], 'default'));
        self::assertSame('source@example.test', $mailer->From);
        self::assertSame('smtp.example.test', $mailer->Host);
        self::assertStringContainsString('#AABBCC', $mailer->Body);
        self::assertStringContainsString('https://preview.example.test', $mailer->Body);

        self::assertTrue($adminHelper->sendTemplateTestHtml($space, 'preview@example.test', (object) [
            'email_blank_background' => '#DDEEFF',
        ], 'blank'));
        self::assertStringContainsString('#DDEEFF', $mailer->Body);
        self::assertStringNotContainsString('<table', $mailer->Body);
    }

    public function testTemplatedMailRequiresTheSourceSpaceMainHost(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $mailer = new RecordingMailer();
        $helper = $this->helper($app, $space, [1 => $space], $mailer);

        $this->expectException(ApplicationException::class);
        $this->expectExceptionMessage('domaine principal');
        $helper->sendDefaultHtml('to@example.test', 'Sujet', '<p>HTML</p>');
    }

    public function testUnknownRenderModeIsRejected(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $helper = $this->helper($app, $space, [1 => $space], new RecordingMailer());

        $this->expectException(InvalidArgumentException::class);
        $helper->sendHtml('to@example.test', 'Sujet', '<p>HTML</p>', renderMode: 'unknown');
    }

    public function testSuccessfulSendCreatesProcessingThenDoneLog(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $mailer = new RecordingMailer();
        $logHelper = new RecordingMailLogHelper($app);
        $helper = $this->helper($app, $space, [1 => $space], $mailer, null, $logHelper);

        self::assertTrue($helper->sendRawHtml(
            ['to@example.test' => 'Destinataire'],
            'Sujet',
            '<p>HTML</p>',
            secrets: ['secret-value'],
        ));

        self::assertCount(1, $logHelper->added);
        self::assertSame(1, $logHelper->added[0]['space_id']);
        self::assertSame(MailLogHelper::KIND_HTML, $logHelper->added[0]['kind']);
        self::assertSame(['secret-value'], $logHelper->added[0]['secrets']);
        self::assertSame('source@example.test', $logHelper->added[0]['from_email']);
        self::assertCount(1, $logHelper->done);
        self::assertSame(1, $logHelper->done[0]['logId']);
        self::assertSame([], $logHelper->failed);
    }

    public function testRefusedTransportMarksTheLogAndReturnsFalse(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $mailer = new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function send(): bool
            {
                return false;
            }
        };
        $logHelper = new RecordingMailLogHelper($app);
        $helper = $this->helper($app, $space, [1 => $space], $mailer, null, $logHelper);

        self::assertFalse($helper->sendRawHtml('to@example.test', 'Sujet', '<p>HTML</p>'));
        self::assertCount(1, $logHelper->failed);
        self::assertSame('SMTP_NOT_ACCEPTED', $logHelper->failed[0]['errorCode']);
        self::assertSame([], $logHelper->done);
    }

    public function testTransportExceptionMarksTheLogAndIsRethrown(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $mailer = new class extends PHPMailer {
            public function __construct()
            {
                parent::__construct(true);
            }

            public function send(): bool
            {
                throw new \PHPMailer\PHPMailer\Exception('550 refusé');
            }
        };
        $logHelper = new RecordingMailLogHelper($app);
        $helper = $this->helper($app, $space, [1 => $space], $mailer, null, $logHelper);

        try {
            $helper->sendRawHtml('to@example.test', 'Sujet', '<p>HTML</p>');
            self::fail('Une exception SMTP doit remonter.');
        } catch (\PHPMailer\PHPMailer\Exception $exception) {
            self::assertSame('550 refusé', $exception->getMessage());
        }

        self::assertCount(1, $logHelper->failed);
        self::assertSame('SMTP_SEND_FAILED', $logHelper->failed[0]['errorCode']);
        self::assertSame('550 refusé', $logHelper->failed[0]['errorMessage']);
    }

    public function testJournalFailureDoesNotBlockTheSend(): void
    {
        $app = $this->createMailApplication();
        $space = $this->space($app, 1);
        $logHelper = new RecordingMailLogHelper($app);
        $logHelper->addFailure = new \RuntimeException('db down');
        $helper = $this->helper($app, $space, [1 => $space], new RecordingMailer(), null, $logHelper);

        self::assertTrue($helper->sendRawHtml('to@example.test', 'Sujet', '<p>HTML</p>'));
        self::assertSame([], $logHelper->added);
        self::assertSame([], $logHelper->done);
    }

    public function testKnownSensitiveVariablesAreCollectedForMasking(): void
    {
        self::assertSame(
            ['mot-de-passe', 'https://example.test/reset/jeton'],
            MailHelper::sensitiveVariableValues([
                'initial_password' => 'mot-de-passe',
                'reset_url' => 'https://example.test/reset/jeton',
                'first_name' => 'Alice',
            ]),
        );

        self::assertSame([], MailHelper::sensitiveVariableValues([
            'initial_password' => '',
            'reset_url' => null,
        ]));
    }

    private function createMailApplication(): Application
    {
        $app = $this->createApplication();
        $app->getConf()->mailonline = true;

        return $app;
    }

    private function space(Application $app, int $id): Space
    {
        $space = new Space($app);
        $space->id = $id;
        $space->template = 'tpl_main';
        $space->params = [
            'mail_online' => true,
            'mailer' => 'smtp',
            'mail_from' => 'source@example.test',
            'mail_from_name' => 'Source',
            'mail_smtp_host' => 'smtp.example.test',
            'mail_smtp_port' => 465,
            'mail_smtp_secure' => 'ssl',
            'mail_smtp_auth' => true,
            'mail_smtp_user' => 'smtp-user',
            'mail_smtp_pass' => 'smtp-pass',
        ];

        return $space;
    }

    /** @param array<int, Space> $spaces */
    private function helper(
        Application $app, Space $current, array $spaces, PHPMailer $mailer,
        ?TemplateHelper $templateHelper = null, ?MailLogHelper $logHelper = null,
    ): MailHelper
    {
        $spaceHelper = $this->createStub(SpaceHelper::class);
        $spaceHelper->method('getCurrentSpace')->willReturn($current);
        $spaceHelper->method('getSpacesByIds')->willReturnCallback(
            static fn (array $ids): array => array_intersect_key($spaces, array_flip($ids))
        );

        $cryptHelper = new CryptHelper($app);
        $logHelper ??= new RecordingMailLogHelper($app);

        return new class($app, $spaceHelper, $cryptHelper, $mailer, $templateHelper, $logHelper) extends MailHelper {
            public function __construct(
                Application $app,
                private SpaceHelper $spaceHelper,
                private CryptHelper $cryptHelper,
                private PHPMailer $mailer,
                private ?TemplateHelper $templateHelper,
                private MailLogHelper $logHelper,
            ) {
                parent::__construct($app);
            }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    CryptHelper::class => $this->cryptHelper,
                    MailLogHelper::class => $this->logHelper,
                    TemplateHelper::class => $this->templateHelper ?? throw new \LogicException('TemplateHelper non simulé.'),
                    default => $this->spaceHelper,
                };
            }

            protected function createMailer(): PHPMailer
            {
                return $this->mailer;
            }
        };
    }

    private function adminHelper(Application $app, PHPMailer $mailer, ?TemplateHelper $templateHelper = null): MailAdminHelper
    {
        $logHelper = new RecordingMailLogHelper($app);

        return new class($app, $mailer, $templateHelper, $logHelper) extends MailAdminHelper {
            public function __construct(
                Application $app,
                private PHPMailer $mailer,
                private ?TemplateHelper $templateHelper,
                private MailLogHelper $logHelper,
            )
            {
                parent::__construct($app);
            }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    MailLogHelper::class => $this->logHelper,
                    TemplateHelper::class => $this->templateHelper
                        ?? throw new \LogicException('TemplateHelper non simulé.'),
                    default => parent::getSharedExtensionsClass($className),
                };
            }

            protected function createMailer(): PHPMailer
            {
                return $this->mailer;
            }
        };
    }
}

final class RecordingMailer extends PHPMailer
{
    public bool $sent = false;

    public function __construct()
    {
        parent::__construct(true);
    }

    public function send(): bool
    {
        $this->sent = true;

        return true;
    }
}

/**
 * Journal en mémoire : les tests vérifient le contrat de MailHelper sans base.
 */
final class RecordingMailLogHelper extends MailLogHelper
{
    public bool $enabled = true;

    public ?\Throwable $addFailure = null;

    /** @var list<array<string, mixed>> */
    public array $added = [];

    /** @var list<array<string, mixed>> */
    public array $done = [];

    /** @var list<array<string, mixed>> */
    public array $failed = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function add(
        ?int $spaceId,
        string $kind,
        string|array $to,
        string $subject,
        string $bodyHtml,
        string|array $cc = [],
        string|array $bcc = [],
        array $attachments = [],
        ?string $templateKey = null,
        ?string $locale = null,
        array $secrets = [],
        ?int $jobId = null,
        ?int $userId = null,
        ?string $fromEmail = null,
        ?string $fromName = null,
    ): ?int {
        if ($this->addFailure !== null) {
            throw $this->addFailure;
        }

        $this->added[] = [
            'space_id' => $spaceId,
            'kind' => $kind,
            'to' => $to,
            'subject' => $subject,
            'body_html' => $bodyHtml,
            'cc' => $cc,
            'bcc' => $bcc,
            'attachments' => $attachments,
            'template_key' => $templateKey,
            'locale' => $locale,
            'secrets' => $secrets,
            'job_id' => $jobId,
            'user_id' => $userId,
            'from_email' => $fromEmail,
            'from_name' => $fromName,
        ];

        return count($this->added);
    }

    public function markDone(
        int $logId,
        ?string $messageId,
        ?string $bodyText,
        array $secrets,
        int $durationMs,
    ): void {
        $this->done[] = compact('logId', 'messageId', 'bodyText', 'secrets', 'durationMs');
    }

    public function markFailed(
        int $logId,
        string $errorCode,
        ?string $errorClass,
        ?string $errorMessage,
        ?string $bodyText,
        array $secrets,
        int $durationMs,
    ): void {
        $this->failed[] = compact(
            'logId', 'errorCode', 'errorClass', 'errorMessage', 'bodyText', 'secrets', 'durationMs',
        );
    }
}
