<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailHtmlSanitizer;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobQueue;
use YTassy\Tycms\CoreExts\Core\Mail\SendMailHtmlJobHandler;

/** Envoie un corps HTML libre, purifié avec le profil e-mail du site. */
final class SendMailAction extends BaseAutomationAction
{
    /** Aligné sur la limite de contenu des modèles d'e-mail. */
    private const MAX_BODY_BYTES = 131072;

    public function execute(ActionContext $context, array $params): mixed
    {
        $to = ValueTools::assertString($params['to'] ?? null, $context->span);
        $subject = ValueTools::assertString($params['subject'] ?? null, $context->span);
        $htmlBody = ValueTools::assertString($params['htmlBody'] ?? null, $context->span);

        $this->assertSubject($subject, $context);
        $htmlBody = $this->purifyBody($htmlBody, $context);

        try {
            $this->queue()->enqueue(
                SendMailHtmlJobHandler::create($to, $subject, $htmlBody, $context->spaceId),
            );
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }

        return true;
    }

    /** Le scénario n'envoie plus le message : il le confie à la file. */
    private function queue(): JobQueue
    {
        /** @var JobQueue $queue */
        $queue = $this->getSharedExtensionsClass(JobQueue::class);

        return $queue;
    }

    /** L'objet reste sur une seule ligne : un retour introduirait un en-tête. */
    private function assertSubject(string $subject, ActionContext $context): void
    {
        if (!mb_check_encoding($subject, 'UTF-8')
            || trim($subject) === ''
            || strlen($subject) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $subject) === 1) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                "Objet d'e-mail invalide : une ligne de 255 octets au maximum est attendue.",
                $context->span,
            );
        }
    }

    /**
     * Le scénario ne doit jamais pouvoir injecter de balise active dans un
     * message : le profil e-mail retire scripts, iframes, formulaires et
     * attributs dangereux avant l'envoi.
     */
    private function purifyBody(string $htmlBody, ActionContext $context): string
    {
        if (!mb_check_encoding($htmlBody, 'UTF-8') || strlen($htmlBody) > self::MAX_BODY_BYTES) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                "Contenu HTML invalide ou trop long (maximum 131072 octets).",
                $context->span,
            );
        }

        $purified = EmailHtmlSanitizer::sanitize($htmlBody);
        if (trim(strip_tags($purified)) === '' && !str_contains($purified, '<img')) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Le contenu HTML est vide après purification.',
                $context->span,
            );
        }

        return $purified;
    }
}