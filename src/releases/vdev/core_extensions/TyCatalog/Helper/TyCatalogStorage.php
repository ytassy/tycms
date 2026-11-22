<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Helper;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Stockage privé des paquets TyCatalog.
 *
 * Tous les chemins sont dérivés côté serveur à partir d'identifiants validés.
 * Aucun nom de fichier fourni par le navigateur n'est utilisé pour construire
 * une destination.
 */
final class TyCatalogStorage extends BaseHelper
{
    private const SEGMENT_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9._-]{0,79}\z/';

    /** Racine privée existante, sans création ni écriture (lectures publiques). */
    public function existingRoot(): ?string
    {
        $configured = trim((string) ($this->app->getConf()->ty_catalog_storage_root ?? ''));
        if ($configured === '' || !str_starts_with($configured, '/') || is_link($configured)) {
            return null;
        }

        $root = realpath($configured);
        $public = realpath(APP_ROOT . '/public');
        if ($root === false || !is_dir($root)
            || ($public !== false && ($root === $public || str_starts_with($root . '/', $public . '/')))) {
            return null;
        }

        return $root;
    }

    /** Racine réelle, créée si nécessaire et vérifiée hors de public/. */
    public function root(): string
    {
        $configured = trim((string) ($this->app->getConf()->ty_catalog_storage_root ?? ''));
        if ($configured === '' || !str_starts_with($configured, '/')) {
            throw new RuntimeException('La racine privée TyCatalog doit être un chemin absolu.');
        }
        if (is_link($configured)) {
            throw new RuntimeException('La racine privée TyCatalog ne peut pas être un lien symbolique.');
        }
        if (!is_dir($configured) && !mkdir($configured, 0700, true) && !is_dir($configured)) {
            throw new RuntimeException('Impossible de créer la racine privée TyCatalog.');
        }

        $root = realpath($configured);
        $public = realpath(APP_ROOT . '/public');
        if ($root === false || !is_dir($root) || !is_writable($root)) {
            throw new RuntimeException('La racine privée TyCatalog est introuvable ou non inscriptible.');
        }
        if ($public !== false && ($root === $public || str_starts_with($root . '/', $public . '/'))) {
            throw new RuntimeException('La racine privée TyCatalog doit rester hors de la racine HTTP publique.');
        }

        return $root;
    }

    /** @return array{absolute:string,relative:string} */
    public function createVersionDirectory(int $catalogId, string $extensionId, string $version): array
    {
        $relative = implode('/', [
            'catalogs',
            (string) $catalogId,
            $this->segment($extensionId, 'identifiant d’extension'),
            $this->segment($version, 'version'),
            bin2hex(random_bytes(8)),
        ]);

        $absolute = $this->ensureDirectory($relative);
        return ['absolute' => $absolute, 'relative' => $relative];
    }

    /** Déplacer un fichier téléversé vers un chemin temporaire privé. */
    public function receiveUploadedFile(array $file, int $maxBytes, string $suffix): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $tmpName = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Le fichier téléversé est invalide ou incomplet.');
        }
        if (!is_string($tmpName) || !is_file($tmpName) || !is_uploaded_file($tmpName) || !is_int($size)
            || $size < 1 || $size > $maxBytes) {
            throw new RuntimeException('La taille du fichier téléversé dépasse la limite autorisée.');
        }

        $temporary = $this->temporaryFile($suffix);
        if (!move_uploaded_file($tmpName, $temporary)) {
            throw new RuntimeException('Impossible de conserver le fichier téléversé.');
        }
        @chmod($temporary, 0600);

        return $temporary;
    }

    /** Écrire une chaîne dans un fichier privé atomique. */
    public function storeContents(string $directory, string $name, string $contents, int $maxBytes): void
    {
        if (strlen($contents) > $maxBytes) {
            throw new RuntimeException('Le fichier à conserver dépasse la limite autorisée.');
        }

        $temporary = $directory . '/.write-' . bin2hex(random_bytes(8));
        if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
            throw new RuntimeException('Impossible d’écrire le fichier privé.');
        }
        @chmod($temporary, 0600);

        $target = $directory . '/' . $name;
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Impossible de finaliser le fichier privé.');
        }
        @chmod($target, 0600);
    }

    /** Copier un fichier temporaire déjà validé vers le stockage privé. */
    public function storeFile(string $source, string $directory, string $name, int $maxBytes): void
    {
        if (!is_file($source) || is_link($source)) {
            throw new RuntimeException('Fichier temporaire introuvable.');
        }
        $size = filesize($source);
        if ($size === false || $size < 1 || $size > $maxBytes) {
            throw new RuntimeException('Le fichier à conserver dépasse la limite autorisée.');
        }

        $temporary = $directory . '/.copy-' . bin2hex(random_bytes(8));
        if (!copy($source, $temporary)) {
            throw new RuntimeException('Impossible de copier le fichier privé.');
        }
        @chmod($temporary, 0600);

        $target = $directory . '/' . $name;
        if (!rename($temporary, $target)) {
            @unlink($temporary);
            throw new RuntimeException('Impossible de finaliser le fichier privé.');
        }
        @chmod($target, 0600);
    }

    /** Créer un chemin temporaire privé, supprimé par l'appelant. */
    public function temporaryFile(string $suffix): string
    {
        $directory = $this->ensureDirectory('tmp');
        $suffix = preg_replace('/[^a-z0-9.]/', '', strtolower($suffix)) ?: 'tmp';

        return $directory . '/' . bin2hex(random_bytes(16)) . '.' . $suffix;
    }

    /** Supprimer un dossier de version après remplacement d'un brouillon. */
    public function deleteDirectory(string $relative): void
    {
        $root = $this->root();
        $target = realpath($root . '/' . $this->safeRelative($relative));
        if ($target === false || !is_dir($target) || $target === $root || !str_starts_with($target, $root . '/')) {
            throw new RuntimeException('Dossier de stockage à supprimer invalide.');
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($target, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isLink()) {
                throw new RuntimeException('Lien symbolique interdit dans le stockage TyCatalog.');
            }
            if ($item->isDir()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException('Impossible de supprimer un dossier de stockage.');
                }
            } elseif (!unlink($item->getPathname())) {
                throw new RuntimeException('Impossible de supprimer un fichier de stockage.');
            }
        }
        if (!rmdir($target)) {
            throw new RuntimeException('Impossible de supprimer le dossier de stockage.');
        }
    }

    private function ensureDirectory(string $relative): string
    {
        $root = $this->root();
        $path = $root;

        foreach (explode('/', $this->safeRelative($relative)) as $segment) {
            $path .= '/' . $segment;
            if (!is_dir($path)) {
                if (!mkdir($path, 0700) && !is_dir($path)) {
                    throw new RuntimeException('Impossible de créer un dossier de stockage TyCatalog.');
                }
            }
            if (is_link($path)) {
                throw new RuntimeException('Lien symbolique interdit dans le stockage TyCatalog.');
            }
        }

        $real = realpath($path);
        if ($real === false || !str_starts_with($real, $root . '/')) {
            throw new RuntimeException('Dossier de stockage TyCatalog hors de la racine autorisée.');
        }

        return $real;
    }

    private function safeRelative(string $relative): string
    {
        $relative = trim($relative, '/');
        if ($relative === '' || strlen($relative) > 512) {
            throw new RuntimeException('Chemin de stockage TyCatalog invalide.');
        }

        $segments = explode('/', $relative);
        foreach ($segments as $segment) {
            $this->segment($segment, 'chemin de stockage');
        }

        return implode('/', $segments);
    }

    private function segment(string $value, string $label): string
    {
        if (!preg_match(self::SEGMENT_PATTERN, $value) || $value === '.' || $value === '..') {
            throw new RuntimeException('Le ' . $label . ' contient un segment de chemin interdit.');
        }

        return $value;
    }
}
