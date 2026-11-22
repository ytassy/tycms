<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\WF\BaseWF;

class WFFont extends BaseWF
{
    use TraitCoreSharedHelpers;

    public function library(): array
    {
        $canManage = $this->canManage();
        $spaces = $this->coreAclHelper->getSpacesHavingGlobalPermission([Permissions::FONT_ADD]);
        if (!$canManage && $spaces === []) {
            throw new AccessDeniedException();
        }

        // Les droits d'ajout restent liés aux espaces autorisés, malgré le stockage commun.
        $catalog = $this->coreFontHelper->catalog();
        $families = [];
        foreach ($catalog['families'] as $id => $font) {
            $variants = $font['variants'];
            usort($variants, static fn (string $a, string $b): int =>
                [str_ends_with($a, 'i') ? 1 : 0, abs((int) $a - 400), (int) $a]
                <=> [str_ends_with($b, 'i') ? 1 : 0, abs((int) $b - 400), (int) $b]);
            $families[] = [
                'id' => $id, 'family' => $font['family'], 'category' => $font['category'],
                'previewVariant' => $variants[0],
            ];
        }
        return [
            'families' => $families, 'installed' => (object) $this->coreFontHelper->installed(),
            'catalogUpdatedAt' => $catalog['updatedAt'] ?? null,
            'canManage' => $canManage, 'spaceIds' => $canManage ? array_values(array_unique([1, ...$spaces])) : $spaces,
        ];
    }

    public function install(int $spaceId, string $id): array
    {
        if (!$this->canManage() && !$this->coreAclHelper->hasGlobalPermission(Permissions::FONT_ADD, $spaceId)) {
            throw new AccessDeniedException();
        }
        if (!isset($this->coreSpaceHelper->getSpacesByIds([$spaceId])[$spaceId])) {
            ExceptionResponse::throwWarning('Espace introuvable.');
        }

        try {
            return $this->coreFontHelper->install($id);
        } catch (RuntimeException $exception) {
            // Le nom soumis et les URL de téléchargement restent hors du contexte.
            throw new ExceptionResponse(new ApiResponse(['error' => true, 'message' => $exception->getMessage()], 422),
                new LogEventException('FONT_INSTALL_FAILED', 'L’installation d’une police a échoué.', 'fonts',
                    ['space_id' => $spaceId], previous: $exception));
        }
    }

    public function refreshCatalog(): array
    {
        $this->assertManage();
        try {
            return $this->coreFontHelper->refreshCatalog();
        } catch (RuntimeException $exception) {
            throw new ExceptionResponse(new ApiResponse(['error' => true, 'message' => $exception->getMessage()], 422),
                new LogEventException('FONT_CATALOG_REFRESH_FAILED', 'L’actualisation du catalogue de polices a échoué.', 'fonts',
                    ['space_id' => 1], previous: $exception));
        }
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->assertManage();
        try {
            $this->coreFontHelper->setEnabled($id, $enabled);
        } catch (RuntimeException $exception) {
            throw new ExceptionResponse(new ApiResponse(['error' => true, 'message' => $exception->getMessage()], 422), $exception);
        }
    }

    public function delete(string $id): void
    {
        $this->assertManage();
        try {
            $this->coreFontHelper->delete($id);
        } catch (RuntimeException $exception) {
            throw new ExceptionResponse(new ApiResponse(['error' => true, 'message' => $exception->getMessage()], 422), $exception);
        }
    }

    private function assertManage(): void
    {
        if (!$this->canManage()) {
            throw new AccessDeniedException();
        }
    }

    private function canManage(): bool
    {
        // Une attribution dans un espace enfant ne donne jamais la gestion de la bibliothèque.
        return $this->coreAclHelper->hasGlobalPermission(Permissions::FONT_MANAGE, 1);
    }
}
