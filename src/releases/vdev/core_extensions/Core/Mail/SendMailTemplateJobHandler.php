<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\BaseJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobRequest;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;

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

    /**
     * Durée attendue d'un envoi SMTP sur mutualisé.
     *
     * Deux secondes, pas trois : le worker ne démarre un job que si le budget
     * restant couvre cette valeur, et une valeur trop haute bloque le lot
     * suivant. La marge du bail (15 s) absorbe les dépassements.
     */
    public const JOB_MIN_TIME = 2;

    public const JOB_MAX_ATTEMPTS = 2;

    /**
     * Envoi de scénario : rien n'attend pendant le rendu, il peut passer après
     * les messages transactionnels. Une fabrique peut demander mieux au cas par
     * cas si le scénario porte un message critique.
     */
    public const JOB_PRIORITY = JobType::PRIORITY_LOW;

    /** @param array<string, scalar|null> $vars */
    public static function create(
        string $template,
        string $to,
        array $vars = [],
        ?int $spaceId = null,
        ?int $priority = null,
    ): JobRequest {
        $options = ['space_id' => $spaceId];

        if ($priority !== null) {
            $options['priority'] = $priority;
        }

        return new JobRequest(self::JOB_TYPE, [
            'template' => $template,
            'to' => $to,
            'vars' => $vars,
        ], $options);
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
            jobId: $context->jobId,
            userId: $context->execUserId,
        );

        return $sent
            ? JobResult::success()
            : JobResult::failure('Le modèle d’e-mail n’a pas pu être envoyé.');
    }
}
