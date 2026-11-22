<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate;

use RuntimeException;
use ZipArchive;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;

/** Prépare le moteur authentifié, sans installer la release ni toucher à la base. */
class SystemUpdatePreparationHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public function prepare(string $source, string $php, string $version, string $sha256, bool $devPreview = false): array
    {
        $root = realpath($this->installationRoot());
        if ($root === false) {
            throw new RuntimeException('Installation inaccessible.');
        }
        $this->directory($root . '/var');
        $this->directory($root . '/var/updates');
        $this->directory($root . '/public', false);
        $lockPath = $root . '/var/updates/.prepare.lock';
        $this->regularFile($lockPath, true);
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw new RuntimeException('Impossible de verrouiller la préparation.');
        }
        $stage = null;
        $reserved = false;
        $completed = false;
        try {
            if (!@chmod($lockPath, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Une préparation est déjà en cours.');
            }
            $statePath = $root . '/var/releases-states.json';
            $state = $this->readState($statePath);
            $dev = $this->developmentMode();
            $active = $state['active_operation'] ?? null;

            // Une opération préparée est réutilisée ; aucun moteur engagé n'est remplacé.
            if ($active !== null) {
                if (is_array($active) && ($active['status'] ?? null) === 'prepared'
                    && ($active['source_version'] ?? null) === $source && ($active['version'] ?? null) === $version
                    && ($active['sha256'] ?? null) === $sha256 && ($active['development'] ?? null) === $dev
                    && is_string($active['entrypoint'] ?? null)
                    && preg_match('/\Aupdate_[a-f0-9]{64}\.php\z/', $active['entrypoint']) === 1) {
                    $this->regularFile($root . '/public/' . $active['entrypoint']);
                    return $this->receipt($active, true);
                }
                throw new RuntimeException('Une opération de mise à jour existe déjà. Elle doit être clôturée avant une nouvelle préparation.');
            }

            // Le navigateur fournit une identité ; seuls les chemins calculés du cache sont utilisés.
            $package = $this->coreSystemUpdatePackageHelper->verifiedPackage($source, $php, $version, $sha256, $devPreview);
            $files = $package['directory'] . '/files';
            if (file_exists($files) || is_link($files)) {
                throw new RuntimeException('Un dossier extrait existe déjà sans opération associée. Vérifiez les fichiers de préparation.');
            }

            $stage = $root . '/var/updates/.prepare-' . bin2hex(random_bytes(16));
            $this->directory($stage);
            $this->extract($package, $stage . '/files');

            $updater = $dev ? 'updater_test' : 'updater';
            $assets = $dev ? 'assets_updater_test' : 'assets_updater';
            $this->copyTree($stage . '/files/updater', $stage . '/engine', false);
            $assetSource = $stage . '/files/public/assets_updater';
            $this->directory($stage . '/assets', false);
            if (is_dir($assetSource)) {
                $this->copyTree($assetSource, $stage . '/assets', true);
            }
            foreach ([$root . '/' . $updater, $root . '/public/' . $assets] as $target) {
                if (file_exists($target) || is_link($target)) {
                    $this->directory($target, false);
                }
            }

            $operation = ['id' => bin2hex(random_bytes(16)), 'status' => 'preparing',
                'source_version' => $source, 'version' => $version, 'sha256' => $sha256,
                'package_id' => $package['package_id'], 'development' => $dev,
                'entrypoint' => 'update_' . bin2hex(random_bytes(32)) . '.php'];
            $entrypoint = $root . '/public/' . $operation['entrypoint'];
            if (file_exists($entrypoint) || is_link($entrypoint)) {
                throw new RuntimeException('Le point d’entrée temporaire existe déjà.');
            }

            // Réserver l'opération avant toute publication : une interruption interdit un remplacement aveugle.
            $state['active_operation'] = $operation;
            $this->writeState($statePath, $state);
            $reserved = true;
            if (!@rename($stage . '/files', $files)) {
                throw new RuntimeException('Impossible de publier les fichiers extraits.');
            }
            $this->installDirectory($stage . '/engine', $root . '/' . $updater, $stage . '/previous-engine');
            $this->installDirectory($stage . '/assets', $root . '/public/' . $assets, $stage . '/previous-assets');

            // Le lien n'est utilisable qu'après publication complète du moteur et validation de l'état.
            $this->writeFile($stage . '/entrypoint.php', $this->entrypoint($operation));
            if (!@rename($stage . '/entrypoint.php', $entrypoint)) {
                throw new RuntimeException('Impossible de publier le lien de mise à jour.');
            }
            $operation['status'] = 'prepared';
            $state['active_operation'] = $operation;
            $this->writeState($statePath, $state);
            $completed = true;

            return $this->receipt($operation, false);
        } finally {
            // Après réservation, conserver les fichiers de récupération si la publication échoue.
            if ($stage !== null && (!$reserved || $completed)) {
                $this->removeTree($stage);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Abandonner la préparation et son cache, sans modifier les releases ni les données du site. */
    public function reset(): array
    {
        $root = realpath($this->installationRoot());
        if ($root === false) throw new RuntimeException('Installation inaccessible.');
        foreach (['var', 'public', 'var/updates', 'var/updates/packages'] as $relative) {
            $this->directory($root . '/' . $relative);
        }
        $locks = [];
        try {
            // Garder les mêmes fichiers et les mêmes inodes que les opérations concurrentes.
            foreach (['var/updates/.prepare.lock', 'var/updates/packages/.download.lock'] as $relative) {
                $path = $root . '/' . $relative;
                $this->regularFile($path, true);
                $lock = @fopen($path, 'c+b');
                if ($lock === false) throw new RuntimeException('Impossible de verrouiller la réinitialisation.');
                $locks[] = $lock;
                if (!@chmod($path, 0600) || !flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('Un téléchargement ou une préparation est en cours. Réessayez après sa fin.');
                }
            }

            $statePath = $root . '/var/releases-states.json';
            $state = $this->readState($statePath);
            $active = $state['active_operation'] ?? null;
            $dev = $this->developmentMode();
            if ($active !== null && (!is_array($active)
                || !in_array($active['status'] ?? null, ['preparing', 'prepared', 'resetting'], true))) {
                throw new RuntimeException('Cette opération a dépassé la préparation ou son état est inconnu : réinitialisation refusée.');
            }
            if (is_array($active) && isset($active['development']) && $active['development'] !== $dev) {
                throw new RuntimeException('Le mode DEV a changé depuis la préparation. Rétablissez le mode de cette opération avant de la réinitialiser.');
            }
            $lastPath = $root . '/var/last_update_data.json';
            $this->regularFile($lastPath, true);
            $progressContents = is_file($lastPath) ? @file_get_contents($lastPath) : '{}';
            $progress = is_string($progressContents) ? json_decode($progressContents, true) : null;
            if (!is_array($progress) || $progress !== []) {
                throw new RuntimeException('Le moteur de mise à jour a commencé son travail ; utilisez son lien pour terminer ou annuler.');
            }

            // Une liste fermée exclut les éléments inconnus. Les dossiers d'opération
            // sont supprimés en entier, sauvegarde comprise : le reset retire aussi
            // les restes d'anciennes opérations non encore nettoyées.
            $targets = [];
            foreach (new \FilesystemIterator($root . '/var/updates') as $entry) {
                $name = $entry->getFilename();
                if (in_array($name, ['packages', '.prepare.lock'], true)) continue;
                if (preg_match('/\A[a-f0-9]{32}\z/', $name) === 1) {
                    $targets[] = $this->operationDirectory($entry->getPathname());
                    continue;
                }
                if (preg_match('/\A\.prepare-[a-f0-9]{32}\z/', $name) !== 1) {
                    throw new RuntimeException('Le dossier de mise à jour contient des éléments non reconnus ; réinitialisation refusée.');
                }
                $targets[] = $entry->getPathname();
            }
            foreach (new \FilesystemIterator($root . '/var/updates/packages') as $entry) {
                $name = $entry->getFilename();
                if ($name === '.download.lock') continue;
                if (preg_match('/\A(?:v[0-9]+_[0-9]+_[0-9]+-[a-f0-9]{64}|\.incoming-[a-f0-9]{32})\z/', $name) !== 1) {
                    throw new RuntimeException('Le cache contient des éléments non reconnus ; réinitialisation refusée.');
                }
                $targets[] = $entry->getPathname();
            }
            foreach (new \FilesystemIterator($root . '/public') as $entry) {
                if (preg_match('/\Aupdate_[a-f0-9]{64}\.php\z/', $entry->getFilename()) === 1) {
                    if (!$entry->isLink() && !$entry->isFile()) {
                        throw new RuntimeException('Point d’entrée temporaire invalide ; réinitialisation refusée.');
                    }
                    $targets[] = $entry->getPathname();
                }
            }
            foreach (new \FilesystemIterator($root . '/var') as $entry) {
                if (preg_match('/\A\.state-[a-f0-9]{32}\z/', $entry->getFilename()) === 1) {
                    if (!$entry->isLink() && !$entry->isFile()) throw new RuntimeException('État temporaire invalide.');
                    $targets[] = $entry->getPathname();
                }
            }
            $targets[] = $root . ($dev ? '/updater_test' : '/updater');
            $targets[] = $root . '/public/' . ($dev ? 'assets_updater_test' : 'assets_updater');

            // Révoquer l'accès avant toute suppression ; un échec reste reprenable par ce même bouton.
            $state['active_operation'] = array_replace($active ?? [], ['status' => 'resetting', 'development' => $dev]);
            $this->writeState($statePath, $state);
            foreach ($targets as $target) $this->removeResetTree($target);

            $this->writeState($lastPath, new \stdClass());
            $state['available_update'] = null;
            $state['active_operation'] = null;
            $this->writeState($statePath, $state);
            return ['reset' => true];
        } finally {
            foreach (array_reverse($locks) as $lock) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /** Indique si une opération close peut être nettoyée. */
    public function cleanupAvailable(): bool
    {
        $root = realpath($this->installationRoot());
        if ($root === false) return false;
        try {
            $state = $this->readState($root . '/var/releases-states.json');
        } catch (RuntimeException) {
            return false;
        }
        $operation = $state['active_operation'] ?? null;
        return is_array($operation) && in_array($operation['status'] ?? null,
            ['completed', 'rolled_back', 'cancelled', 'cleaning'], true);
    }

    public function cleanup(): array
    {
        $root = realpath($this->installationRoot());
        if ($root === false) throw new RuntimeException('Installation inaccessible.');
        foreach (['var', 'var/updates', 'var/updates/packages', 'public'] as $relative) {
            $this->directory($root . '/' . $relative);
        }
        $locks = [];
        try {
            foreach (['var/updates/.prepare.lock', 'var/updates/packages/.download.lock'] as $relative) {
                $path = $root . '/' . $relative;
                $this->regularFile($path, true);
                $handle = @fopen($path, 'c+b');
                if ($handle === false) throw new RuntimeException('Verrou de nettoyage inaccessible.');
                $locks[] = $handle;
                if (!@chmod($path, 0600) || !flock($handle, LOCK_EX | LOCK_NB)) {
                    throw new RuntimeException('Une opération de mise à jour est en cours.');
                }
            }
            $path = $root . '/var/releases-states.json';
            $state = $this->readState($path);
            $operation = $state['active_operation'] ?? null;
            if (!is_array($operation) || !in_array($operation['status'] ?? null,
                ['completed', 'rolled_back', 'cancelled', 'cleaning'], true)
                || !is_string($operation['id'] ?? null)
                || preg_match('/\A[a-f0-9]{32}\z/', $operation['id']) !== 1
                || !is_string($operation['entrypoint'] ?? null)
                || preg_match('/\Aupdate_[a-f0-9]{64}\.php\z/', $operation['entrypoint']) !== 1
                || !is_string($operation['package_id'] ?? null)
                || preg_match('/\Av[0-9]+_[0-9]+_[0-9]+-[a-f0-9]{64}\z/', $operation['package_id']) !== 1
                || !is_bool($operation['development'] ?? null)) {
                throw new RuntimeException('Aucune opération close à nettoyer.');
            }
            if ($operation['development'] !== $this->developmentMode()) {
                throw new RuntimeException('Le mode DEV a changé depuis la mise à jour ; nettoyage refusé.');
            }
            $progressPath = $root . '/var/last_update_data.json';
            $this->regularFile($progressPath);
            $contents = @file_get_contents($progressPath);
            $progress = is_string($contents) ? json_decode($contents, true) : null;
            if (!is_array($progress) || ($progress !== [] && (($progress['operation_id'] ?? null) !== $operation['id']
                || !in_array($progress['phase'] ?? null, ['completed', 'rolled_back', 'cancelled'], true)))
                || ($progress === [] && $operation['status'] !== 'cleaning')) {
                throw new RuntimeException('État de clôture incohérent.');
            }

            // Valider le dossier d'opération avant toute suppression ou écriture d'état.
            $work = $this->operationDirectory($root . '/var/updates/' . $operation['id']);
            $oldVersions = $this->oldVersionDirectories($root, $state['active_release']);

            // La révocation précède les suppressions et reste sûre après une coupure.
            $state['active_operation']['status'] = 'cleaning';
            $this->writeState($path, $state);
            $this->removeResetTree($root . '/public/' . $operation['entrypoint']);
            $this->removeResetTree($root . '/var/updates/packages/' . $operation['package_id']);
            $this->removeResetTree($root . ($operation['development'] ? '/updater_test' : '/updater'));
            $this->removeResetTree($root . '/public/' . ($operation['development'] ? 'assets_updater_test' : 'assets_updater'));

            // L'opération close ne peut plus être reprise : supprimer ses fichiers et les anciennes versions.
            $this->removeResetTree($work);
            foreach ($oldVersions as $oldVersion) $this->removeResetTree($oldVersion);

            // La progression repart vierge pour une future préparation.
            $this->writeState($progressPath, new \stdClass());
            $state['active_operation'] = null;
            $state['available_update'] = null;
            $this->writeState($path, $state);
            return ['cleaned' => true];
        } finally {
            foreach (array_reverse($locks) as $handle) {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }
    }

    /** Limiter la suppression aux versions numériques, après contrôle de la release réellement exécutée. */
    private function oldVersionDirectories(string $root, string $active): array
    {
        if (preg_match('/\Av(?:dev|(?:0|[1-9][0-9]*)_(?:0|[1-9][0-9]*)_(?:0|[1-9][0-9]*))\z/', $active) !== 1
            || $active !== $this->runningRelease()) {
            throw new RuntimeException('Release active incohérente ; nettoyage des versions refusé.');
        }
        if (is_link($root . '/releases') || !is_dir($root . '/releases') || realpath($root . '/releases') !== $root . '/releases') {
            throw new RuntimeException('Dossier des releases inaccessible ; nettoyage refusé.');
        }
        $current = $root . '/releases/' . $active;
        if (is_link($current) || !is_dir($current) || realpath($current) !== $current) {
            throw new RuntimeException('Release active inaccessible ; nettoyage des versions refusé.');
        }

        $targets = [];
        foreach ([$root . '/releases' => 'v', $root . '/public' => 'assets_core_v'] as $parent => $prefix) {
            foreach (new \FilesystemIterator($parent) as $entry) {
                $name = $entry->getFilename();
                if (preg_match('/\A' . preg_quote($prefix, '/') . '(?:0|[1-9][0-9]*)_(?:0|[1-9][0-9]*)_(?:0|[1-9][0-9]*)\z/', $name) !== 1
                    || $name === ($prefix === 'v' ? $active : 'assets_core_' . $active)) continue;
                if ($entry->isLink() || !$entry->isDir()) {
                    throw new RuntimeException('Ancienne version invalide ; nettoyage refusé.');
                }
                $targets[] = $entry->getPathname();
            }
        }
        return $targets;
    }

    /** Vérifie un dossier d'opération avant suppression ; refuse tout contenu inconnu. */
    private function operationDirectory(string $path): string
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new RuntimeException('Dossier d’opération invalide ; suppression refusée.');
        }
        if (!is_dir($path)) return $path;

        // Seuls l'espace de travail et la sauvegarde privée sont attendus ;
        // toute autre entrée bloque la suppression avant modification des JSON.
        foreach (new \FilesystemIterator($path) as $child) {
            if (!in_array($child->getFilename(), ['backup', 'stage'], true)
                || $child->isLink() || !$child->isDir()) {
                throw new RuntimeException('Dossier d’opération incomplet ; suppression refusée.');
            }
        }

        return $path;
    }

    protected function removeResetTree(string $path): void
    {
        // Supprimer le lien lui-même : ne jamais descendre dans une cible extérieure.
        if (is_link($path)) {
            if (!@unlink($path)) throw new RuntimeException('Impossible de supprimer un lien temporaire. Relancez l’opération.');
        } elseif (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $entry) $this->removeResetTree($entry->getPathname());
            if (!@rmdir($path)) throw new RuntimeException('Impossible de supprimer un dossier temporaire. Relancez l’opération.');
        } elseif (file_exists($path)) {
            if (!@unlink($path)) throw new RuntimeException('Impossible de supprimer un fichier temporaire. Relancez l’opération.');
        }
    }

    protected function installationRoot(): string { return APP_ROOT; }

    protected function developmentMode(): bool { return $this->in_dev; }

    protected function runningRelease(): string { return 'v' . APP_VERSION_STRING; }

    private function receipt(array $operation, bool $reused): array
    {
        return ['version' => $operation['version'], 'package_id' => $operation['package_id'],
            'url' => '/' . $operation['entrypoint'], 'development' => $operation['development'], 'reused' => $reused];
    }

    private function readState(string $path): array
    {
        $this->regularFile($path);
        $contents = @file_get_contents($path);
        try {
            $state = json_decode($contents === false ? '' : $contents, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('État des releases illisible.');
        }
        if (!is_array($state) || ($state['schema_version'] ?? null) !== 1 || !is_string($state['active_release'] ?? null)) {
            throw new RuntimeException('État des releases invalide.');
        }
        return $state;
    }

    private function writeState(string $path, array|\stdClass $state): void
    {
        $temporary = dirname($path) . '/.state-' . bin2hex(random_bytes(16));
        try {
            $this->writeFile($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
            if (!@rename($temporary, $path)) {
                throw new RuntimeException('Impossible d’enregistrer l’état de préparation.');
            }
        } finally {
            if (is_file($temporary)) @unlink($temporary);
        }
    }

    /** Extraction en flux dans un dossier neuf ; limites réelles, taille et CRC contrôlés. */
    private function extract(array $package, string $destination): void
    {
        $archive = $package['directory'] . '/package.zip';
        ArchiveInspector::inspect($archive, $package['manifest']['version']);
        $zip = new ZipArchive();
        if (@$zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Impossible d’ouvrir le ZIP vérifié.');
        }
        $total = 0;
        try {
            // Prévoir l'extraction et la seconde copie du moteur avant de consommer l'espace disque.
            $required = 1048576;
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $entry = $zip->statIndex($index);
                if ($entry === false) throw new RuntimeException('Entrée ZIP illisible.');
                $required += $entry['size'];
                if (str_starts_with($entry['name'], 'updater/') || str_starts_with($entry['name'], 'public/assets_updater/')) {
                    $required += $entry['size'];
                }
            }
            $free = @disk_free_space(dirname($destination));
            if ($free !== false && $free < $required) throw new RuntimeException('Espace disque insuffisant pour préparer la mise à jour.');
            $this->directory($destination);
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false) throw new RuntimeException('Entrée ZIP illisible.');
                $name = $stat['name'];
                $target = $destination . '/' . rtrim($name, '/');
                if (str_ends_with($name, '/')) {
                    $this->parents($destination, rtrim($name, '/'));
                    continue;
                }
                $this->parents($destination, dirname($name));
                $input = @$zip->getStream($name);
                $output = @fopen($target, 'xb');
                if ($input === false || $output === false) {
                    if (is_resource($input)) fclose($input);
                    if (is_resource($output)) fclose($output);
                    throw new RuntimeException('Impossible d’extraire un fichier du ZIP.');
                }
                $size = 0;
                $crc = hash_init('crc32b');
                try {
                    if (!@chmod($target, 0600)) throw new RuntimeException('Permissions d’extraction invalides.');
                    while (!feof($input)) {
                        $chunk = @fread($input, 65536);
                        if ($chunk === false || ($chunk === '' && !feof($input))) {
                            throw new RuntimeException('Lecture ZIP interrompue.');
                        }
                        $size += strlen($chunk);
                        $total += strlen($chunk);
                        if ($size > $stat['size'] || $total > 1073741824 || fwrite($output, $chunk) !== strlen($chunk)) {
                            throw new RuntimeException('Extraction incomplète ou taille décompressée excessive.');
                        }
                        hash_update($crc, $chunk);
                    }
                    if ($size !== $stat['size'] || hash_final($crc) !== sprintf('%08x', $stat['crc']) || !fflush($output)) {
                        throw new RuntimeException('Intégrité du fichier extrait invalide.');
                    }
                } finally {
                    fclose($input);
                    fclose($output);
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function parents(string $root, string $relative): void
    {
        if ($relative === '.') return;
        foreach (explode('/', $relative) as $part) {
            $root .= '/' . $part;
            $this->directory($root);
        }
    }

    private function directory(string $path, bool $private = true): void
    {
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, $private ? 0700 : 0755)) || realpath($path) !== $path) {
            throw new RuntimeException('Dossier de préparation inaccessible ou lien symbolique interdit.');
        }
    }

    private function regularFile(string $path, bool $optional = false): void
    {
        if (is_link($path) || ((!$optional || file_exists($path)) && !is_file($path))) {
            throw new RuntimeException('Fichier de préparation absent ou invalide.');
        }
    }

    private function writeFile(string $path, string $contents): void
    {
        $handle = @fopen($path, 'xb');
        if ($handle === false) throw new RuntimeException('Impossible de créer un fichier de préparation.');
        try {
            if (!@chmod($path, 0600) || fwrite($handle, $contents) !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException('Écriture de préparation incomplète.');
            }
        } finally {
            fclose($handle);
        }
    }

    private function copyTree(string $source, string $destination, bool $public): void
    {
        $this->directory($source);
        $this->directory($destination, !$public);
        foreach (new \FilesystemIterator($source) as $entry) {
            $target = $destination . '/' . $entry->getFilename();
            if ($entry->isLink()) throw new RuntimeException('Lien symbolique interdit dans les fichiers préparés.');
            if ($entry->isDir()) {
                $this->copyTree($entry->getPathname(), $target, $public);
            } else {
                $this->regularFile($entry->getPathname());
                if (file_exists($target) || is_link($target) || !@copy($entry->getPathname(), $target)
                    || !@chmod($target, $public ? 0644 : 0600)) {
                    throw new RuntimeException('Impossible de copier les fichiers du moteur.');
                }
            }
        }
    }

    private function installDirectory(string $source, string $destination, string $backup): void
    {
        $exists = is_dir($destination);
        if ($exists && !@rename($destination, $backup)) {
            throw new RuntimeException('Impossible de déplacer l’ancien moteur ou ses assets.');
        }
        if (!@rename($source, $destination)) {
            if ($exists) @rename($backup, $destination);
            throw new RuntimeException('Impossible d’installer le moteur ou ses assets.');
        }
    }

    private function removeTree(string $path): void
    {
        // Ne jamais suivre un lien lors du nettoyage des seuls dossiers de cette tentative.
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $entry) $this->removeTree($entry->getPathname());
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }

    private function entrypoint(array $operation): string
    {
        return <<<PHPENTRY
<?php

require_once dirname(__DIR__) . '/updater/index.php';
PHPENTRY;
    }
}
