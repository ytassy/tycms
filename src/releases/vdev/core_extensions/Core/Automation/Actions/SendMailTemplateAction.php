<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobQueue;
use YTassy\Tycms\CoreExts\Core\Mail\SendMailTemplateJobHandler;

/** Envoie un modèle d'e-mail déclaré dans le Space du run. */
final class SendMailTemplateAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $template = ValueTools::assertString($params['template'] ?? null, $context->span);
        $to = ValueTools::assertString($params['to'] ?? null, $context->span);
        $vars = $params['vars'] ?? [];

        if (!is_array($vars)) {
            throw ValueTools::typeError('array', $vars, $context->span);
        }

        try {
            $this->queue()->enqueue(
                SendMailTemplateJobHandler::create($template, $to, $vars, $context->spaceId),
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
}