<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use Google\Service\Exception as GoogleServiceException;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

/** Base des actions Google : journalise le détail utile des refus de l'API. */
abstract class GoogleApiAction extends BaseAutomationAction
{
    protected function actionException(\Throwable $exception, ActionContext $context): ExecutionException
    {
        if ($exception instanceof GoogleServiceException) {
            $errors = $exception->getErrors();
            $first = is_array($errors[0] ?? null) ? $errors[0] : [];
            $providerMessage = is_string($first['message'] ?? null)
                ? $first['message']
                : $exception->getMessage();
            $reason = is_string($first['reason'] ?? null) ? $first['reason'] : null;
            $errorCode = $this->errorCode($providerMessage, $reason);
            $operation = (new \ReflectionClass(static::class))->getShortName();

            // Ne pas recopier la réponse brute du fournisseur : seuls des
            // marqueurs techniques bornés et utiles au diagnostic sont gardés.
            $this->logError(
                'Google API a refusé une action d’automatisation.',
                [
                    'google_error' => $errorCode,
                    'google_reason' => $reason,
                    'google_operation' => $operation,
                    'http_status' => $exception->getCode(),
                ],
                'GOOGLE_API_ERROR',
                'automation',
            );
            $this->logException($exception);

            return ValueTools::error(
                DiagnosticCode::ACTION_FAILED,
                $this->userMessage($errorCode, $exception->getCode(), $reason),
                $context->span,
            );
        }

        return parent::actionException($exception, $context);
    }

    private function errorCode(string $message, ?string $reason): string
    {
        $message = strtolower($message);

        if (str_contains($message, 'part of a table')) {
            return 'TABLE_RANGE_CONFLICT';
        }

        if (str_contains($message, 'quota') || str_contains($message, 'rate limit')) {
            return 'QUOTA_EXCEEDED';
        }

        if (str_contains($message, 'permission') || $reason === 'forbidden') {
            return 'PERMISSION_DENIED';
        }

        if (str_contains($message, 'invalid_grant')
            || str_contains($message, 'invalid credentials')
            || str_contains($message, 'unauthorized')) {
            return 'AUTH_INVALID';
        }

        if (str_contains($message, 'not found') || $reason === 'notFound') {
            return 'NOT_FOUND';
        }

        return 'UNKNOWN';
    }

    private function userMessage(string $errorCode, int $status, ?string $reason): string
    {
        return match ($errorCode) {
            'TABLE_RANGE_CONFLICT' => 'La plage Google chevauche un tableau ; utilisez une ligne entière.',
            'QUOTA_EXCEEDED' => 'Quota Google atteint ; réessayez plus tard.',
            'PERMISSION_DENIED' => 'Accès Google refusé ; vérifiez le partage du classeur ou de l’agenda.',
            'AUTH_INVALID' => 'Compte de service Google invalide.',
            'NOT_FOUND' => 'Ressource Google introuvable.',
            default => 'Google API a refusé la requête (HTTP ' . $status
                . ($reason !== null ? ', ' . $reason : '') . ').',
        };
    }
}
