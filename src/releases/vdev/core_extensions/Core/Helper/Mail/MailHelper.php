<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use InvalidArgumentException;
use PHPMailer\PHPMailer\PHPMailer;
use Throwable;
use YTassy\Tycms\Exceptions\ApplicationException;
use YTassy\Tycms\Extensions\BaseTemplate;
use YTassy\Tycms\Extensions\EmailRenderContext;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\Logging\LogEventException;

class MailHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    /**
     * Variables dont la valeur ne doit jamais apparaître dans le journal.
     *
     * Le masquage explicite de l'appelant reste prioritaire ; cette liste est
     * un filet de sécurité pour les deux modèles Core qui portent un secret.
     */
    private const SENSITIVE_VARIABLE_NAMES = ['initial_password', 'reset_url'];

    /**
     * Send an HTML email immediately through the source Space's SMTP server.
     *
     * Address lists accept an email string, a list of emails, or email => name pairs.
     * Attachments accept file paths or ['path' => ..., 'name' => ...] entries.
     * Returns false without sending when global mailonline or the source Space's
     * mail_online is disabled. Configuration and transport errors are exceptions.
     * The source Space selects its template; renderMode is default, blank or raw.
     * In default and blank modes, relative /medias/ image sources become absolute
     * URLs; raw mode transmits the HTML unchanged. Values listed in $secrets are
     * masked in the mail journal only; the sent message keeps them unchanged.
     *
     * @param string|array<int|string, string> $to
     * @param string|array<int|string, string> $cc
     * @param string|array<int|string, string> $bcc
     * @param string|array<int|string, string> $replyTo
     * @param array<int, string|array{path:string, name?:string}> $attachments
     */
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
        if ($this->app->getConf()->mailonline === false) {
            return false;
        }

        $space = $this->resolveSourceSpace($sourceSpace);
        $params = is_object($space->params) ? get_object_vars($space->params) : (is_array($space->params) ? $space->params : []);
        if (!($params['mail_online'] ?? true)) {
            return false;
        }

        return $this->sendPreparedHtml(
            to: $to,
            subject: $subject,
            html: $html,
            space: $space,
            params: $params,
            cc: $cc,
            bcc: $bcc,
            replyTo: $replyTo,
            attachments: $attachments,
            renderMode: $renderMode,
            emailLocale: $emailLocale,
            kind: MailLogHelper::KIND_HTML,
            templateKey: null,
            secrets: $secrets,
            jobId: $jobId,
            userId: $userId,
        );
    }

    /**
     * Envoie un modèle métier avec l'habillage du Space source.
     *
     * Les valeurs de `$secrets`, ainsi que celles des variables sensibles
     * connues (`initial_password`, `reset_url`), sont masquées dans le journal.
     */
    public function sendTemplate(
        string $key,
        string|array $to,
        array $values,
        Space|int|null $sourceSpace = null,
        ?string $locale = null,
        string|array $cc = [],
        string|array $bcc = [],
        string|array $replyTo = [],
        array $attachments = [],
        array $secrets = [],
        ?int $jobId = null,
        ?int $userId = null,
    ): bool {
        if ($this->app->getConf()->mailonline === false) {
            return false;
        }

        $space = $this->resolveSourceSpace($sourceSpace);
        $params = is_object($space->params) ? get_object_vars($space->params) : (array) $space->params;
        if (!($params['mail_online'] ?? true)) {
            return false;
        }

        $locale ??= $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);
        $message = $this->coreEmailTemplateHelper->prepare($key, $space->id, $locale, $values);

        // Filet de sécurité : les variables sensibles connues sont masquées
        // même si l'appelant a oublié de les ajouter à $secrets.
        $secrets = array_values(array_unique(array_filter(
            [...$secrets, ...self::sensitiveVariableValues($values)],
            static fn (mixed $value): bool => is_string($value) && $value !== '',
        )));

        return $this->sendPreparedHtml(
            to: $to,
            subject: $message['subject'],
            html: $message['bodyHtml'],
            space: $space,
            params: $params,
            cc: $cc,
            bcc: $bcc,
            replyTo: $replyTo,
            attachments: $attachments,
            renderMode: $message['renderMode'],
            emailLocale: $message['locale'],
            kind: MailLogHelper::KIND_TEMPLATE,
            templateKey: $key,
            secrets: $secrets,
            jobId: $jobId,
            userId: $userId,
        );
    }

    /** Rend un brouillon dans la Template du Space sans ouvrir de connexion SMTP. */
    public function renderEmailPreview(string $html, Space $space, string $mode, string $locale): string
    {
        return $this->prepareHtml($this->absoluteMediaUrls($html, $space), $space, $mode, $locale);
    }

    private function absoluteMediaUrls(string $html, Space $space): string
    {
        if (!str_contains($html, '<img')) {
            return $html;
        }

        $origin = $this->siteUrlForEmail($space);
        return preg_replace_callback(
            '~(<img\b[^>]*\bsrc=")(/medias/[^"<>]+)(")~i',
            static fn (array $match): string => $match[1] . $origin . $match[2] . $match[3],
            $html,
        ) ?? $html;
    }

    /** Send through the source Space's template with its framed layout. */
    public function sendDefaultHtml(
        string|array $to, string $subject, string $html, Space|int|null $sourceSpace = null,
        string|array $cc = [], string|array $bcc = [], string|array $replyTo = [], array $attachments = [],
        array $secrets = [],
    ): bool {
        return $this->sendHtml(
            $to, $subject, $html, $sourceSpace, $cc, $bcc, $replyTo, $attachments,
            renderMode: 'default',
            secrets: $secrets,
        );
    }

    /** Send through the source Space's template with its simple layout. */
    public function sendBlankHtml(
        string|array $to, string $subject, string $html, Space|int|null $sourceSpace = null,
        string|array $cc = [], string|array $bcc = [], string|array $replyTo = [], array $attachments = [],
        array $secrets = [],
    ): bool {
        return $this->sendHtml(
            $to, $subject, $html, $sourceSpace, $cc, $bcc, $replyTo, $attachments,
            renderMode: 'blank',
            secrets: $secrets,
        );
    }

    /** Send trusted HTML exactly as supplied, without a template. */
    public function sendRawHtml(
        string|array $to, string $subject, string $html, Space|int|null $sourceSpace = null,
        string|array $cc = [], string|array $bcc = [], string|array $replyTo = [], array $attachments = [],
        array $secrets = [],
    ): bool {
        return $this->sendHtml(
            $to, $subject, $html, $sourceSpace, $cc, $bcc, $replyTo, $attachments,
            renderMode: 'raw',
            secrets: $secrets,
        );
    }

    /**
     * Prépare et envoie le message déjà résolu, en transmettant le contexte du journal.
     *
     * @param array<string, mixed> $params
     * @param string|array<int|string, string> $to
     * @param string|array<int|string, string> $cc
     * @param string|array<int|string, string> $bcc
     * @param string|array<int|string, string> $replyTo
     * @param array<int, string|array{path:string, name?:string}> $attachments
     * @param list<string> $secrets
     */
    private function sendPreparedHtml(
        string|array $to,
        string $subject,
        string $html,
        Space $space,
        array $params,
        string|array $cc,
        string|array $bcc,
        string|array $replyTo,
        array $attachments,
        string $renderMode,
        ?string $emailLocale,
        string $kind,
        ?string $templateKey,
        array $secrets,
        ?int $jobId,
        ?int $userId,
    ): bool {
        // Les images des médias restent relatives dans un contenu libre. Un
        // client de messagerie ne sait pas les résoudre : les modes avec
        // habillage reçoivent donc une URL absolue. Le mode raw reste intact.
        if ($renderMode !== 'raw') {
            $html = $this->absoluteMediaUrls($html, $space);
        }

        $html = $this->prepareHtml($html, $space, $renderMode, $emailLocale);

        return $this->sendHtmlWithParams(
            $to, $subject, $html, $params, $cc, $bcc, $replyTo, $attachments,
            [
                'space_id' => $space->id,
                'kind' => $kind,
                'template_key' => $templateKey,
                'locale' => $emailLocale,
                'secrets' => $secrets,
                'job_id' => $jobId,
                'user_id' => $userId,
            ],
        );
    }

    /**
     * Valeurs des variables de modèle qui ne doivent jamais être stockées.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    public static function sensitiveVariableValues(array $values): array
    {
        $secrets = [];
        foreach (self::SENSITIVE_VARIABLE_NAMES as $name) {
            $value = $values[$name] ?? null;
            if (is_string($value) && $value !== '') {
                $secrets[] = $value;
            }
        }

        return $secrets;
    }

    private function prepareHtml(string $html, Space $space, string $renderMode, ?string $emailLocale = null): string
    {
        if ($renderMode === 'raw') {
            return $html;
        }
        if (!in_array($renderMode, ['blank', 'default'], true)) {
            throw new InvalidArgumentException('Mode de rendu e-mail inconnu.');
        }

        $siteUrl = $this->siteUrlForEmail($space);
        [$configuration, $template] = $this->coreTemplateHelper->getTemplateAndExtension($space->template, $space->id);
        return $this->renderHtmlWithTemplateParams($html, $space, $renderMode, $configuration->params, $template, $siteUrl, $emailLocale);
    }

    protected function renderHtmlWithTemplateParams(
        string $html, Space $space, string $renderMode, object $templateParams,
        ?BaseTemplate $template = null, ?string $siteUrl = null, ?string $emailLocale = null,
    ): string
    {
        if (!in_array($renderMode, ['blank', 'default'], true)) {
            throw new InvalidArgumentException('Mode de rendu e-mail inconnu.');
        }

        $siteUrl ??= $this->siteUrlForEmail($space);
        $template ??= $this->coreTemplateHelper->getTemplateExtension($space->template);
        return $template->renderEmail(
            $html,
            $templateParams,
            $renderMode,
            new EmailRenderContext($siteUrl, $space->title, $emailLocale ?? 'fr'),
        );
    }

    public function siteUrlForEmail(Space $space): string
    {
        $params = is_object($space->params) ? get_object_vars($space->params) : (is_array($space->params) ? $space->params : []);
        $host = strtolower(trim((string) ($params['main_host'] ?? '')));
        // En développement, les liens locaux utilisent le port HTTP du domaine enregistré.
        if ($this->app->getConf()->dev
            && preg_match('/^localhost:([0-9]{1,5})$/D', $host, $matches) === 1
            && (int) $matches[1] >= 1 && (int) $matches[1] <= 65535) {
            return 'http://' . $host;
        }

        $validDomain = preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $host) === 1;
        if ($host === '' || (!$validDomain && filter_var($host, FILTER_VALIDATE_IP) === false)) {
            throw new ApplicationException('Le domaine principal du Space est requis pour les e-mails avec template.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $host = '[' . $host . ']';
        }
        return 'https://' . $host;
    }

    /**
     * @param array<string, mixed> $params
     * @param string|array<int|string, string> $to
     * @param string|array<int|string, string> $cc
     * @param string|array<int|string, string> $bcc
     * @param string|array<int|string, string> $replyTo
     * @param array<int, string|array{path:string, name?:string}> $attachments
     * @param array<string, mixed> $logContext
     */
    protected function sendHtmlWithParams(
        string|array $to,
        string $subject,
        string $html,
        array $params,
        string|array $cc = [],
        string|array $bcc = [],
        string|array $replyTo = [],
        array $attachments = [],
        array $logContext = [],
    ): bool {
        if ($this->app->getConf()->mailonline === false || !($params['mail_online'] ?? true)) {
            return false;
        }

        $spaceId = isset($logContext['space_id']) && (int) $logContext['space_id'] > 0
            ? (int) $logContext['space_id']
            : null;
        $secrets = is_array($logContext['secrets'] ?? null) ? $logContext['secrets'] : [];

        // La ligne naît avant toute connexion SMTP, y compris pour une
        // configuration invalide : le journal doit montrer la tentative.
        $logId = $this->startMailLog($to, $subject, $html, $params, $cc, $bcc, $attachments, $logContext);

        $startedAt = microtime(true);
        $altBody = null;
        $secretsForLog = $secrets;

        try {
            if (($params['mailer'] ?? 'smtp') !== 'smtp') {
                throw new ApplicationException('Transport e-mail non pris en charge pour cet espace.');
            }

            $host = trim((string) ($params['mail_smtp_host'] ?? ''));
            $from = trim((string) ($params['mail_from'] ?? ''));
            $port = filter_var($params['mail_smtp_port'] ?? null, FILTER_VALIDATE_INT);
            $secure = (string) ($params['mail_smtp_secure'] ?? 'ssl');
            if ($host === '' || $from === '' || $port === false || $port < 1 || $port > 65535
                || !in_array($secure, ['ssl', 'tls', ''], true)) {
                throw new ApplicationException('Configuration SMTP incomplète ou invalide pour cet espace.');
            }

            $mailer = $this->createMailer();
            $mailer->isSMTP();
            $mailer->Host = $host;
            $mailer->Port = $port;
            $mailer->Timeout = 4;
            $mailer->SMTPAuth = (bool) ($params['mail_smtp_auth'] ?? true);
            $mailer->Username = (string) ($params['mail_smtp_user'] ?? '');
            $mailer->Password = $this->smtpPassword($params);
            $mailer->SMTPSecure = match ($secure) {
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => '',
            };
            $mailer->SMTPAutoTLS = false;
            $mailer->CharSet = PHPMailer::CHARSET_UTF8;
            $mailer->setFrom($from, (string) ($params['mail_from_name'] ?? ''));

            // Le mot de passe SMTP ne doit jamais ressortir dans un message
            // d'erreur conservé par le journal.
            if ($mailer->Password !== '') {
                $secretsForLog[] = $mailer->Password;
            }

            $this->addAddresses($mailer, $to, 'to');
            $this->addAddresses($mailer, $cc, 'cc');
            $this->addAddresses($mailer, $bcc, 'bcc');
            $this->addAddresses($mailer, $replyTo, 'replyTo');
            foreach ($attachments as $attachment) {
                if (is_string($attachment)) {
                    $mailer->addAttachment($attachment);
                } else {
                    $mailer->addAttachment($attachment['path'], $attachment['name'] ?? '');
                }
            }

            $mailer->isHTML(true);
            $mailer->Subject = $subject;
            $mailer->Body = $html;
            $mailer->AltBody = $mailer->html2text($html);
            $altBody = $mailer->AltBody;

            if ($mailer->send()) {
                $this->finishMailLogDone($logId, $mailer, $altBody, $secretsForLog, $startedAt, $spaceId);
                return true;
            }

            $this->finishMailLogFailed(
                $logId,
                'SMTP_NOT_ACCEPTED',
                null,
                'Le serveur SMTP n’a pas accepté le message.',
                $altBody,
                $secretsForLog,
                $startedAt,
                $spaceId,
            );

            return false;
        } catch (Throwable $exception) {
            $this->finishMailLogFailed(
                $logId,
                $this->mailErrorCode($exception),
                $exception::class,
                $exception->getMessage(),
                $altBody,
                $secretsForLog,
                $startedAt,
                $spaceId,
            );

            throw $exception;
        }
    }

    /**
     * Insère la ligne `processing`, sans jamais empêcher l'envoi si le journal échoue.
     *
     * @param string|array<int|string, string> $to
     * @param string|array<int|string, string> $cc
     * @param string|array<int|string, string> $bcc
     * @param array<int, string|array{path:string, name?:string}> $attachments
     * @param array<string, mixed> $params
     * @param array<string, mixed> $logContext
     */
    private function startMailLog(
        string|array $to,
        string $subject,
        string $html,
        array $params,
        string|array $cc,
        string|array $bcc,
        array $attachments,
        array $logContext,
    ): ?int {
        if (!$this->coreMailLogHelper->isEnabled()) {
            return null;
        }

        $spaceId = isset($logContext['space_id']) && (int) $logContext['space_id'] > 0
            ? (int) $logContext['space_id']
            : null;
        $kind = is_string($logContext['kind'] ?? null) ? $logContext['kind'] : MailLogHelper::KIND_HTML;

        try {
            return $this->coreMailLogHelper->add(
                spaceId: $spaceId,
                kind: $kind,
                to: $to,
                subject: $subject,
                bodyHtml: $html,
                cc: $cc,
                bcc: $bcc,
                attachments: $attachments,
                templateKey: is_string($logContext['template_key'] ?? null) ? $logContext['template_key'] : null,
                locale: is_string($logContext['locale'] ?? null) ? $logContext['locale'] : null,
                secrets: is_array($logContext['secrets'] ?? null) ? $logContext['secrets'] : [],
                jobId: isset($logContext['job_id']) && (int) $logContext['job_id'] > 0
                    ? (int) $logContext['job_id'] : null,
                userId: isset($logContext['user_id']) && (int) $logContext['user_id'] > 0
                    ? (int) $logContext['user_id'] : null,
                fromEmail: isset($params['mail_from']) ? (string) $params['mail_from'] : null,
                fromName: isset($params['mail_from_name']) ? (string) $params['mail_from_name'] : null,
            );
        } catch (Throwable $exception) {
            // Le journal est un diagnostic : une panne d'écriture ne doit pas
            // empêcher un reset de mot de passe ou une bienvenue de partir.
            $this->logException(new LogEventException(
                'MAIL_LOG_FAILED',
                'Le journal des e-mails n’a pas pu être écrit.',
                'mail',
                ['space_id' => $spaceId, 'kind' => $kind],
                'WARNING',
                $exception,
            ));

            return null;
        }
    }

    /** @param list<string> $secrets */
    private function finishMailLogDone(
        ?int $logId,
        PHPMailer $mailer,
        ?string $bodyText,
        array $secrets,
        float $startedAt,
        ?int $spaceId,
    ): void {
        if ($logId === null) {
            return;
        }

        try {
            $messageId = $mailer->getLastMessageID();
            $this->coreMailLogHelper->markDone(
                $logId,
                is_string($messageId) && $messageId !== '' ? $messageId : null,
                $bodyText,
                $secrets,
                self::durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            $this->logMailLogUpdateFailure($exception, $spaceId);
        }
    }

    /** @param list<string> $secrets */
    private function finishMailLogFailed(
        ?int $logId,
        string $errorCode,
        ?string $errorClass,
        ?string $errorMessage,
        ?string $bodyText,
        array $secrets,
        float $startedAt,
        ?int $spaceId,
    ): void {
        if ($logId === null) {
            return;
        }

        try {
            $this->coreMailLogHelper->markFailed(
                $logId,
                $errorCode,
                $errorClass,
                $errorMessage,
                $bodyText,
                $secrets,
                self::durationMs($startedAt),
            );
        } catch (Throwable $exception) {
            $this->logMailLogUpdateFailure($exception, $spaceId);
        }
    }

    private function logMailLogUpdateFailure(Throwable $exception, ?int $spaceId): void
    {
        $this->logException(new LogEventException(
            'MAIL_LOG_UPDATE_FAILED',
            'Le journal des e-mails n’a pas pu être mis à jour.',
            'mail',
            ['space_id' => $spaceId],
            'WARNING',
            $exception,
        ));
    }

    private static function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function mailErrorCode(Throwable $exception): string
    {
        if ($exception instanceof InvalidArgumentException) {
            return 'MAIL_ADDRESS_INVALID';
        }

        if ($exception instanceof ApplicationException) {
            return str_contains($exception->getMessage(), 'Transport e-mail')
                ? 'SMTP_TRANSPORT_UNSUPPORTED'
                : 'SMTP_CONFIG';
        }

        if ($exception instanceof \PHPMailer\PHPMailer\Exception) {
            return 'SMTP_SEND_FAILED';
        }

        return 'SMTP_SEND_FAILED';
    }

    protected function createMailer(): PHPMailer
    {
        return new PHPMailer(true);
    }

    /** @param array<string, mixed> $params */
    private function smtpPassword(array $params): string
    {
        $storedPassword = (string) ($params['mail_smtp_pass'] ?? '');
        return match ((int) ($params['mail_smtp_pass_key_version'] ?? 0)) {
            0 => $storedPassword,
            1 => $storedPassword === '' ? '' : $this->coreCryptHelper->decrypt($storedPassword, 'mail-smtp:v1'),
            default => throw new ApplicationException('Version du mot de passe SMTP inconnue.'),
        };
    }

    private function resolveSourceSpace(Space|int|null $sourceSpace): Space
    {
        if ($sourceSpace === null) {
            return $this->coreSpaceHelper->getCurrentSpace();
        }

        $spaceId = $sourceSpace instanceof Space ? $sourceSpace->id : $sourceSpace;
        $space = $this->coreSpaceHelper->getSpacesByIds([$spaceId])[$spaceId] ?? null;
        if ($space === null) {
            throw new InvalidArgumentException('Espace source introuvable.');
        }

        return $space;
    }

    /** @param string|array<int|string, string> $addresses */
    private function addAddresses(PHPMailer $mailer, string|array $addresses, string $kind): void
    {
        if (is_string($addresses)) {
            $addresses = [$addresses];
        }
        if ($kind === 'to' && $addresses === []) {
            throw new InvalidArgumentException('Au moins un destinataire est requis.');
        }

        foreach ($addresses as $email => $name) {
            if (is_int($email)) {
                $email = $name;
                $name = '';
            }
            match ($kind) {
                'to' => $mailer->addAddress($email, $name),
                'cc' => $mailer->addCC($email, $name),
                'bcc' => $mailer->addBCC($email, $name),
                'replyTo' => $mailer->addReplyTo($email, $name),
            };
        }
    }
}

