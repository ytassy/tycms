<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\TyCatalog\Exception\PublicationException;
use YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog;
use YTassy\Tycms\CoreExts\TyCatalog\Permissions;
use YTassy\Tycms\CoreExts\TyCatalog\Traits\TraitTyCatalogHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Request\WebRequest;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/**
 * Dépôt et publication des versions d'un catalogue.
 *
 * Le droit de déposer un brouillon (EDIT) ne suffit pas à publier : la
 * transition de publication exige PUBLISH. Le catalogue est relu en base et
 * son Space réel doit correspondre au Space courant, sauf pour ROOT qui peut
 * administrer les catalogues de tous les Espaces.
 */
final class WFTyCatalogPublication extends BaseWF
{
    use TraitAllHelpers;
    use TraitTyCatalogHelpers;

    /** @return array<string, mixed> */
    public function getVersions(int $catalogId): array
    {
        $catalog = $this->catalog($catalogId, Permissions::VIEW);
        $spaceId = (int) $this->coreSpaceHelper->getCurrentSpace()->id;
        $isRoot = $this->coreAclHelper->isRoot();

        return [
            'catalog' => ['id' => $catalog->id, 'name' => $catalog->name, 'slug' => $catalog->slug],
            'can_edit' => $isRoot || $this->coreAclHelper->hasGlobalPermission(Permissions::EDIT, $spaceId),
            'can_publish' => $isRoot || $this->coreAclHelper->hasGlobalPermission(Permissions::PUBLISH, $spaceId),
            'versions' => $this->tyCatalogPublicationHelper->listVersions($catalog)['versions'],
        ];
    }

    /** @return array{version_id:int} */
    public function depositLocal(
        int $catalogId,
        WebRequest $request,
        ?string $displayName,
        ?string $description,
    ): array {
        $catalog = $this->catalog($catalogId, Permissions::EDIT);
        $archive = $request->file('archive');
        $notes = $request->file('notes');
        $signature = $request->file('signature');

        if (!is_array($archive) || !is_array($notes) || !is_array($signature)) {
            throw new PublicationException('Le ZIP, les notes et l’enveloppe de signature sont requis.');
        }

        try {
            return $this->tyCatalogPublicationHelper->depositLocal(
                $catalog, $archive, $notes, $signature,
                $this->optionalText($displayName, 150),
                $this->optionalText($description, 2000),
                $this->session->getUserId(),
            );
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }
    }

    /** @return array{version_id:int} */
    public function depositExternal(object $data): array
    {
        $catalog = $this->catalog((int) ($data->catalogId ?? 0), Permissions::EDIT);
        $archiveUrl = is_string($data->archiveUrl ?? null) ? trim($data->archiveUrl) : '';
        $notesUrl = is_string($data->notesUrl ?? null) ? trim($data->notesUrl) : '';
        $envelope = is_string($data->envelope ?? null) ? $data->envelope : '';

        if ($archiveUrl === '' || $notesUrl === '' || $envelope === '') {
            throw new PublicationException('Les URL externes et l’enveloppe de signature sont requises.');
        }

        try {
            return $this->tyCatalogPublicationHelper->depositExternal(
                $catalog, $archiveUrl, $notesUrl, $envelope,
                $this->optionalText($data->displayName ?? null, 150),
                $this->optionalText($data->description ?? null, 2000),
                $this->session->getUserId(),
            );
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }
    }

    public function publish(int $catalogId, int $versionId): array
    {
        $catalog = $this->catalog($catalogId, Permissions::PUBLISH);
        try {
            $this->tyCatalogPublicationHelper->publish($catalog, $versionId, $this->session->getUserId());
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        return $this->tyCatalogPublicationHelper->cleanupSafely($catalog);
    }

    public function withdraw(int $catalogId, int $versionId): void
    {
        $catalog = $this->catalog($catalogId, Permissions::PUBLISH);
        try {
            $this->tyCatalogPublicationHelper->withdraw($catalog, $versionId, $this->session->getUserId());
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }
    }

    public function reactivate(int $catalogId, int $versionId): array
    {
        $catalog = $this->catalog($catalogId, Permissions::PUBLISH);
        try {
            $this->tyCatalogPublicationHelper->reactivate($catalog, $versionId, $this->session->getUserId());
        } catch (PublicationException $exception) {
            ExceptionResponse::throwWarning($exception->getMessage());
        }

        return $this->tyCatalogPublicationHelper->cleanupSafely($catalog);
    }

    /** @return array{versions_deleted:int,archives_deleted:int,errors:list<string>} */
    public function cleanup(int $catalogId): array
    {
        $catalog = $this->catalog($catalogId, Permissions::PUBLISH);

        return $this->tyCatalogPublicationHelper->cleanupSafely($catalog);
    }

    private function catalog(int $catalogId, string $permission): Catalog
    {
        $spaceId = (int) $this->coreSpaceHelper->getCurrentSpace()->id;
        $isRoot = $this->coreAclHelper->isRoot();
        if (!$isRoot && !$this->coreAclHelper->hasGlobalPermission($permission, $spaceId)) {
            throw new AccessDeniedException();
        }

        $catalog = $this->tyCatalogAdminHelper->get($catalogId);
        if ($catalog === null || (!$isRoot && $catalog->space_id !== $spaceId)) {
            throw new AccessDeniedException();
        }

        return $catalog;
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
}
