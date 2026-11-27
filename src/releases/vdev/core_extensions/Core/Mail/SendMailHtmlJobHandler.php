<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\BaseJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobRequest;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailHtmlSanitizer;

/**
 * Envoi différé d'un e-mail à corps HTML libre.
 *
 * Le corps est purifié une première fois au moment de l'enfilement, puis à
 * nouveau à l'exécution : une ligne de file peut avoir été écrite par un autre
 * chemin que la fabrique. Le payload porte le contenu du message, c'est le seul
 * type de job qui le fait ; il ne doit jamais contenir de secret.
 */
final class SendMailHtmlJobHandler extends BaseJobHandler
{
    public const JOB_TYPE = 'core.mail_html';

    public const JOB_LABEL = 'Envoi d’un e-mail libre';

    /**
     * Durée attendue d'un envoi SMTP sur mutualisé.
     *
     * Deux secondes, pas trois : le worker ne démarre un job que si le budget
     * restant couvre cette valeur, et une valeur trop haute bloque le lot
     * suivant. La marge du bail (15 s) absorbe les dépassements.
     */
    public const JOB_MIN_TIME = 2;

    public const JOB_MAX_ATTEMPTS = 2;

    /** Aligné sur la limite du corps dans l'action d'automatisation. */
    public const MAX_BODY_BYTES = 131072;

    public static function create(string $to, string $subject, string $htmlBody, ?int $spaceId = null): JobRequest
    {
        return new JobRequest(self::JOB_TYPE, [
            'to' => $to,
            'subject' => $subject,
            'html' => $htmlBody,
        ], [
            'space_id' => $spaceId,
        ]);
    }

    public function handle(JobContext $context, array $payload): JobResult
    {
        $to = is_string($payload['to'] ?? null) ? trim($payload['to']) : '';
        $subject = is_string($payload['subject'] ?? null) ? trim($payload['subject']) : '';
        $html = is_string($payload['html'] ?? null) ? $payload['html'] : '';

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false
            || $subject === ''
            || strlen($subject) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $subject) === 1
            || trim($html) === ''
            || strlen($html) > self::MAX_BODY_BYTES
            || $context->spaceId === null) {
            return JobResult::failure('Payload d’e-mail invalide.');
        }

        // Le corps a déjà été purifié à l'enfilement ; on ne fait pas confiance
        // à la ligne pour autant.
        $purified = EmailHtmlSanitizer::sanitize($html);

        if (trim(strip_tags($purified)) === '' && !str_contains($purified, '<img')) {
            return JobResult::failure('Le contenu HTML est vide après purification.');
        }

        $sent = $this->coreMailHelper->sendHtml(
            $to,
            $subject,
            $purified,
            sourceSpace: $context->spaceId,
            jobId: $context->jobId,
            userId: $context->execUserId,
        );

        return $sent
            ? JobResult::success()
            : JobResult::failure('L’e-mail n’a pas été accepté par le serveur SMTP.');
    }
}
