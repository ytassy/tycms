<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use InvalidArgumentException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Journal des e-mails sortants.
 *
 * Une ligne naît en `processing` avant la connexion SMTP, puis passe en `done`
 * ou `failed`. Le journal est un diagnostic d'exploitation : il ne remplace
 * pas les logs applicatifs et ne doit jamais recevoir un secret en clair.
 * Les valeurs transmises dans `$secrets` sont remplacées par [SECRET] avant
 * l'écriture, y compris leurs variantes échappées en HTML.
 */
class MailLogHelper extends BaseHelper
{
    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    public const KIND_HTML = 'html';

    public const KIND_TEMPLATE = 'template';

    public const KIND_TEST = 'test';

    public const SECRET_PLACEHOLDER = '[SECRET]';

    /** En dessous, un secret masquerait des mots courants du message. */
    public const MIN_SECRET_LENGTH = 4;

    public const MAX_SUBJECT_LENGTH = 255;

    public const MAX_ERROR_MESSAGE_LENGTH = 1000;

    /** La purge manuelle et la purge horaire avancent par lots bornés. */
    public const PURGE_BATCH_SIZE = 500;

    public const PURGE_MAX_BATCHES = 20;

    public const RETENTION_MIN_DAYS = 1;

    public const RETENTION_MAX_DAYS = 3650;

    /** Liste d'administration : bornée pour ne pas renvoyer un historique démesuré. */
    public const LIST_DEFAULT_LIMIT = 2000;

    public const LIST_MAX_LIMIT = 5000;

    /** Le stockage peut être coupé sans perdre les boutons de purge. */
    public function isEnabled(): bool
    {
        return (bool) ($this->app->getConf()->mail_log_enabled ?? true);
    }

    /** Durée de rétention effective, bornée même si la configuration est erronée. */
    public function retentionDays(): int
    {
        $days = (int) ($this->app->getConf()->mail_log_retention_days ?? 90);

        return max(self::RETENTION_MIN_DAYS, min(self::RETENTION_MAX_DAYS, $days));
    }

    /**
     * Insère la ligne d'une tentative d'envoi.
     *
     * `$secrets` contient les valeurs à ne jamais conserver : mot de passe
     * initial, URL de réinitialisation, ou tout autre secret fourni par
     * l'appelant. Une valeur vide ou trop courte est ignorée : elle masquerait
     * davantage que le secret visé.
     *
     * @param string|array<int|string, string> $to
     * @param string|array<int|string, string> $cc
     * @param string|array<int|string, string> $bcc
     * @param array<int, string|array{path?:string, name?:string}> $attachments
     * @param list<string> $secrets
     */
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
        if (!$this->isEnabled()) {
            return null;
        }

        $kind = in_array($kind, [self::KIND_HTML, self::KIND_TEMPLATE, self::KIND_TEST], true)
            ? $kind
            : self::KIND_HTML;

        $toJson = self::json(self::normaliseAddresses($to));
        $ccJson = self::json(self::normaliseAddresses($cc));
        $bccJson = self::json(self::normaliseAddresses($bcc));
        $attachmentsJson = self::json(self::normaliseAttachments($attachments));
        $subject = self::singleLine(self::maskSecrets($subject, $secrets), self::MAX_SUBJECT_LENGTH) ?? '';
        $bodyHtml = self::maskSecrets($bodyHtml, $secrets) ?? '';

        $this->db->execute(
            <<<'SQL'
                INSERT INTO mail_logs
                    (space_id, status, kind, template_key, locale, to_json, cc_json, bcc_json,
                     subject, body_html, body_text, attachments, from_email, from_name, job_id, user_id)
                VALUES (%, %, %, %, %, %, %, %, %, %, NULL, %, %, %, %, %)
                SQL,
            [
                $spaceId !== null && $spaceId > 0 ? $spaceId : null,
                self::STATUS_PROCESSING,
                $kind,
                self::truncate($templateKey, 150),
                self::truncate($locale, 35),
                $toJson,
                $ccJson,
                $bccJson,
                $subject,
                $bodyHtml,
                $attachmentsJson,
                self::truncate($fromEmail, 255),
                self::truncate($fromName, 255),
                $jobId !== null && $jobId > 0 ? $jobId : null,
                $userId !== null && $userId > 0 ? $userId : null,
            ],
        );

