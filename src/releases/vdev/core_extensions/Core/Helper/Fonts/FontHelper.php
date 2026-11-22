<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Fonts;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Bibliothèque commune à Core, aux templates et aux autres extensions. */
class FontHelper extends BaseHelper
{
    private ?array $installedCache = null;
    private ?array $catalogCache = null;

    private const BUILTIN = [
        'arial' => ['family' => 'Arial', 'stack' => 'Arial, Helvetica, sans-serif'],
        'verdana' => ['family' => 'Verdana', 'stack' => 'Verdana, Geneva, sans-serif'],
        'georgia' => ['family' => 'Georgia', 'stack' => 'Georgia, "Times New Roman", serif'],
        'times_new_roman' => ['family' => 'Times New Roman', 'stack' => '"Times New Roman", Times, serif'],
        'courier_new' => ['family' => 'Courier New', 'stack' => '"Courier New", Courier, monospace'],
    ];

    /** Les anciennes valeurs restent lisibles sans conserver les fichiers de polices. */
    public function normalizeId(string $id): string
    {
        return match ($id) {
            'system', 'nunito' => 'arial',
            'bona_nova' => 'georgia',
            default => $id,
        };
    }

    public function catalog(): array
    {
        if ($this->catalogCache !== null) {
            return $this->catalogCache;
        }
        $path = $this->storageRoot() . '/.catalog.json';
        if (is_link($this->storageRoot()) || is_link($path)) {
            throw new RuntimeException('Le catalogue des polices ne peut pas être un lien symbolique.');
        }
        if (!file_exists($path)) {
            $path = dirname(__DIR__, 2) . '/Resources/Fonts/catalog.json';
        }
        if (!is_file($path) || !is_readable($path) || filesize($path) > 8 * 1024 * 1024) {
            throw new RuntimeException('Le catalogue des polices n’est pas lisible ou dépasse la taille autorisée.');
        }
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new RuntimeException('Impossible de lire le catalogue des polices.');
        }
        $catalog = FontCatalog::decode($json);
        FontCatalog::validate($catalog);
        return $this->catalogCache = $catalog;
    }

    public function refreshCatalog(): array
    {
        $this->ensureDirectory($this->storageRoot());
        $result = [];
        $this->withManagementLock(function () use (&$result): void {
            $this->catalogCache = null;
            $previous = $this->catalog();

            // Deux téléchargements bornés, sans URL fournie par le navigateur ni clé API.
            $metadata = $this->download(FontCatalog::METADATA_URL, 8 * 1024 * 1024, 20);
            $tree = $this->download(FontCatalog::TREE_URL, 8 * 1024 * 1024, 20);
            $catalog = FontCatalog::fromGoogle($metadata, $tree);

            // Les installations conservent leurs noms, notices et révisions de licence d'origine.
            foreach ($this->installationRecords() as $id => $record) {
                $font = $previous['families'][$id] ?? null;
                if ($font !== null) {
                    $catalog['families'][$id] = [...$font, 'revision' => $font['revision'] ?? $previous['revision']];
                }
            }
            ksort($catalog['families']);
            $catalog['updatedAt'] = gmdate('Y-m-d\TH:i:s\Z');
            FontCatalog::validate($catalog);
            $json = json_encode($catalog, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (strlen($json) > 8 * 1024 * 1024) {
                throw new RuntimeException('Le catalogue actualisé dépasse la taille autorisée.');
            }

            $temporary = $this->storageRoot() . '/.catalog-' . bin2hex(random_bytes(12)) . '.tmp';
            try {
                $this->write($temporary, $json);
                // Relire le fichier publié en devenir avant tout remplacement du catalogue actif.
                if (@file_get_contents($temporary) !== $json) {
                    throw new RuntimeException('Le catalogue écrit ne correspond pas aux données validées.');
                }
                $target = $this->storageRoot() . '/.catalog.json';
                if (is_link($target) || !@rename($temporary, $target)) {
                    throw new RuntimeException('Impossible de publier le catalogue actualisé.');
                }
            } finally {
                if (is_file($temporary)) @unlink($temporary);
            }
            $this->catalogCache = $catalog;
            $this->installedCache = null;
            $result = ['count' => count($catalog['families']), 'updatedAt' => $catalog['updatedAt']];
        });
        return $result;
    }

    protected function storageRoot(): string
    {
        return $this->app->getConf()->fonts_root ?? APP_ROOT . '/public/assets/fonts';
    }

    protected function download(string $url, int $limit, int $timeout): string
    {
        return (new FontDownload())->get($url, $limit, $timeout);
    }

    /** @return array<string,array> Seuls les répertoires publiés et complets sont exposés. */
    public function installed(): array
    {
        if ($this->installedCache !== null) {
            return $this->installedCache;
        }
        $installed = [];
        $root = $this->storageRoot();
        if (!is_dir($root) || is_link($root)) {
            return $this->installedCache = [];
        }
        $files = glob($root . '/google_*/*/metadata.json') ?: [];
        if ($files === []) {
            return $this->installedCache = [];
        }
        $records = $this->installationRecords();
        foreach ($files as $file) {
            $versionDir = dirname($file);
            $familyDir = dirname($versionDir);
            $id = basename($familyDir);
            $family = $this->catalog()['families'][$id] ?? null;
            $version = basename($versionDir);
            $record = $records[$id] ?? null;
            if ($record === null || $record['version'] !== $version || $family === null || !is_readable($file) || is_link($file) || is_link($familyDir) || is_link($versionDir)
                || !preg_match('/\A[a-f0-9]{24}\z/', $version)) {
                continue;
            }
            $data = json_decode((string) @file_get_contents($file), true);
            if (!is_array($data) || ($data['id'] ?? null) !== $id || ($data['version'] ?? null) !== $version
                || !isset($data['files']) || !is_array($data['files']) || $data['files'] === []) {
                continue;
            }
            // Les notices et la feuille locale doivent accompagner chaque installation.
            foreach (array_merge(['font.css'], array_column($family['licenses'], 'name')) as $required) {
                if (!in_array($required, $data['files'], true)) {
                    continue 2;
                }
            }
            foreach ($data['files'] as $name) {
                if (!is_string($name) || !preg_match('/\A(?:[a-f0-9]{64}\.woff2|font\.css|OFL\.txt|LICENSE\.txt|LICENCE\.txt|NOTICE(?:\.txt)?)\z/', $name)
                    || is_link($versionDir . '/' . $name) || !is_file($versionDir . '/' . $name)
                    || !is_readable($versionDir . '/' . $name) || !@filesize($versionDir . '/' . $name)) {
                    continue 2;
                }
            }
            $base = '/assets/fonts/' . $id . '/' . $version . '/';
            $installed[$id] = [
                'id' => $id, 'family' => $family['family'], 'category' => $family['category'],
                'version' => $version, 'cssUrl' => $base . 'font.css',
                'licenses' => array_map(static fn (array $license): array => [
                    'name' => $license['name'], 'url' => $base . $license['name'],
                ], $family['licenses']),
                'enabled' => $record['enabled'],
            ];
        }
        ksort($installed);
        return $this->installedCache = $installed;
    }

    /** Options et piles CSS ont une source commune, pour les cinq polices système et les familles installées. */
    public function options(): array
    {
        $options = [];
        foreach (self::BUILTIN as $id => $font) {
            $options[] = [
                'value' => $id, 'label' => $font['family'],
                'data' => ['fontFamily' => $font['stack'], 'fontName' => $font['family'], 'fontCssUrl' => null],
            ];
        }
        foreach ($this->installed() as $id => $font) {
            $options[] = [
                'value' => $id, 'label' => $font['family'] . (!$font['enabled'] ? ' (désactivée)' : ''),
                'disabled' => !$font['enabled'],
                'data' => ['fontFamily' => $this->stack($id), 'fontName' => $font['family'], 'fontCssUrl' => $font['cssUrl']],
            ];
        }
        return $options;
    }

    public function assertSelectable(mixed $id, mixed $previousId = null): void
    {
        if (is_string($id) && isset(self::BUILTIN[$id])) {
            return;
        }
        $font = is_string($id) ? ($this->installed()[$id] ?? null) : null;
        if ($font === null || (!$font['enabled'] && $id !== $previousId)) {
            throw new RuntimeException('Cette police n’est pas activée pour de nouveaux choix.');
        }
    }

    public function stack(mixed $id, string $fallback = 'arial'): string
    {
        $id = is_string($id) ? $this->normalizeId($id) : $id;
        $fallback = $this->normalizeId($fallback);
        if (is_string($id) && isset(self::BUILTIN[$id])) {
            return self::BUILTIN[$id]['stack'];
        }
        $font = is_string($id) ? ($this->installed()[$id] ?? null) : null;
        if ($font === null) {
            return (self::BUILTIN[$fallback] ?? self::BUILTIN['arial'])['stack'];
        }
        $generic = match ($font['category']) {
            'Serif' => 'serif', 'Monospace' => 'monospace', 'Handwriting' => 'cursive', default => 'sans-serif',
        };
        return '"' . $font['family'] . '", ' . $generic;
    }

    /** À placer avant les règles de thème ; ne retourne jamais d'import distant. */
    public function css(array $ids): string
    {
        $installed = $this->installed();
        $rules = [];
        foreach (array_unique(array_filter($ids, 'is_string')) as $id) {
            if (!isset($installed[$id])) {
                continue;
            }
            $font = $installed[$id];
            $rules[] = '@import url("' . $font['cssUrl'] . '");';
        }
        return implode("\n", $rules);
    }

    public function install(string $id): array
    {
        $catalog = $this->catalog();
        $family = $catalog['families'][$id] ?? null;
        if ($family === null) {
            throw new RuntimeException('Cette famille ne figure pas dans le catalogue local.');
        }
        $root = $this->storageRoot();
        $this->ensureDirectory($root);
        if (is_link($root . '/' . $id) || (file_exists($root . '/' . $id) && !is_dir($root . '/' . $id))) {
            throw new RuntimeException('Dossier de famille invalide.');
        }

        // Un verrou commun borne également les installations concurrentes sur le disque partagé.
        if (is_link($root . '/.install.lock')) {
            throw new RuntimeException('Verrou de polices invalide.');
        }
        $lock = @fopen($root . '/.install.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Le dossier assets/fonts ou son verrou n’est pas accessible en écriture à PHP. Vérifiez ses droits sur le serveur.');
        }
        $temporary = null;
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Une installation de police est déjà en cours. Réessayez dans un instant.');
            }
            // Le catalogue peut avoir été actualisé pendant l'attente du verrou.
            $this->catalogCache = null;
            $catalog = $this->catalog();
            $family = $catalog['families'][$id] ?? null;
            if ($family === null) {
                throw new RuntimeException('Cette famille ne figure plus dans le catalogue local.');
            }
            $this->installedCache = null;
            if (isset($this->installed()[$id])) {
                return $this->verifyInstallation($id);
            }
            foreach (glob($root . '/' . $id . '/*/metadata.json') ?: [] as $orphan) {
                $version = basename(dirname($orphan));
                if (preg_match('/\A[a-f0-9]{24}\z/', $version) && !is_link(dirname($orphan)) && !is_link(dirname($orphan, 2))) {
                    $this->registerInstallation($id, $version);
                    $this->installedCache = null;
                    if (isset($this->installed()[$id])) {
                        return $this->verifyInstallation($id);
                    }
                }
            }
            $temporary = $root . '/.install-' . bin2hex(random_bytes(12));
            $this->ensureDirectory($temporary);
            $deadline = microtime(true) + 60;
            $download = function (string $url, int $limit) use ($deadline): string {
                $remaining = (int) floor($deadline - microtime(true));
                if ($remaining < 1) {
                    throw new RuntimeException('Le délai maximal d’installation de la police est dépassé.');
                }
                return $this->download($url, $limit, min(15, $remaining));
            };
            $files = [];
            $fingerprints = [];

            // La licence originale est obligatoire et vérifiée contre son empreinte Git immuable.
            foreach ($family['licenses'] as $license) {
                $url = 'https://raw.githubusercontent.com/google/fonts/' . ($family['revision'] ?? $catalog['revision']) . '/' . $family['path'] . '/' . $license['name'];
                $text = $download($url, 256 * 1024);
                if (sha1('blob ' . strlen($text) . "\0" . $text) !== $license['sha'] || str_contains($text, "\0")) {
                    throw new RuntimeException('La licence téléchargée ne correspond pas au catalogue local.');
                }
                $this->write($temporary . '/' . $license['name'], $text);
                $files[] = $license['name'];
                $fingerprints[] = hash('sha256', $text);
            }

            // Installer toutes les graisses standard proposées, normales et italiques disponibles.
            $variants = array_map(static fn (string $variant): string => (str_ends_with($variant, 'i') ? '1' : '0') . ',' . (int) $variant, $family['variants']);
            usort($variants, static fn (string $a, string $b): int => strnatcmp($a, $b));
            $url = 'https://fonts.googleapis.com/css2?family=' . rawurlencode($family['family'])
                . ':ital,wght@' . implode(';', $variants) . '&display=swap';
            $faces = FontDownload::parseCss($download($url, 1024 * 1024));
            $sources = [];
            $total = 0;
            foreach ($faces as &$face) {
                if (!isset($sources[$face['url']])) {
                    $bytes = $download($face['url'], 4 * 1024 * 1024);
                    $total += strlen($bytes);
                    if (substr($bytes, 0, 4) !== 'wOF2' || strlen($bytes) < 48 || $total > 48 * 1024 * 1024
                        || unpack('Nlength', substr($bytes, 8, 4))['length'] !== strlen($bytes)) {
                        throw new RuntimeException('Le fichier téléchargé est invalide ou la famille est trop volumineuse.');
                    }
                    $name = hash('sha256', $bytes) . '.woff2';
                    $this->write($temporary . '/' . $name, $bytes);
                    $sources[$face['url']] = $name;
                    $files[] = $name;
                    $fingerprints[] = $name;
                }
                $face['file'] = $sources[$face['url']];
            }
            unset($face);
            $version = substr(hash('sha256', json_encode([$faces, $fingerprints], JSON_THROW_ON_ERROR)), 0, 24);
            $base = '/assets/fonts/' . $id . '/' . $version . '/';
            $css = [];
            foreach ($faces as $face) {
                $css[] = '@font-face{font-family:"' . $family['family'] . '";font-style:' . $face['style']
                    . ';font-weight:' . $face['weight'] . ';font-display:swap;src:url("' . $base . $face['file'] . '") format("woff2");'
                    . ($face['range'] !== '' ? 'unicode-range:' . $face['range'] . ';' : '') . '}';
            }
            $this->write($temporary . '/font.css', implode("\n", $css));
            $files[] = 'font.css';
            $hashes = [];
            foreach (array_unique($files) as $name) {
                $hashes[$name] = hash_file('sha256', $temporary . '/' . $name);
            }
            $this->write($temporary . '/metadata.json', json_encode([
                'id' => $id, 'version' => $version, 'revision' => $family['revision'] ?? $catalog['revision'], 'files' => array_values(array_unique($files)), 'sha256' => $hashes,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            // Une publication atomique : aucun choix utilisable avant la fin des téléchargements.
            $this->ensureDirectory($root . '/' . $id);
            if (!@rename($temporary, $root . '/' . $id . '/' . $version)) {
                throw new RuntimeException('Impossible de publier la police installée.');
            }
            $temporary = null;
            $this->registerInstallation($id, $version);
            $this->installedCache = null;
            return $this->verifyInstallation($id);
        } finally {
            if ($temporary !== null) {
                foreach (glob($temporary . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($temporary);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Relit le registre et les fichiers avant de confirmer une installation au client. */
    private function verifyInstallation(string $id): array
    {
        clearstatcache();
        $this->installedCache = null;
        $font = $this->installed()[$id] ?? null;
        if ($font === null) {
            throw new RuntimeException('Installation incomplète : le registre SQL ou les fichiers de la police ne sont pas disponibles.');
        }
        $directory = $this->storageRoot() . '/' . $id . '/' . $font['version'];
        $metadata = json_decode((string) file_get_contents($directory . '/metadata.json'), true, flags: JSON_THROW_ON_ERROR);
        $binaryCount = 0;
        foreach ($metadata['files'] as $name) {
            $path = $directory . '/' . $name;
            $expected = $metadata['sha256'][$name] ?? null;
            if (!is_string($expected) || !hash_equals($expected, (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Installation non vérifiée : un fichier est absent, altéré ou sans empreinte de contrôle.');
            }
            if (str_ends_with($name, '.woff2')) {
                $binaryCount++;
                if (!hash_equals(substr($name, 0, -6), $expected)) {
                    throw new RuntimeException('Empreinte de fichier WOFF2 incohérente.');
                }
            }
        }
        if ($binaryCount === 0) {
            throw new RuntimeException('Installation incomplète : aucun fichier WOFF2 n’a été publié.');
        }
        return [...$font, 'verified' => true];
    }

    /** Les méthodes protégées permettent des tests sans réseau ni base de production. */
    protected function installationRecords(): array
    {
        $records = [];
        foreach ($this->db->select('SELECT font_key, storage_version, enabled FROM font_installations') as $row) {
            $records[$row->font_key] = ['version' => $row->storage_version, 'enabled' => (bool) $row->enabled];
        }
        return $records;
    }

    protected function registerInstallation(string $id, string $version): void
    {
        $this->db->execute(
            'INSERT INTO font_installations (font_key, storage_version) VALUES (%, %) '
                . 'ON DUPLICATE KEY UPDATE storage_version = VALUES(storage_version)',
            [$id, $version],
        );
    }

    protected function updateEnabledRecord(string $id, bool $enabled): void
    {
        $this->db->execute('UPDATE font_installations SET enabled = % WHERE font_key = %', [$enabled ? 1 : 0, $id]);
    }

    protected function deleteRecord(string $id): void
    {
        $this->db->execute('DELETE FROM font_installations WHERE font_key = %', [$id]);
    }

    public function setEnabled(string $id, bool $enabled): void
    {
        $this->withManagementLock(function () use ($id, $enabled): void {
            if (!isset($this->installed()[$id])) {
                throw new RuntimeException('Police installée introuvable.');
            }
            $this->updateEnabledRecord($id, $enabled);
            $this->installedCache = null;
        });
    }

    public function delete(string $id): void
    {
        $this->withManagementLock(function () use ($id): void {
            $font = $this->installed()[$id] ?? null;
            if ($font === null || $font['enabled']) {
                throw new RuntimeException('Désactivez la police avant de supprimer ses fichiers.');
            }
            $path = $this->storageRoot() . '/' . $id;
            $temporary = $this->storageRoot() . '/.delete-' . bin2hex(random_bytes(12));
            // Sortir atomiquement les fichiers publiés avant de modifier le registre SQL.
            if (!@rename($path, $temporary)) {
                throw new RuntimeException('Impossible de retirer les fichiers de la police.');
            }
            try {
                $this->deleteRecord($id);
                $this->installedCache = null;
            } catch (\Throwable $exception) {
                @rename($temporary, $path);
                throw $exception;
            }
            $this->removeDirectory($temporary);
        });
    }

    private function withManagementLock(callable $action): void
    {
        $root = $this->storageRoot();
        if (!is_dir($root) || is_link($root) || is_link($root . '/.install.lock')) {
            throw new RuntimeException('Dossier des polices invalide.');
        }
        $lock = @fopen($root . '/.install.lock', 'c');
        if ($lock === false) {
            throw new RuntimeException('Le dossier assets/fonts ou son verrou n’est pas accessible en écriture à PHP. Vérifiez ses droits sur le serveur.');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('La bibliothèque est occupée. Réessayez dans un instant.');
            }
            $this->installedCache = null;
            $action();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function removeDirectory(string $directory): void
    {
        // Ne jamais suivre un lien symbolique, même lors du nettoyage après suppression.
        foreach (new \FilesystemIterator($directory) as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                if (!@unlink($entry->getPathname())) {
                    throw new RuntimeException('Le retrait est enregistré, mais un fichier de police n’a pas pu être supprimé du disque.');
                }
            }
        }
        if (!@rmdir($directory)) {
            throw new RuntimeException('Le retrait est enregistré, mais un dossier de police n’a pas pu être supprimé du disque.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) || !is_writable($path)) {
            throw new RuntimeException('Le dossier des polices doit être un répertoire accessible en écriture.');
        }
    }

    private function write(string $path, string $bytes): void
    {
        if (@file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Impossible d’écrire un fichier de police.');
        }
    }
}
