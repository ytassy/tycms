<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Frontend;

use RuntimeException;

/** Lire un manifeste Vite local en refusant toute sortie du dossier de publication. */
final class FrontendManifest
{
    private array $manifest;
    private string $root;

    public function __construct(private readonly string $directory)
    {
        $root = realpath($directory);
        if ($root === false || $root !== rtrim($directory, '/')) {
            throw new RuntimeException('Dossier frontend absent ou redirigé par un lien symbolique.');
        }
        $this->root = $root;
        $file = $this->assetPath('.vite/manifest.json');
        if (filesize($file) > 2 * 1024 * 1024) throw new RuntimeException('Manifeste frontend trop volumineux.');
        $manifest = json_decode(file_get_contents($file), true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($manifest)) throw new RuntimeException('Manifeste frontend invalide.');
        $this->manifest = $manifest;
    }

    /** Suivre les CSS des chunks communs, en visitant chaque nœud au plus une fois. */
    public function entry(string $key, string $base, array &$styles, bool $includeDynamicStyles = false): string
    {
        $seen = [];
        return $this->collect($key, $base, $styles, $seen, $includeDynamicStyles);
    }

    private function collect(string $key, string $base, array &$styles, array &$seen, bool $includeDynamicStyles): string
    {
        $item = $this->manifest[$key] ?? null;
        if (!is_array($item) || !is_string($item['file'] ?? null) || !str_ends_with($item['file'], '.js')
            || !is_array($item['css'] ?? []) || !is_array($item['imports'] ?? []) || !is_array($item['dynamicImports'] ?? [])) {
            throw new RuntimeException('Entrée frontend absente ou invalide : ' . $key);
        }
        $this->assetPath($item['file']);
        if (isset($seen[$key])) return $base . $item['file'];
        $seen[$key] = true;
        // Le mode bibliothèque ne fournit pas le chargement automatique des CSS différés.
        $imports = array_merge($item['imports'] ?? [], $includeDynamicStyles ? ($item['dynamicImports'] ?? []) : []);
        foreach ($imports as $import) {
            if (!is_string($import)) throw new RuntimeException('Import frontend invalide.');
            $this->collect($import, $base, $styles, $seen, $includeDynamicStyles);
        }
        foreach ($item['css'] ?? [] as $css) {
            if (!is_string($css) || !str_ends_with($css, '.css')) throw new RuntimeException('Feuille de style frontend invalide.');
            $this->assetPath($css);
            $styles[] = $base . $css;
        }
        return $base . $item['file'];
    }

    private function assetPath(string $path): string
    {
        if (!preg_match('~^(?:[A-Za-z0-9_-]+/|\.vite/)*[A-Za-z0-9_.-]+$~D', $path)
            || in_array('..', explode('/', $path), true)) throw new RuntimeException('Chemin d’asset frontend invalide.');
        $file = realpath($this->directory . '/' . $path);
        if ($file === false || !is_file($file) || !str_starts_with($file, $this->root . '/')) {
            throw new RuntimeException('Asset frontend absent ou hors du dossier autorisé : lancer le build correspondant.');
        }
        return $file;
    }
}
