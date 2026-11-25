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
                self::exceptionMessage($exception),
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

    /**
     * Message métier d'un `ExceptionResponse`.
     *
     * `ExceptionResponse` passe une chaîne vide à `\Exception` et conserve le
     * texte dans sa réponse : `getMessage()` renverrait toujours `''`. Sans ce
     * déballage, un refus du WF (droit insuffisant, règle métier) arriverait au
     * scénario comme un générique « L'action a échoué », sans explication.
     */
    private static function exceptionMessage(ExceptionResponse $exception): string
    {
        // Le texte vit dans la réponse portée par l'exception ; la classe
        // expose `getMessage()` et non une clé `message` du tableau de données.
        $message = $exception->getResponse()->getMessage();

        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        // Une exception d'origine porte parfois le message utile.
        $cause = $exception->exception?->getMessage();

        return is_string($cause) && trim($cause) !== '' ? $cause : 'L’action a échoué.';
    }
}
