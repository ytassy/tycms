<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Controller;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\TyCatalog\Exception\PublicationException;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogToken;
use YTassy\Tycms\CoreExts\TyCatalog\Traits\TraitTyCatalogHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Request\WebRequest;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;

/**
 * Publication distante par jeton Bearer.
 *
 * Aucune session ni CSRF : le jeton porte l'identité du créateur, il est limité
 * à un catalogue et à des droits explicites. Le HTTPS est assuré par le
 * déploiement et rappelé par le client de publication.
 */
#[Controller(name: 'tycms_catalog_api_', isApi: true, path: 'tycms-catalog-api/v1', withoutCsrf: true)]
final class TyCatalogPublishApiController extends BaseController
{
    use TraitTyCatalogHelpers;

    #[ControllerMethod(name: 'deposit', path: 'deposit', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'catalogId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'displayName', required: false, varType: VarType::STRING)]
    #[ControllerParam3(name: 'description', required: false, varType: VarType::STRING)]
    public function deposit(
        int $catalogId,
        WebRequest $_request,
        string $displayName = '',
        string $description = '',
    ): ApiResponse {
        $this->assertSecureTransport();
        $context = $this->tyCatalogTokenHelper->authenticate(
            (string) $_request->header('Authorization', ''),
            $catalogId,
            CatalogToken::ABILITY_DEPOSIT,
        );

        $archive = $_request->file('archive');
        $notes = $_request->file('notes');
        $signature = $_request->file('signature');
        if (!is_array($archive) || !is_array($notes) || !is_array($signature)) {
            ExceptionResponse::throwWarning('Le ZIP, les notes et l’enveloppe de signature sont requis.');
        }

        try {
            $result = $this->tyCatalogPublicationHelper->depositLocal(
                $context['catalog'],
                $archive,
                $notes,
                $signature,
                $this->optionalText($displayName, 150),
                $this->optionalText($description, 2000),
                $context['token']->created_by,
            );
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        $this->logAction($context, 'deposit');
        return new ApiResponse($result);
    }

    #[ControllerMethod(name: 'reference', path: 'reference', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionDeposit.php')]
    public function reference(object $data): ApiResponse
    {
        $this->assertSecureTransport();
        $catalogId = (int) ($data->catalogId ?? 0);
        $context = $this->tyCatalogTokenHelper->authenticate(
            (string) $this->request->header('Authorization', ''),
            $catalogId,
            CatalogToken::ABILITY_DEPOSIT,
        );

        $archiveUrl = is_string($data->archiveUrl ?? null) ? trim($data->archiveUrl) : '';
        $notesUrl = is_string($data->notesUrl ?? null) ? trim($data->notesUrl) : '';
        $envelope = is_string($data->envelope ?? null) ? $data->envelope : '';
        if ($archiveUrl === '' || $notesUrl === '' || $envelope === '') {
            ExceptionResponse::throwWarning('Les URL externes et l’enveloppe de signature sont requises.');
        }

        try {
            $result = $this->tyCatalogPublicationHelper->depositExternal(
                $context['catalog'],
                $archiveUrl,
                $notesUrl,
                $envelope,
                $this->optionalText($data->displayName ?? null, 150),
                $this->optionalText($data->description ?? null, 2000),
                $context['token']->created_by,
            );
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        $this->logAction($context, 'reference');
        return new ApiResponse($result);
    }

    #[ControllerMethod(name: 'publish', path: 'publish', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionAction.php')]
    public function publish(object $data): ApiResponse
    {
        $context = $this->authorizeAction($data);
        try {
            $this->tyCatalogPublicationHelper->publish(
                $context['catalog'], (int) $data->versionId, $context['token']->created_by,
            );
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        $this->logAction($context, 'publish');
        $cleanup = $this->tyCatalogPublicationHelper->cleanupSafely($context['catalog']);
        return new ApiResponse(['published' => true, 'cleanup' => $cleanup]);
    }

    #[ControllerMethod(name: 'withdraw', path: 'withdraw', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionAction.php')]
    public function withdraw(object $data): ApiResponse
    {
        $context = $this->authorizeAction($data);
        try {
            $this->tyCatalogPublicationHelper->withdraw(
                $context['catalog'], (int) $data->versionId, $context['token']->created_by,
            );
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        $this->logAction($context, 'withdraw');
        return new ApiResponse(['withdrawn' => true]);
    }

    #[ControllerMethod(name: 'reactivate', path: 'reactivate', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'data', required: true, schemaFile: __DIR__ . '/Schemas/VersionAction.php')]
    public function reactivate(object $data): ApiResponse
    {
        $context = $this->authorizeAction($data);
        try {
            $this->tyCatalogPublicationHelper->reactivate(
                $context['catalog'], (int) $data->versionId, $context['token']->created_by,
            );
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        $this->logAction($context, 'reactivate');
        $cleanup = $this->tyCatalogPublicationHelper->cleanupSafely($context['catalog']);
        return new ApiResponse(['reactivated' => true, 'cleanup' => $cleanup]);
    }

    /** @return array{token:CatalogToken,catalog:\YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog} */
    private function authorizeAction(object $data): array
    {
        $this->assertSecureTransport();
        return $this->tyCatalogTokenHelper->authenticate(
            (string) $this->request->header('Authorization', ''),
            (int) ($data->catalogId ?? 0),
            CatalogToken::ABILITY_PUBLISH,
        );
    }

    private function assertSecureTransport(): void
    {
        // En production, un jeton ne doit jamais circuler en clair sur HTTP.
        if (!$this->request->is_https && !$this->app->getConf()->dev) {
            throw new AccessDeniedException();
        }
    }

    private function optionalText(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $maxLength) {
            throw new PublicationException('Un champ texte dépasse la longueur autorisée.');
        }

        return $value;
    }

    /** @param array{token:CatalogToken,catalog:object} $context */
    private function logAction(array $context, string $action): void
    {
        $this->logInfo(
            'Action distante TyCatalog.',
            ['catalog_id' => $context['catalog']->id, 'token_id' => $context['token']->id, 'action' => $action],
            'TY_CATALOG_TOKEN_ACTION',
            'extensions',
        );
    }
}
