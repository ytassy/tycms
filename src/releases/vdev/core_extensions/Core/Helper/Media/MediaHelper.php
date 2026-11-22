<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Media;

use DirectoryIterator;
use FilesystemIterator;
use finfo;
use RuntimeException;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class MediaHelper extends BaseHelper
{
    private const EDITABLE_EXTENSIONS = ['txt', 'scss'];
    private const MAX_EDITABLE_SIZE = 2 * 1024 * 1024;

    public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);
    }

    /**
     * Validate a public URL selected by a media_file form field against a real
     * file in the configured Space. Empty values represent no selection.
     */
    public function assertSelectableFile(Space $space, string $url, string $mediaType = 'all'): void
    {
        if (!in_array($mediaType, ['all', 'image', 'video', 'audio'], true)) {
            throw new RuntimeException('Type de média non pris en charge.');
        }
        if ($url === '') {
            return;
        }

        $prefix = '/medias/' . rawurlencode($space->medias_dir) . '/';
        if (!str_starts_with($url, $prefix)) {
            throw new RuntimeException('Le média doit appartenir à cet espace.');
        }

        $encodedSegments = explode('/', substr($url, strlen($prefix)));
        foreach ($encodedSegments as $segment) {
            if ($segment === '' || rawurlencode(rawurldecode($segment)) !== $segment) {
                throw new RuntimeException('URL de média invalide.');
            }
        }
        $relativePath = $this->normalizeRelativePath(implode('/', array_map('rawurldecode', $encodedSegments)));
        if ($url !== $this->getPublicUrl($space, $relativePath)) {
            throw new RuntimeException('URL de média invalide.');
        }

        $file = $this->resolveExistingPath($space, $relativePath);
        if (!is_file($file) || !is_readable($file)) {
            throw new RuntimeException('Le fichier média est introuvable ou illisible.');
        }
        if (in_array($mediaType, ['image', 'video', 'audio'], true) && !str_starts_with($this->getMimeType($file), $mediaType . '/')) {
            throw new RuntimeException(match ($mediaType) {
                'image' => 'Le média sélectionné doit être une image.',
                'audio' => 'Le média sélectionné doit être un fichier audio.',
                default => 'Le média sélectionné doit être une vidéo.',
            });
        }
    }

    /** Validate a directly entered public path or HTTP(S) URL. */
    public function assertDirectFileUrl(string $url): void
    {
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F"\x27<>\\\\]/', $url)) {
            throw new RuntimeException('URL de fichier invalide.');
        }
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            $path = rawurldecode((string) (parse_url($url, PHP_URL_PATH) ?: ''));
            if ($path !== '' && !in_array('..', explode('/', $path), true)) {
                return;
            }
        }
        if (filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(strtolower((string) parse_url($url, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            return;
        }
        throw new RuntimeException('Saisissez un chemin local ou une URL HTTP(S) valide.');
    }

    /** @return array{path:string,parentPath:?string,entries:list<array<string,mixed>>,tree:list<array<string,mixed>>} */
    public function listDirectory(Space $space, string $relativePath = '', bool $includeTree = true, int $maxEntries = 0): array
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $directory = $this->resolveExistingPath($space, $relativePath);
        if (!is_dir($directory)) {
            throw new RuntimeException('Le dossier média demandé est introuvable.');
        }

        $entries = [];
        $count = 0;
        foreach (new DirectoryIterator($directory) as $item) {
            if ($item->isDot() || $item->isLink() || str_starts_with($item->getFilename(), '.')) {
                continue;
            }

            // Le rendu public ne doit pas parcourir un dossier de taille illimitée.
            if ($maxEntries > 0 && ++$count > $maxEntries) {
                throw new RuntimeException('Ce dossier contient trop d’éléments (maximum ' . $maxEntries . ').');
            }
            if ($item->isDir()) {
                $entries[] = [
                    'name' => $item->getFilename(),
                    'path' => $this->joinRelativePath($relativePath, $item->getFilename()),
                    'type' => 'directory',
                    'size' => null,
                    'modified' => $item->getMTime(),
                    'extension' => '',
                    'url' => null,
                    'mime' => null,
                ];
                continue;
            }

            if (!$item->isFile()) {
                continue;
            }

            $filePath = $item->getPathname();
            $extension = strtolower((string) pathinfo($item->getFilename(), PATHINFO_EXTENSION));
            $entries[] = [
                'name' => $item->getFilename(),
                'path' => $this->joinRelativePath($relativePath, $item->getFilename()),
                'type' => 'file',
                'size' => $item->getSize(),
                'modified' => $item->getMTime(),
                'extension' => $extension,
                'url' => $this->getPublicUrl($space, $this->joinRelativePath($relativePath, $item->getFilename())),
                'mime' => $this->getMimeType($filePath),
            ];
        }

        usort($entries, static function (array $left, array $right): int {
            if ($left['type'] !== $right['type']) {
                return $left['type'] === 'directory' ? -1 : 1;
            }
            return strnatcasecmp($left['name'], $right['name']);
        });

        return [
            'path' => $relativePath,
            'parentPath' => $relativePath === '' ? null : $this->getParentRelativePath($relativePath),
            'entries' => $entries,
            'tree' => $includeTree ? $this->listFolderTree($this->getSpaceRootPath($space), '') : [],
        ];
    }

    /** @return list<array{name:string,path:string,children:array}> */
    private function listFolderTree(string $directory, string $relativePath): array
    {
        $folders = [];
        foreach (new DirectoryIterator($directory) as $item) {
            if ($item->isDot() || $item->isLink() || !$item->isDir() || str_starts_with($item->getFilename(), '.')) {
                continue;
            }
            $path = $this->joinRelativePath($relativePath, $item->getFilename());
            $folders[] = [
                'name' => $item->getFilename(),
                'path' => $path,
                'children' => is_readable($item->getPathname())
                    ? $this->listFolderTree($item->getPathname(), $path)
                    : [],
            ];
        }
        usort($folders, static fn (array $left, array $right): int => strnatcasecmp($left['name'], $right['name']));
        return $folders;
    }

    public function assertDirectory(Space $space, string $relativePath): void
    {
        $path = $this->resolveExistingPath($space, $this->normalizeRelativePath($relativePath));
        if (!is_dir($path) || !is_readable($path)) throw new RuntimeException('Dossier média introuvable.');
    }

    public function createDirectory(Space $space, string $parentPath, string $name): void
    {
        $parentPath = $this->normalizeRelativePath($parentPath);
        $directory = $this->resolveExistingPath($space, $parentPath);
        if (!is_dir($directory)) {
            throw new RuntimeException('Le dossier parent est introuvable.');
        }

        $name = $this->normalizeDirectoryName($name);
        $targetPath = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_exists($targetPath)) {
            throw new RuntimeException('Un élément porte déjà ce nom dans ce dossier.');
        }
        if (!mkdir($targetPath, 0775)) {
            throw $this->storageFailure($space, 'MEDIA_DIRECTORY_CREATE_FAILED', 'Impossible de créer le dossier média.');
        }
    }

    /** @param array<string,mixed> $uploadedFile */
    public function uploadFile(Space $space, string $parentPath, array $uploadedFile, bool $overwrite = false): void
    {
        $parentPath = $this->normalizeRelativePath($parentPath);
        $directory = $this->resolveExistingPath($space, $parentPath);
        if (!is_dir($directory)) {
            throw new RuntimeException('Le dossier de destination est introuvable.');
        }

        $error = $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->getUploadErrorMessage($error));
        }
        $tmpName = $uploadedFile['tmp_name'] ?? null;
        $size = $uploadedFile['size'] ?? null;
        if (!is_string($tmpName) || !is_file($tmpName) || !is_int($size)) {
            throw new RuntimeException('Fichier téléversé invalide.');
        }
        if ($size < 1 || $size > $this->getMaxUploadSize()) {
            throw new RuntimeException('La taille du fichier dépasse la limite autorisée.');
        }

        $name = $this->normalizeFileName((string) ($uploadedFile['name'] ?? ''));
        $targetPath = $directory . DIRECTORY_SEPARATOR . $name;
        if (file_exists($targetPath) && !$overwrite) {
            throw new RuntimeException('Un fichier porte déjà ce nom dans ce dossier.');
        }

        $temporaryTargetPath = $targetPath . '.upload-' . bin2hex(random_bytes(8));
        if (!move_uploaded_file($tmpName, $temporaryTargetPath)) {
            throw $this->storageFailure($space, 'MEDIA_UPLOAD_WRITE_FAILED', 'Impossible d’enregistrer le fichier téléversé.');
        }

        $backupPath = null;
        if ($overwrite && file_exists($targetPath)) {
            $backupPath = $targetPath . '.replace-' . bin2hex(random_bytes(8));
            if (!rename($targetPath, $backupPath)) {
                @unlink($temporaryTargetPath);
                throw $this->storageFailure($space, 'MEDIA_REPLACE_PREPARE_FAILED', 'Impossible de préparer le remplacement du fichier.');
            }
        }
        if (!rename($temporaryTargetPath, $targetPath)) {
            @unlink($temporaryTargetPath);
            if ($backupPath !== null) {
                if (!@rename($backupPath, $targetPath)) {
                    // L'échec de compensation exige un diagnostic distinct, sans chemin disque.
                    $this->logError('La restauration du média précédent a échoué.', ['space_id' => $space->id],
                        'MEDIA_RESTORE_FAILED', 'media');
                }
            }
            throw $this->storageFailure($space, 'MEDIA_REPLACE_FAILED', 'Impossible de finaliser l’enregistrement du fichier.');
        }
        if ($backupPath !== null) {
            @unlink($backupPath);
        }
    }

    public function rename(Space $space, string $relativePath, string $newName): void
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === '') {
            throw new RuntimeException('Le dossier racine ne peut pas être renommé.');
        }
        $sourcePath = $this->resolveExistingPath($space, $relativePath);
        if (is_link($sourcePath)) {
            throw new RuntimeException('Les liens symboliques ne sont pas pris en charge.');
        }

        $newName = is_dir($sourcePath)
            ? $this->normalizeDirectoryName($newName)
            : $this->normalizeFileName($newName);
        $targetPath = dirname($sourcePath) . DIRECTORY_SEPARATOR . $newName;
        if (file_exists($targetPath)) {
            throw new RuntimeException('Un élément porte déjà ce nom dans ce dossier.');
        }
        if (!rename($sourcePath, $targetPath)) {
            throw $this->storageFailure($space, 'MEDIA_RENAME_FAILED', 'Impossible de renommer cet élément.');
        }
    }

    public function move(Space $space, string $relativePath, string $destinationPath): void
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        $destinationPath = $this->normalizeRelativePath($destinationPath);
        if ($relativePath === '') {
            throw new RuntimeException('Le dossier racine ne peut pas être déplacé.');
        }

        $sourcePath = $this->resolveExistingPath($space, $relativePath);
        $destinationDirectory = $this->resolveExistingPath($space, $destinationPath);
        if (!is_dir($destinationDirectory) || is_link($sourcePath)) {
            throw new RuntimeException('Destination média invalide.');
        }
        if (is_dir($sourcePath) && str_starts_with($destinationDirectory . DIRECTORY_SEPARATOR, $sourcePath . DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Un dossier ne peut pas être déplacé dans lui-même.');
        }

        $targetPath = $destinationDirectory . DIRECTORY_SEPARATOR . basename($sourcePath);
        if (file_exists($targetPath)) {
            throw new RuntimeException('Un élément porte déjà ce nom dans le dossier de destination.');
        }
        if (!rename($sourcePath, $targetPath)) {
            throw $this->storageFailure($space, 'MEDIA_MOVE_FAILED', 'Impossible de déplacer cet élément.');
        }
    }

    public function delete(Space $space, string $relativePath): void
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === '') {
            throw new RuntimeException('Le dossier racine ne peut pas être supprimé.');
        }
        $path = $this->resolveExistingPath($space, $relativePath);
        if (is_dir($path)) {
            $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
            if ($iterator->valid()) {
                throw new RuntimeException('Seuls les dossiers vides peuvent être supprimés.');
            }
            if (!rmdir($path)) {
                throw $this->storageFailure($space, 'MEDIA_DELETE_FAILED', 'Impossible de supprimer ce dossier.');
            }
            return;
        }
        if (!is_file($path) || !unlink($path)) {
            throw $this->storageFailure($space, 'MEDIA_DELETE_FAILED', 'Impossible de supprimer ce fichier.');
        }
    }

    /** @return list<string> */
    public function getAllowedExtensions(): array
    {
        $extensions = $this->app->getConf()->media_allowed_extensions ?? [];
        if (!is_array($extensions)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $extension): string => strtolower(ltrim(trim((string) $extension), '.')),
            $extensions,
        ), static fn (string $extension): bool => preg_match('/^[a-z0-9]{1,10}$/D', $extension) === 1)));
    }

    public function getMaxUploadSize(): int
    {
        $size = $this->app->getConf()->media_max_upload_size ?? 0;
        return is_int($size) && $size > 0 ? $size : 25 * 1024 * 1024;
    }

    /** @return list<string> */
    public function getEditableExtensions(): array
    {
        return array_values(array_intersect(self::EDITABLE_EXTENSIONS, $this->getAllowedExtensions()));
    }

    public function getMaxEditableSize(): int
    {
        return self::MAX_EDITABLE_SIZE;
    }

    /** @return array{content:string,version:string} */
    public function readEditableFile(Space $space, string $relativePath): array
    {
        $path = $this->resolveEditableFile($space, $relativePath);
        if (filesize($path) > self::MAX_EDITABLE_SIZE) {
            throw new RuntimeException('Ce fichier est trop volumineux pour être modifié ici.');
        }
        $content = file_get_contents($path);
        if ($content === false || strlen($content) > self::MAX_EDITABLE_SIZE || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new RuntimeException('Ce fichier ne contient pas de texte UTF-8 éditable.');
        }
        return ['content' => $content, 'version' => hash('sha256', $content)];
    }

    public function createEditableFile(Space $space, string $parentPath, string $name, string $content): string
    {
        $this->validateEditableContent($content);
        $name = $this->normalizeFileName($name);
        $this->assertEditableExtension($name);
        $directory = $this->resolveExistingPath($space, $this->normalizeRelativePath($parentPath));
        if (!is_dir($directory)) {
            throw new RuntimeException('Le dossier de destination est introuvable.');
        }
        $target = $directory . DIRECTORY_SEPARATOR . $name;
        $handle = @fopen($target, 'x');
        if ($handle === false) {
            throw new RuntimeException('Un fichier porte déjà ce nom, ou sa création a échoué.');
        }
        try {
            $written = 0;
            while ($written < strlen($content)) {
                $count = fwrite($handle, substr($content, $written));
                if ($count === false || $count === 0) {
                    throw $this->storageFailure($space, 'MEDIA_EDIT_WRITE_FAILED', 'Impossible d’enregistrer le fichier.');
                }
                $written += $count;
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($target);
            throw $error;
        }
        fclose($handle);
        return hash('sha256', $content);
    }

    public function saveEditableFile(Space $space, string $relativePath, string $content, string $version): string
    {
        $this->validateEditableContent($content);
        $path = $this->resolveEditableFile($space, $relativePath);
        if (!preg_match('/^[a-f0-9]{64}$/D', $version) || hash_file('sha256', $path) !== $version) {
            throw new RuntimeException('Ce fichier a changé depuis son ouverture. Rechargez-le avant de l’enregistrer.');
        }
        $temporary = dirname($path) . DIRECTORY_SEPARATOR . '.edit-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $content, LOCK_EX) !== strlen($content)) {
            @unlink($temporary);
            throw $this->storageFailure($space, 'MEDIA_EDIT_WRITE_FAILED', 'Impossible de préparer l’enregistrement du fichier.');
        }
        $backup = dirname($path) . DIRECTORY_SEPARATOR . '.replace-' . bin2hex(random_bytes(8));
        if (!rename($path, $backup)) {
            @unlink($temporary);
            throw $this->storageFailure($space, 'MEDIA_REPLACE_PREPARE_FAILED', 'Impossible de préparer le remplacement du fichier.');
        }
        if (!rename($temporary, $path)) {
            if (!@rename($backup, $path)) {
                $this->logError('La restauration du média précédent a échoué.', ['space_id' => $space->id],
                    'MEDIA_RESTORE_FAILED', 'media');
            }
            @unlink($temporary);
            throw $this->storageFailure($space, 'MEDIA_REPLACE_FAILED', 'Impossible de finaliser l’enregistrement du fichier.');
        }
        @unlink($backup);
        return hash('sha256', $content);
    }

    private function resolveEditableFile(Space $space, string $relativePath): string
    {
        $relativePath = $this->normalizeRelativePath($relativePath);
        if ($relativePath === '') {
            throw new RuntimeException('Fichier média invalide.');
        }
        $this->assertEditableExtension($relativePath);
        $path = $this->resolveExistingPath($space, $relativePath);
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Fichier média introuvable ou illisible.');
        }
        return $path;
    }

    private function assertEditableExtension(string $name): void
    {
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($extension, $this->getEditableExtensions(), true)) {
            throw new RuntimeException('Ce type de fichier ne peut pas être édité ici.');
        }
    }

    private function validateEditableContent(string $content): void
    {
        if (strlen($content) > self::MAX_EDITABLE_SIZE || str_contains($content, "\0") || preg_match('//u', $content) !== 1) {
            throw new RuntimeException('Le contenu doit être du texte UTF-8 de 1 Mo maximum.');
        }
    }

    private function resolveExistingPath(Space $space, string $relativePath): string
    {
        $rootPath = $this->getSpaceRootPath($space);
        $candidate = $rootPath;
        if ($relativePath !== '') {
            foreach (explode('/', $relativePath) as $segment) {
                $candidate .= DIRECTORY_SEPARATOR . $segment;
                if (is_link($candidate)) {
                    throw new RuntimeException('Les liens symboliques ne sont pas pris en charge.');
                }
            }
        }
        $resolvedPath = realpath($candidate);
        if ($resolvedPath === false || !$this->isWithinRoot($resolvedPath, $rootPath)) {
            throw new RuntimeException('Chemin média invalide.');
        }
        return $resolvedPath;
    }

    private function getSpaceRootPath(Space $space): string
    {
        $rootPath = $space->getMediasDir();
        if (!is_dir($rootPath) && !mkdir($rootPath, 0775, true)) {
            throw $this->storageFailure($space, 'MEDIA_ROOT_UNAVAILABLE', 'Impossible d’accéder au répertoire média de cet espace.');
        }
        $rootPath = realpath($rootPath);
        if ($rootPath === false) {
            throw $this->storageFailure($space, 'MEDIA_ROOT_UNAVAILABLE', 'Répertoire média invalide.');
        }
        return $rootPath;
    }

    /** Ne qualifier que les échecs de stockage, pas les validations ordinaires. */
    private function storageFailure(Space $space, string $code, string $message): LogEventException
    {
        return new LogEventException($code, $message, 'media', ['space_id' => $space->id], 'ERROR');
    }

    private function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new RuntimeException('Chemin média invalide.');
        }

        $segments = explode('/', $path);
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                throw new RuntimeException('Chemin média invalide.');
            }
        }
        return implode('/', $segments);
    }

    private function normalizeDirectoryName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || strlen($name) > 150 || str_contains($name, '/') || str_contains($name, '\\')
            || str_contains($name, "\0") || str_starts_with($name, '.')) {
            throw new RuntimeException('Nom de dossier invalide.');
        }
        return $name;
    }

    private function normalizeFileName(string $name): string
    {
        $name = trim(basename(str_replace('\\', '/', $name)));
        if ($name === '' || strlen($name) > 150 || str_starts_with($name, '.') || str_contains($name, "\0")) {
            throw new RuntimeException('Nom de fichier invalide.');
        }
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        if ($extension === '' || !in_array($extension, $this->getAllowedExtensions(), true)) {
            throw new RuntimeException('Cette extension de fichier n’est pas autorisée.');
        }
        return $name;
    }

    private function getPublicUrl(Space $space, string $relativePath): string
    {
        $segments = array_map('rawurlencode', explode('/', $relativePath));
        return '/medias/' . rawurlencode($space->medias_dir) . ($segments === [] ? '' : '/' . implode('/', $segments));
    }

    private function joinRelativePath(string $parentPath, string $name): string
    {
        return $parentPath === '' ? $name : $parentPath . '/' . $name;
    }

    private function getParentRelativePath(string $path): string
    {
        $position = strrpos($path, '/');
        return $position === false ? '' : substr($path, 0, $position);
    }

    private function isWithinRoot(string $path, string $rootPath): bool
    {
        return $path === $rootPath || str_starts_with($path, $rootPath . DIRECTORY_SEPARATOR);
    }

    private function getMimeType(string $filePath): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        return is_string($mime) ? $mime : 'application/octet-stream';
    }

    private function getUploadErrorMessage(mixed $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'La taille du fichier dépasse la limite autorisée.',
            UPLOAD_ERR_PARTIAL => 'Le fichier n’a été téléversé que partiellement.',
            UPLOAD_ERR_NO_FILE => 'Aucun fichier n’a été téléversé.',
            default => 'Le téléversement du fichier a échoué.',
        };
    }
}
