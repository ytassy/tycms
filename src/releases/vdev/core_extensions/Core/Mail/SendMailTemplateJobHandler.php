<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\BaseJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobRequest;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;

/**
 * Envoi différé d'un modèle d'e-mail.
 *
 * Le payload porte la clé du modèle, le destinataire et les variables
 * d'affichage ; le rendu reste dans `MailHelper` au moment de l'exécution, avec
 * la configuration SMTP du Space.
 */
final class SendMailTemplateJobHandler extends BaseJobHandler
{
    public const JOB_TYPE = 'core.mail_template';

    public const JOB_LABEL = 'Envoi d’un modèle d’e-mail';

    /** Durée attendue : un envoi SMTP court sur mutualisé. */
    public const JOB_MIN_TIME = 3;

    public const JOB_MAX_ATTEMPTS = 2;

    /** @param array<string, scalar|null> $vars */
    public static function create(string $template, string $to, array $vars = [], ?int $spaceId = null): JobRequest
    {
        return new JobRequest(self::JOB_TYPE, [
            'template' => $template,
            'to' => $to,
            'vars' => $vars,
        ], [
            'space_id' => $spaceId,
        ]);
    }

    public function handle(JobContext $context, array $payload): JobResult
    {
        $template = is_string($payload['template'] ?? null) ? trim($payload['template']) : '';
        $to = is_string($payload['to'] ?? null) ? trim($payload['to']) : '';
        $vars = is_array($payload['vars'] ?? null) ? $payload['vars'] : [];

        if ($template === '' || filter_var($to, FILTER_VALIDATE_EMAIL) === false || $context->spaceId === null) {
            return JobResult::failure('Payload de modèle d’e-mail invalide.');
        }

        $sent = $this->coreMailHelper->sendTemplate(
            $template,
            $to,
            $vars,
            $context->spaceId,
        );

        return $sent
            ? JobResult::success()
            : JobResult::failure('Le modèle d’e-mail n’a pas pu être envoyé.');
    }
}
