<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use RuntimeException;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageFileList;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\Request\WebRequest;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFMedia extends BaseWF
{
    use TraitAllHelpers;

    /** @return array{spaces:list<array<string,mixed>>,maxUploadSize:int,allowedExtensions:list<string>,editableExtensions:list<string>,maxEditableSize:int} */
    public function getMediaDirectory(): array
    {
        $spaces = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            if (!$this->canRead($space)) {
                continue;
            }
            $spaces[] = [
                'id' => $space->id,
                'title' => $space->title,
                'canCrud' => $this->canCrud($space),
            ];
        }

        return [
            'spaces' => $spaces,
            'maxUploadSize' => $this->coreMediaHelper->getMaxUploadSize(),
            'allowedExtensions' => $this->coreMediaHelper->getAllowedExtensions(),
            'editableExtensions' => $this->coreMediaHelper->getEditableExtensions(),
            'maxEditableSize' => $this->coreMediaHelper->getMaxEditableSize(),
        ];
    }

    /** @return array{path:string,parentPath:?string,entries:list<array<string,mixed>>,tree:list<array<string,mixed>>} */
    public function listDirectory(int $spaceId, string $path = ''): array
    {
        $space = $this->getSpaceForRead($spaceId);
        return $this->runMediaAction(fn (): array => $this->coreMediaHelper->listDirectory($space, $path));
    }

    public function pageFileList(int $spaceId, object $options, string $locale = 'fr'): array
    {
        $space = $this->getSpaceForRead($spaceId);
        return $this->runMediaAction(function () use ($space, $options, $locale): array {
            $files = $this->corePageFileList->files($space, $options);
            return ['files' => $files, 'html' => PageFileList::renderFiles($files, $options, $locale)];
        });
    }

    public function createDirectory(int $spaceId, string $path, string $name): void
    {
        $space = $this->getSpaceForCrud($spaceId);
        $this->runMediaAction(function () use ($space, $path, $name): void {
            $this->coreMediaHelper->createDirectory($space, $path, $name);
        });
    }

    public function uploadFile(int $spaceId, string $path, WebRequest $request, bool $overwrite = false): void
    {
        $space = $this->getSpaceForCrud($spaceId);
        $file = $request->file('file');
        if (!is_array($file)) {
            ExceptionResponse::throwWarning('Aucun fichier n’a été téléversé.');
        }

        $this->runMediaAction(function () use ($space, $path, $file, $overwrite): void {
            $this->coreMediaHelper->uploadFile($space, $path, $file, $overwrite);
        });
    }

    public function rename(int $spaceId, string $path, string $name): void
    {
        $space = $this->getSpaceForCrud($spaceId);
        $this->runMediaAction(function () use ($space, $path, $name): void {
            $this->coreMediaHelper->rename($space, $path, $name);
        });
    }

    public function move(int $spaceId, string $path, string $destinationPath): void
    {
        $space = $this->getSpaceForCrud($spaceId);
        $this->runMediaAction(function () use ($space, $path, $destinationPath): void {
            $this->coreMediaHelper->move($space, $path, $destinationPath);
        });
    }

    public function delete(int $spaceId, string $path): void
    {
        $space = $this->getSpaceForCrud($spaceId);
        $this->runMediaAction(function () use ($space, $path): void {
            $this->coreMediaHelper->delete($space, $path);
        });
    }

    /** @return array{content:string,version:string} */
    public function readEditableFile(int $spaceId, string $path): array
    {
        $space = $this->getSpaceForRead($spaceId);
        return $this->runMediaAction(fn (): array => $this->coreMediaHelper->readEditableFile($space, $path));
    }

    public function createEditableFile(int $spaceId, string $path, string $name, string $content): string
    {
        $space = $this->getSpaceForCrud($spaceId);
        return $this->runMediaAction(fn (): string => $this->coreMediaHelper->createEditableFile($space, $path, $name, $content));
    }

    public function saveEditableFile(int $spaceId, string $path, string $content, string $version): string
    {
        $space = $this->getSpaceForCrud($spaceId);
        return $this->runMediaAction(fn (): string => $this->coreMediaHelper->saveEditableFile($space, $path, $content, $version));
    }

    private function getSpaceForRead(int $spaceId): Space
    {
        $space = $this->getSpaceOrFail($spaceId);
        if (!$this->canRead($space)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour consulter les médias de cet espace.');
        }
        return $space;
    }

    private function getSpaceForCrud(int $spaceId): Space
    {
        $space = $this->getSpaceForRead($spaceId);
        if (!$this->canCrud($space)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour modifier les médias de cet espace.');
        }
        return $space;
    }

    private function getSpaceOrFail(int $spaceId): Space
    {
        if ($spaceId < 1) {
            ExceptionResponse::throwWarning('Espace média invalide.');
        }
        $space = $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null;
        if ($space === null) {
            ExceptionResponse::throwWarning('Espace média introuvable.');
        }
        return $space;
    }

    private function canRead(Space $space): bool
    {
        return $this->coreAclHelper->hasGlobalPermission(Permissions::MEDIA_READ, $space->id);
    }

    private function canCrud(Space $space): bool
    {
        return $this->coreAclHelper->hasGlobalPermission(Permissions::MEDIA_CRUD, $space->id);
    }

    private function runMediaAction(callable $action): mixed
    {
        try {
            return $action();
        } catch (RuntimeException $exception) {
            // Préserver les erreurs de stockage qualifiées, sans journaliser les validations usuelles.
            ExceptionResponse::throwWarning($exception->getMessage(), $exception instanceof LogEventException ? $exception : null);
        }
    }
}
