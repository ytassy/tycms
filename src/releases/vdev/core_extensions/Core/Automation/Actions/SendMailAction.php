<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class SendMailAction extends BaseAutomationAction
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
            return $this->coreMailHelper->sendTemplate(
                $template,
                $to,
                $vars,
                $context->spaceId,
            );
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