        $id = (int) $this->db->getLastInsertId();

        return $id > 0 ? $id : null;
    }

    /**
     * Termine une tentative acceptée par le serveur SMTP.
     *
     * Le texte est déduit du HTML par PHPMailer : il est masqué ici avant
     * l'écriture, car il peut contenir les mêmes secrets que le corps HTML.
     *
     * @param list<string> $secrets
     */
    public function markDone(
        int $logId,
        ?string $messageId,
        ?string $bodyText,
        array $secrets,
        int $durationMs,
    ): void {
        $this->db->execute(
            <<<'SQL'
                UPDATE mail_logs
                   SET status = %, finished_at = current_timestamp(), duration_ms = %,
                       message_id = %, body_text = COALESCE(%, body_text)
                 WHERE id = % AND status = %
                SQL,
            [
                self::STATUS_DONE,
                max(0, $durationMs),
                self::truncate($messageId, 255),
                self::maskSecrets($bodyText, $secrets),
                $logId,
                self::STATUS_PROCESSING,
            ],
        );
    }

    /**
     * Termine une tentative refusée ou interrompue.
     *
     * Le message d'erreur est conservé de façon bornée et masquée : un refus
     * SMTP utile au diagnostic ne doit pas y introduire un secret.
     *
     * @param list<string> $secrets
     */
    public function markFailed(
        int $logId,
        string $errorCode,
        ?string $errorClass,
        ?string $errorMessage,
        ?string $bodyText,
        array $secrets,
        int $durationMs,
    ): void {
        $this->db->execute(
            <<<'SQL'
                UPDATE mail_logs
                   SET status = %, finished_at = current_timestamp(), duration_ms = %,
                       error_code = %, error_class = %, error_message = %,
                       body_text = COALESCE(%, body_text)
                 WHERE id = % AND status = %
                SQL,
            [
                self::STATUS_FAILED,
                max(0, $durationMs),
                self::normaliseErrorCode($errorCode),
                self::truncate($errorClass, 150),
                self::singleLine(self::maskSecrets($errorMessage, $secrets), self::MAX_ERROR_MESSAGE_LENGTH),
                self::maskSecrets($bodyText, $secrets),
                $logId,
                self::STATUS_PROCESSING,
            ],
        );
    }

    /**
     * Supprime les lignes plus anciennes que le seuil, ou toutes si `days` vaut 0.
     *
     * La suppression avance par lots pour ne pas verrouiller la table sur un
     * hébergement mutualisé. Un historique très volumineux peut demander
     * plusieurs passages ; le CRON horaire poursuit alors la rétention.
     */
    public function purge(int $days): int
    {
        if ($days < 0) {
            throw new InvalidArgumentException('Seuil de purge invalide.');
        }

        $days = min(self::RETENTION_MAX_DAYS, $days);
        $deleted = 0;

        for ($batch = 0; $batch < self::PURGE_MAX_BATCHES; $batch++) {
            $sql = $days === 0
                ? 'DELETE FROM mail_logs LIMIT ' . self::PURGE_BATCH_SIZE
                : 'DELETE FROM mail_logs WHERE created_at < DATE_SUB(now(), INTERVAL '
                    . $days . ' DAY) LIMIT ' . self::PURGE_BATCH_SIZE;

            $count = $this->db->execute($sql);
            $deleted += $count;

            if ($count < self::PURGE_BATCH_SIZE) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * État affiché dans l'écran d'administration.
     *
     * Les bornes `MIN`/`MAX` restent indexées et évitent un `COUNT(*)` coûteux
     * sur une table qui peut contenir beaucoup de lignes.
     *
     * @return array{enabled:bool, retentionDays:int, oldest:?string, newest:?string}
     */
    public function status(): array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT MIN(created_at) AS oldest, MAX(created_at) AS newest FROM mail_logs',
        );

        return [
            'enabled' => $this->isEnabled(),
            'retentionDays' => $this->retentionDays(),
            'oldest' => $row?->oldest === null ? null : (string) $row->oldest,
            'newest' => $row?->newest === null ? null : (string) $row->newest,
        ];
    }

    /**
     * Liste les métadonnées seules, sans corps ni message d'erreur.
     *
     * La requête ne lit que les colonnes affichées. Le premier destinataire et
     * son nombre sont extraits en SQL, ce qui évite de transférer `to_json`
     * entier pour chaque ligne. `LIMIT n+1` détecte l'écrêtage sans `COUNT(*)`.
     *
     * @return array{items: list<array<string, mixed>>, truncated: bool}
     */
    public function listMetadata(int $limit = self::LIST_DEFAULT_LIMIT): array
    {
        $limit = max(1, min(self::LIST_MAX_LIMIT, $limit));
        $sql = sprintf(<<<'SQL'
            SELECT m.id, m.created_at, m.status, m.kind, m.template_key, m.space_id,
                   s.title AS space_title, m.subject, m.error_code, m.duration_ms,
                   JSON_UNQUOTE(JSON_EXTRACT(m.to_json, '$[0].email')) AS first_to,
                   JSON_LENGTH(m.to_json) AS to_count
              FROM mail_logs m
              LEFT JOIN spaces s ON s.id = m.space_id
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT %d
            SQL, $limit + 1);

        $rows = $this->db->select($sql);
        $truncated = count($rows) > $limit;

        if ($truncated) {
            array_pop($rows);
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id' => (int) $row->id,
                'created_at' => (string) $row->created_at,
                'status' => (string) $row->status,
                'kind' => (string) $row->kind,
                'template_key' => $row->template_key === null ? null : (string) $row->template_key,
                'space_id' => $row->space_id === null ? null : (int) $row->space_id,
                'space_title' => $row->space_title === null ? null : (string) $row->space_title,
                'subject' => (string) $row->subject,
                'error_code' => $row->error_code === null ? null : (string) $row->error_code,
                'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
                'first_to' => $row->first_to === null ? null : (string) $row->first_to,
                'to_count' => (int) $row->to_count,
            ];
        }

        return ['items' => $items, 'truncated' => $truncated];
    }

    /**
     * Détail complet d'une ligne, chargé uniquement à l'ouverture.
     *
     * @return array<string, mixed>|null
     */
    public function detail(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $row = $this->db->selectOneOrNull(<<<'SQL'
            SELECT m.id, m.space_id, m.status, m.kind, m.template_key, m.locale,
                   m.to_json, m.cc_json, m.bcc_json, m.subject, m.body_html, m.body_text,
                   m.attachments, m.from_email, m.from_name, m.message_id,
                   m.error_code, m.error_class, m.error_message, m.job_id, m.user_id,
                   m.created_at, m.finished_at, m.duration_ms,
                   s.title AS space_title
              FROM mail_logs m
              LEFT JOIN spaces s ON s.id = m.space_id
             WHERE m.id = %
             LIMIT 1
            SQL, [$id]);

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'space_id' => $row->space_id === null ? null : (int) $row->space_id,
            'space_title' => $row->space_title === null ? null : (string) $row->space_title,
            'status' => (string) $row->status,
            'kind' => (string) $row->kind,
            'template_key' => $row->template_key === null ? null : (string) $row->template_key,
            'locale' => $row->locale === null ? null : (string) $row->locale,
            'to' => self::decodeAddresses($row->to_json),
            'cc' => self::decodeAddresses($row->cc_json),
            'bcc' => self::decodeAddresses($row->bcc_json),
            'subject' => (string) $row->subject,
            'body_html' => (string) $row->body_html,
            'body_text' => $row->body_text === null ? null : (string) $row->body_text,
            'attachments' => self::decodeAttachments($row->attachments),
            'from_email' => $row->from_email === null ? null : (string) $row->from_email,
            'from_name' => $row->from_name === null ? null : (string) $row->from_name,
            'message_id' => $row->message_id === null ? null : (string) $row->message_id,
            'error_code' => $row->error_code === null ? null : (string) $row->error_code,
            'error_class' => $row->error_class === null ? null : (string) $row->error_class,
            'error_message' => $row->error_message === null ? null : (string) $row->error_message,
            'job_id' => $row->job_id === null ? null : (int) $row->job_id,
            'user_id' => $row->user_id === null ? null : (int) $row->user_id,
            'created_at' => (string) $row->created_at,
            'finished_at' => $row->finished_at === null ? null : (string) $row->finished_at,
            'duration_ms' => $row->duration_ms === null ? null : (int) $row->duration_ms,
        ];
    }

    /** @return list<array{email:string, name:string}> */
    private static function decodeAddresses(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $addresses = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $email = is_string($row['email'] ?? null) ? $row['email'] : '';
            if ($email === '') {
                continue;
            }
            $addresses[] = [
                'email' => $email,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
            ];
        }

        return $addresses;
    }

    /** @return list<array{name:string, size:?int}> */
    private static function decodeAttachments(mixed $json): array
    {
        if (!is_string($json) || $json === '') {
            return [];
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return [];
        }

        $attachments = [];
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $attachments[] = [
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'size' => is_int($row['size'] ?? null) ? $row['size'] : null,
            ];
        }

        return $attachments;
    }

    /**
     * Remplace toutes les occurrences des secrets par [SECRET].
     *
     * `strtr()` traite toutes les clés en un seul passage, dans l'ordre des
     * plus longues : une valeur insérée n'est jamais masquée à nouveau. La
     * variante échappée en HTML couvre les secrets passés par
     * `htmlspecialchars()` dans un modèle.
     *
     * @param list<string> $secrets
     */
    public static function maskSecrets(?string $value, array $secrets): ?string
    {
        if ($value === null || $value === '' || $secrets === []) {
            return $value;
        }

        $map = [];
        foreach ($secrets as $secret) {
            if (!is_string($secret) || trim($secret) === '') {
                continue;
            }

            // Une valeur très courte masquerait des mots ordinaires du message.
            $length = mb_check_encoding($secret, 'UTF-8')
                ? mb_strlen($secret, 'UTF-8')
                : strlen($secret);
            if ($length < self::MIN_SECRET_LENGTH) {
                continue;
            }

            $map[$secret] = self::SECRET_PLACEHOLDER;
            $escaped = htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($escaped !== $secret) {
                $map[$escaped] = self::SECRET_PLACEHOLDER;
            }
        }

        return $map === [] ? $value : strtr($value, $map);
    }

    /**
     * @param string|array<int|string, string> $addresses
     * @return list<array{email:string, name:string}>
     */
    private static function normaliseAddresses(string|array $addresses): array
    {
        if (is_string($addresses)) {
            $addresses = [$addresses];
        }

        $result = [];
        foreach ($addresses as $email => $name) {
            if (is_int($email)) {
                $email = $name;
                $name = '';
            }
            if (!is_string($email) || $email === '') {
                continue;
            }

            $result[] = [
                'email' => self::truncate($email, 255) ?? '',
                'name' => self::truncate(is_string($name) ? $name : '', 255) ?? '',
            ];

            // Un message normal ne dépasse pas cette borne ; le journal reste borné.
            if (count($result) >= 100) {
                break;
            }
        }

        return $result;
    }

    /**
     * @param array<int, string|array{path?:string, name?:string}> $attachments
     * @return list<array{name:string, size:?int}>
     */
    private static function normaliseAttachments(array $attachments): array
    {
        $result = [];
        foreach ($attachments as $attachment) {
            if (is_string($attachment)) {
                $path = $attachment;
                $name = basename($attachment);
            } elseif (is_array($attachment)) {
                $path = is_string($attachment['path'] ?? null) ? $attachment['path'] : '';
                $name = is_string($attachment['name'] ?? null) && $attachment['name'] !== ''
                    ? $attachment['name']
                    : basename($path);
            } else {
                continue;
            }

            // Ne jamais conserver le chemin serveur : seul le nom et la taille servent.
            $size = $path !== '' && is_readable($path) ? filesize($path) : false;
            $result[] = [
                'name' => self::truncate($name, 255) ?? '',
                'size' => $size === false ? null : (int) $size,
            ];

            if (count($result) >= 50) {
                break;
            }
        }

        return $result;
    }

    /** @param array<int, mixed> $value */
    private static function json(array $value): string
    {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return $encoded === false ? '[]' : $encoded;
    }

    private static function normaliseErrorCode(string $code): string
    {
        $code = strtoupper(trim($code));

        return preg_match('/^[A-Z][A-Z0-9_]{0,49}$/D', $code) === 1 ? $code : 'SMTP_SEND_FAILED';
    }

    /** Un objet et un message d'erreur tiennent sur une ligne contrôlée. */
    private static function singleLine(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value;

        return self::truncate($value, $maxLength);
    }

    private static function truncate(?string $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }

        return mb_check_encoding($value, 'UTF-8')
            ? mb_substr($value, 0, $maxLength, 'UTF-8')
            : substr($value, 0, $maxLength);
    }
}
