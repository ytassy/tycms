<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Response\ExceptionResponse;

/** Base des handlers d'action d'automation. */
abstract class BaseAutomationAction extends BaseHelper implements IAutomationAction
{
    protected function actionException(\Throwable $exception, ActionContext $context): ExecutionException
    {
        if ($exception instanceof ExceptionResponse) {
            return ValueTools::error(
                DiagnosticCode::ACTION_FAILED,
                $exception->getMessage(),
                $context->span,
            );
        }

        // Conserver la cause interne sans exposer son message au scénario.
        $this->logException($exception);

        return ValueTools::error(
            DiagnosticCode::ACTION_FAILED,
            'L’action a échoué.',
            $context->span,
        );
    }
}
