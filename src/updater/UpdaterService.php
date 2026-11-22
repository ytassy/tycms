<?php

declare(strict_types=1);

namespace Tycms\Updater;

use RuntimeException;

require_once __DIR__ . '/OperationStore.php';
require_once __DIR__ . '/ReleasePreparer.php';
require_once __DIR__ . '/Maintenance.php';

/** Actions de mise à jour autorisées sous le verrou partagé. */
final class UpdaterService
{
    public function __construct(private readonly OperationStore $store) {}

    public function dispatch(string $entrypoint, string $action): array
    {
        return $this->store->exclusive(function () use ($entrypoint, $action): array {
            $snapshot = $this->store->snapshot($entrypoint);
            $phase = $snapshot['progress']['phase'] ?? 'new';
            return match ($action) {
                'status' => $this->receipt($snapshot['operation'], $phase, $snapshot['progress']),
                'prepare' => $this->receipt($snapshot['operation'], (new ReleasePreparer($this->store))->prepare($snapshot)['phase']),
                'maintenance' => $this->receipt($snapshot['operation'], $this->maintenance($snapshot)),
                'backup' => $this->receipt($snapshot['operation'], $this->backup($snapshot), $this->store->snapshot($entrypoint)['progress']),
                'skip_backup' => $this->receipt($snapshot['operation'], $this->skipBackup($snapshot)),
                'migrate' => $this->receipt($snapshot['operation'], $this->migrate($snapshot), $snapshot['progress']),
                'activate' => $this->receipt($snapshot['operation'], $this->activate($snapshot), $this->store->snapshot($entrypoint)['progress']),
                'online' => $this->online($snapshot),
                'restore' => $this->receipt($snapshot['operation'], $this->restore($snapshot), $this->store->snapshot($entrypoint)['progress']),
                'rollback' => $this->rollback($snapshot, false),
                'external_restore' => $this->rollback($snapshot, true),
                'cancel' => $this->cancel($snapshot),
                default => throw new RuntimeException('Action inconnue.'),
            };
        });
    }

    private function assertMaintenance(array $snapshot, bool $allowTarget = false): void
    {
        $active = $snapshot['state']['active_release'];
        $targetName = 'v' . str_replace('.', '_', $snapshot['operation']['version']);
        if (($snapshot['progress']['source_release'] ?? null) !== $active && !($allowTarget && $active === $targetName)) {
            throw new RuntimeException('La release source a changé ; opération refusée.');
        }
        $operation = $snapshot['operation'];
        $target = $this->targetRelease($snapshot);
        ReleasePreparer::assertOwner($target, $operation['id']);
        ReleasePreparer::assertOwner($this->targetAssets($snapshot), $operation['id']);
        $source = $this->sourceConfig($snapshot);
        $targetConfig = $target . '/configuration.php';
        if (!Maintenance::isOffline($source) || !Maintenance::isOffline($targetConfig)) {
            throw new RuntimeException('La source et la cible doivent être en maintenance.');
        }
        $sourceHash = hash_file('sha256', $source);
        $targetHash = hash_file('sha256', $targetConfig);
        if (!is_string($sourceHash) || !is_string($targetHash) || !hash_equals($sourceHash, $targetHash)) {
            throw new RuntimeException('Les configurations source et cible ne correspondent plus.');
        }
    }

    private function activate(array $snapshot): string
    {
        $progress = $snapshot['progress'];
        if (!in_array($progress['phase'] ?? null, ['migrated', 'activating', 'activation_failed'], true)) {
            throw new RuntimeException('L’activation est indisponible à cette étape.');
        }
        $this->assertMaintenance($snapshot, true);
        $progress['phase'] = 'activating';
        $this->store->saveProgress($progress);
        ob_start(static fn (string $output): string => '');
        try {
            require_once __DIR__ . '/DatabaseConnection.php';
            require_once __DIR__ . '/MigrationRunner.php';
            MigrationRunner::check($this->store->root(), $this->targetRelease($snapshot), $snapshot['operation']['version'], $progress['database_identity'] ?? null);
            $index = $this->targetRelease($snapshot) . '/index.php';
            if (is_link($index) || !is_file($index) || realpath($index) !== $index) throw new RuntimeException('Point d’entrée cible inaccessible.');
            $state = $snapshot['state'];
            $state['active_release'] = 'v' . str_replace('.', '_', $snapshot['operation']['version']);
            $this->store->saveReleaseState($state);
            $progress['phase'] = 'activated';
            $this->store->saveProgress($progress);
            return 'activated';
        } catch (\Throwable $error) {
            $progress['phase'] = 'activation_failed';
            $this->store->saveProgress($progress);
            error_log('Tycms updater : échec du contrôle de démarrage (' . get_class($error) . ')');
            throw new RuntimeException('Le contrôle de démarrage ou l’activation a échoué. Le site reste en maintenance.');
        } finally {
            ob_end_clean();
        }
    }

    private function online(array $snapshot): array
    {
        $progress = $snapshot['progress'];
        if (!in_array($progress['phase'] ?? null, ['activated', 'online_running', 'completed'], true)
            || ($snapshot['state']['active_release'] ?? null) !== 'v' . str_replace('.', '_', $snapshot['operation']['version'])
            || !is_bool($progress['source_offline'] ?? null)) {
            throw new RuntimeException('La remise en ligne est indisponible à cette étape.');
        }
        ReleasePreparer::assertOwner($this->targetRelease($snapshot), $snapshot['operation']['id']);
        ReleasePreparer::assertOwner($this->targetAssets($snapshot), $snapshot['operation']['id']);
        if ($progress['phase'] === 'activated') $this->assertMaintenance($snapshot, true);
        if ($progress['phase'] !== 'completed') {
            $progress['phase'] = 'online_running';
            $this->store->saveProgress($progress);
            // Respecter l'état initial : un site déjà hors ligne le reste.
            Maintenance::setOffline($this->targetRelease($snapshot) . '/configuration.php', $progress['source_offline']);
            $progress['phase'] = 'completed';
            $this->store->saveProgress($progress);
        }
        // La clôture révoque le lien ; le nettoyage ROOT conserve la sauvegarde et les releases.
        $state = $snapshot['state'];
        $state['active_operation']['status'] = 'completed';
        $this->store->saveReleaseState($state);
        return ['version' => $snapshot['operation']['version'], 'phase' => 'completed', 'source_offline' => $progress['source_offline'], 'closed' => true];
    }

    private function restore(array $snapshot): string
    {
        $progress = $snapshot['progress'];
        if (!in_array($progress['phase'] ?? null, ['migrating', 'migration_failed', 'migrated', 'activating', 'activation_failed', 'activated', 'restore_running', 'restore_failed'], true)
            || !isset($progress['backup_sha256'])) throw new RuntimeException('Restauration interne indisponible à cette étape.');
        if (array_key_exists('backup_complete', $progress) && $progress['backup_complete'] !== true) {
            throw new RuntimeException('Une sauvegarde partielle ne peut pas être restaurée automatiquement.');
        }
        $this->assertMaintenance($snapshot, true);
        $this->verifyBackup($snapshot, false);
        if (in_array($progress['phase'], ['migrated', 'activating', 'activation_failed', 'activated'], true)) $progress['migrations_completed'] = true;
        $progress['phase'] = 'restore_running';
        $this->store->saveProgress($progress);
        ob_start(static fn (string $output): string => '');
        try {
            require_once __DIR__ . '/DatabaseConnection.php';
            require_once __DIR__ . '/Lib/MariaDbBackup/DatabaseBackup.php';
            $config = DatabaseConnection::config($this->store->root(), $progress['source_release']);
            if (($config->RESET_DATABASE ?? false) !== false || ($config->offline ?? null) !== true
                || !hash_equals($progress['database_identity'], DatabaseConnection::identity($config))) {
                throw new RuntimeException('Configuration de restauration incohérente.');
            }
            $backup = new \Tycms\Updater\Lib\MariaDbBackup\DatabaseBackup(DatabaseConnection::connect($config));
            $backup->restore($this->backupDirectory($snapshot, false) . '/backup.jsonl', $progress['backup_size'], $progress['backup_sha256']);
            $progress['phase'] = 'restored';
            $this->store->saveProgress($progress);
            return 'restored';
        } catch (\Throwable $error) {
            $progress['phase'] = 'restore_failed';
            $this->store->saveProgress($progress);
            error_log('Tycms updater : échec de restauration (' . get_class($error) . ')');
            throw new RuntimeException('La restauration a échoué. Le site reste en maintenance ; la base peut être partiellement restaurée.');
        } finally {
            ob_end_clean();
        }
    }

    private function rollback(array $snapshot, bool $external): array
    {
        $phase = $snapshot['progress']['phase'] ?? null;
        if ($external) {
            if (!in_array($phase, ['migrating', 'migration_failed', 'migrated', 'activating', 'activation_failed', 'activated', 'restore_failed'], true)) {
                throw new RuntimeException('Confirmation de restauration externe indisponible.');
            }
            $this->assertMaintenance($snapshot, true);
            $snapshot['progress']['phase'] = 'restored';
            $this->store->saveProgress($snapshot['progress']);
        } elseif (!in_array($phase, ['restored', 'rolling_back', 'rolled_back'], true)) {
            throw new RuntimeException('Restaurez la base avant de revenir à la release source.');
        }
        if (!is_bool($snapshot['progress']['source_offline'] ?? null) || ($snapshot['progress']['owns_targets'] ?? null) !== true) {
            throw new RuntimeException('État de récupération incohérent.');
        }
        if ($phase === 'restored') $this->assertMaintenance($snapshot, true);
        return $this->cancel($snapshot, true);
    }

    private function backup(array $snapshot): string
    {
        $progress = $snapshot['progress'];
        if (!in_array($progress['phase'] ?? null, ['offline', 'backup_running', 'backup_failed'], true)) {
            throw new RuntimeException('La sauvegarde est indisponible à cette étape.');
        }
        $this->assertMaintenance($snapshot);
        $directory = $this->backupDirectory($snapshot, true);
        $path = $directory . '/backup.jsonl';
        if (is_link($path) || (file_exists($path) && (!is_file($path) || !@unlink($path)))) {
            throw new RuntimeException('Sauvegarde incomplète impossible à remplacer.');
        }
        foreach (['backup.sql', 'metadata.json'] as $filename) {
            $old = $directory . '/' . $filename;
            if (is_link($old) || (file_exists($old) && (!is_file($old) || !@unlink($old)))) {
                throw new RuntimeException('Fichier de sauvegarde invalide.');
            }
        }

        // Enregistrer le début avant de créer le fichier ; une reprise reste un choix explicite.
        unset($progress['backup_size'], $progress['backup_sha256'], $progress['backup_sql_size'], $progress['backup_sql_sha256'], $progress['backup_tables'], $progress['backup_complete'], $progress['backup_missing_privileges'], $progress['database_identity']);
        $progress['phase'] = 'backup_running';
        $this->store->saveProgress($progress);
        ob_start(static fn (string $output): string => '');
        try {
            require_once __DIR__ . '/DatabaseConnection.php';
            require_once __DIR__ . '/Lib/MariaDbBackup/DatabaseBackup.php';
            require_once __DIR__ . '/Lib/MariaDbBackup/SqlExporter.php';
            try {
                $config = DatabaseConnection::config($this->store->root(), $progress['source_release']);
            } catch (\Throwable $error) {
                error_log('Tycms updater : chargement de configuration impossible (' . get_class($error) . ')');
                throw new RuntimeException('Impossible de charger la configuration de sauvegarde.');
            }
            if (($config->RESET_DATABASE ?? false) !== false || ($config->offline ?? null) !== true) {
                throw new RuntimeException('Configuration de sauvegarde non sûre.');
            }
            $identity = DatabaseConnection::identity($config);
            $pdo = DatabaseConnection::connect($config);
            $result = (new \Tycms\Updater\Lib\MariaDbBackup\DatabaseBackup($pdo))->backup($path);
            $sql = (new \Tycms\Updater\Lib\MariaDbBackup\SqlExporter($pdo))->export($path, $directory . '/backup.sql', $result['size'], $result['sha256']);
            $progress['backup_size'] = $result['size'];
            $progress['backup_sha256'] = $result['sha256'];
            $progress['backup_tables'] = $result['tables'];
            $progress['backup_complete'] = $result['complete'];
            $progress['backup_missing_privileges'] = $result['missing_privileges'];
            $progress['backup_sql_size'] = $sql['size'];
            $progress['backup_sql_sha256'] = $sql['sha256'];
            $progress['database_identity'] = $identity;
            $this->writeBackupMetadata($directory, ['operation_id' => $snapshot['operation']['id'],
                'source_release' => $progress['source_release'], 'target_version' => $snapshot['operation']['version'],
                'size' => $result['size'], 'sha256' => $result['sha256'], 'tables' => $result['tables'],
                'complete' => $result['complete'], 'missing_privileges' => $result['missing_privileges'],
                'sql_size' => $sql['size'], 'sql_sha256' => $sql['sha256'],
                'database_identity' => $identity]);
            $progress['phase'] = 'backed_up';
            $this->store->saveProgress($progress);
            return 'backed_up';
        } catch (\Throwable $error) {
            $progress['phase'] = 'backup_failed';
            $this->store->saveProgress($progress);
            throw $error;
        } finally {
            ob_end_clean();
        }
    }

    private function skipBackup(array $snapshot): string
    {
        $progress = $snapshot['progress'];
        if (!in_array($progress['phase'] ?? null, ['offline', 'backup_running', 'backup_failed'], true)) {
            throw new RuntimeException('Le choix de sauvegarde est indisponible à cette étape.');
        }
        $this->assertMaintenance($snapshot);
        $this->removeIncompleteBackup($snapshot);
        unset($progress['backup_size'], $progress['backup_sha256'], $progress['backup_sql_size'], $progress['backup_sql_sha256'], $progress['backup_tables'], $progress['backup_complete'], $progress['backup_missing_privileges'], $progress['database_identity']);
        $progress['phase'] = 'backup_skipped';
        $this->store->saveProgress($progress);
        return 'backup_skipped';
    }

    private function migrate(array $snapshot): string
    {
        $progress = $snapshot['progress'];
        $phase = $progress['phase'] ?? null;
        if (!in_array($phase, ['backed_up', 'backup_skipped'], true)) {
            throw new RuntimeException('Les migrations ne peuvent pas être lancées à cette étape. Cliquez sur "Actualiser l\'état".');
        }
        $this->assertMaintenance($snapshot);
        $identity = null;
        if ($phase === 'backed_up') {
            $this->verifyBackup($snapshot);
            $identity = $progress['database_identity'];
        }

        // Le marqueur précède le chargement du code cible : une interruption ne doit jamais relancer les migrations.
        $progress['phase'] = 'migrating';
        $this->store->saveProgress($progress);
        ob_start(static fn (string $output): string => '');
        try {
            require_once __DIR__ . '/DatabaseConnection.php';
            require_once __DIR__ . '/MigrationRunner.php';
            MigrationRunner::run($this->store->root(), $this->targetRelease($snapshot), $snapshot['operation']['version'], $identity);
            $progress['migrations_completed'] = true;
            $progress['phase'] = 'migrated';
            $this->store->saveProgress($progress);
            return 'migrated';
        } catch (\Throwable $error) {
            unset($progress['migrations_completed']);
            $progress['phase'] = 'migration_failed';
            $this->store->saveProgress($progress);
            // Les exceptions de la release ou des extensions peuvent contenir des données sensibles.
            error_log('Tycms updater : échec des migrations (' . get_class($error) . ')');
            throw new RuntimeException('Les migrations ont échoué. Le site reste en maintenance ; une récupération est nécessaire.', 0, $error);
        } finally {
            ob_end_clean();
        }
    }

    private function backupDirectory(array $snapshot, bool $create): string
    {
        $work = $this->store->root() . '/var/updates/' . $snapshot['operation']['id'];
        if (is_link($work) || !is_dir($work) || realpath($work) !== $work) {
            throw new RuntimeException('Dossier de sauvegarde inaccessible.');
        }
        $directory = $work . '/backup';
        if ($create && !file_exists($directory) && !is_link($directory) && !@mkdir($directory, 0700)) {
            throw new RuntimeException('Impossible de créer le dossier de sauvegarde.');
        }
        if (is_link($directory) || !is_dir($directory) || realpath($directory) !== $directory
            || (($permissions = @fileperms($directory)) === false) || ($permissions & 0077) !== 0) {
            throw new RuntimeException('Dossier de sauvegarde non privé.');
        }
        return $directory;
    }

    private function removeIncompleteBackup(array $snapshot): void
    {
        $directory = $this->store->root() . '/var/updates/' . $snapshot['operation']['id'] . '/backup';
        if (!file_exists($directory) && !is_link($directory)) return;
        $directory = $this->backupDirectory($snapshot, false);
        foreach (['backup.jsonl', 'backup.sql', 'metadata.json'] as $name) {
            $path = $directory . '/' . $name;
            if (is_link($path) || (file_exists($path) && (!is_file($path) || !@unlink($path)))) {
                throw new RuntimeException('Impossible de supprimer la sauvegarde incomplète.');
            }
        }
    }

    private function writeBackupMetadata(string $directory, array $metadata): void
    {
        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        $temporary = $directory . '/.metadata-' . bin2hex(random_bytes(16));
        $handle = @fopen($temporary, 'xb');
        if ($handle === false) throw new RuntimeException('Écriture des métadonnées impossible.');
        try {
            if (!chmod($temporary, 0600) || fwrite($handle, $json) !== strlen($json) || !fflush($handle)) {
                throw new RuntimeException('Écriture des métadonnées incomplète.');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($temporary);
            throw $error;
        }
        if (!fclose($handle) || !@rename($temporary, $directory . '/metadata.json')) {
            @unlink($temporary);
            throw new RuntimeException('Publication des métadonnées impossible.');
        }
    }

    private function verifyBackup(array $snapshot, bool $verifySql = true): void
    {
        $progress = $snapshot['progress'];
        $path = $this->backupDirectory($snapshot, false) . '/backup.jsonl';
        if (!is_int($progress['backup_size'] ?? null) || $progress['backup_size'] <= 0
            || !is_string($progress['backup_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $progress['backup_sha256']) !== 1
            || !is_string($progress['database_identity'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $progress['database_identity']) !== 1
            || is_link($path) || !is_file($path) || filesize($path) !== $progress['backup_size']) {
            throw new RuntimeException('Sauvegarde absente ou altérée ; migrations refusées.');
        }
        $digest = hash_file('sha256', $path);
        if (!is_string($digest) || !hash_equals($progress['backup_sha256'], $digest)) {
            throw new RuntimeException('Sauvegarde absente ou altérée ; migrations refusées.');
        }
        $metadataPath = dirname($path) . '/metadata.json';
        if (is_link($metadataPath) || !is_file($metadataPath) || filesize($metadataPath) > 65536) {
            throw new RuntimeException('Métadonnées de sauvegarde absentes ou altérées.');
        }
        $metadata = json_decode((string) file_get_contents($metadataPath), true);
        if (!is_array($metadata) || ($metadata['operation_id'] ?? null) !== $snapshot['operation']['id']
            || ($metadata['source_release'] ?? null) !== $progress['source_release']
            || ($metadata['target_version'] ?? null) !== $snapshot['operation']['version']
            || ($metadata['size'] ?? null) !== $progress['backup_size']
            || ($metadata['sha256'] ?? null) !== $progress['backup_sha256']
            || ($metadata['database_identity'] ?? null) !== $progress['database_identity']) {
            throw new RuntimeException('Métadonnées de sauvegarde absentes ou altérées.');
        }
        if (array_key_exists('backup_complete', $progress)
            && (!is_bool($progress['backup_complete']) || ($metadata['complete'] ?? null) !== $progress['backup_complete']
                || !is_array($progress['backup_missing_privileges'] ?? null)
                || ($metadata['missing_privileges'] ?? null) !== $progress['backup_missing_privileges'])) {
            throw new RuntimeException('Métadonnées de sauvegarde absentes ou altérées.');
        }
        // Les anciennes sauvegardes sans export SQL restent utilisables par la bibliothèque JSONL.
        if ($verifySql && (array_key_exists('backup_sql_size', $progress) || array_key_exists('backup_sql_sha256', $progress))) {
            $sql = dirname($path) . '/backup.sql';
            if (!is_int($progress['backup_sql_size'] ?? null) || $progress['backup_sql_size'] <= 0
                || !is_string($progress['backup_sql_sha256'] ?? null) || preg_match('/\A[a-f0-9]{64}\z/', $progress['backup_sql_sha256']) !== 1
                || is_link($sql) || !is_file($sql) || filesize($sql) !== $progress['backup_sql_size']
                || ($metadata['sql_size'] ?? null) !== $progress['backup_sql_size']
                || ($metadata['sql_sha256'] ?? null) !== $progress['backup_sql_sha256']) {
                throw new RuntimeException('Export SQL absent ou altéré ; migrations refusées.');
            }
            $sqlHash = hash_file('sha256', $sql);
            if (!is_string($sqlHash) || !hash_equals($progress['backup_sql_sha256'], $sqlHash)) {
                throw new RuntimeException('Export SQL absent ou altéré ; migrations refusées.');
            }
        }
    }

    private function receipt(array $operation, string $phase, array $progress = []): array
    {
        $receipt = ['version' => $operation['version'], 'phase' => $phase];
        $receipt['migrations_completed'] = ($progress['migrations_completed'] ?? false) === true
            || in_array($phase, ['migrated', 'activating', 'activation_failed', 'activated', 'online_running', 'completed'], true);

        // Préserver la distinction entre sauvegarde effectuée et omise après les migrations.
        $later = ['migrating', 'migration_failed', 'migrated', 'activating', 'activation_failed', 'activated', 'online_running', 'restore_running', 'restore_failed', 'restored', 'rolling_back'];
        if ($phase === 'backed_up' || (in_array($phase, $later, true) && isset($progress['backup_sha256']))) {
            $receipt['backup'] = (array_key_exists('backup_complete', $progress) && $progress['backup_complete'] !== true) ? 'partial' : 'completed';
            if ($receipt['backup'] === 'partial') $receipt['backup_missing_privileges'] = $progress['backup_missing_privileges'] ?? [];
            $directory = $this->store->root() . '/var/updates/' . $operation['id'] . '/backup';
            $receipt['backup_paths'] = ['jsonl' => $directory . '/backup.jsonl'];
            if (isset($progress['backup_sql_sha256'])) $receipt['backup_paths']['sql'] = $directory . '/backup.sql';
        } elseif ($phase === 'backup_skipped' || in_array($phase, $later, true)) {
            $receipt['backup'] = 'skipped';
        }

        return $receipt;
    }

    private function maintenance(array $snapshot): string
    {
        $progress = $snapshot['progress'];
        $phase = $progress['phase'] ?? 'new';
        if (!in_array($phase, ['ready', 'maintenance_running', 'offline'], true)) {
            throw new RuntimeException('Préparez les fichiers avant d’activer la maintenance.');
        }
        if (($progress['source_release'] ?? null) !== $snapshot['state']['active_release']) {
            throw new RuntimeException('La release source a changé ; maintenance refusée.');
        }
        $source = $this->sourceConfig($snapshot);
        $target = $this->targetRelease($snapshot);
        ReleasePreparer::assertOwner($target, $snapshot['operation']['id']);
        ReleasePreparer::assertOwner($this->targetAssets($snapshot), $snapshot['operation']['id']);
        if ($phase === 'offline') return 'offline';

        if ($phase === 'ready') {
            // Conserver l'état initial pour ne pas mettre en ligne un site déjà en maintenance.
            $targetConfig = $target . '/configuration.php';
            if (is_link($source) || !is_file($source) || is_link($targetConfig) || !is_file($targetConfig)) {
                throw new RuntimeException('Configuration locale inaccessible.');
            }
            $sourceHash = @hash_file('sha256', $source);
            $targetHash = @hash_file('sha256', $targetConfig);
            if (!is_string($sourceHash) || !is_string($targetHash) || !hash_equals($sourceHash, $targetHash)) {
                throw new RuntimeException('La configuration a changé depuis la préparation ; annulez puis préparez à nouveau.');
            }
            $progress['source_offline'] = Maintenance::isOffline($source);
            $progress['phase'] = 'maintenance_running';
            $this->store->saveProgress($progress);
        } elseif (!is_bool($progress['source_offline'] ?? null)) {
            throw new RuntimeException('État de maintenance incohérent.');
        }

        // La cible est mise hors ligne en premier, puis la release actuellement active.
        Maintenance::setOffline($target . '/configuration.php', true);
        Maintenance::setOffline($source, true);
        $progress['phase'] = 'offline';
        $this->store->saveProgress($progress);
        return 'offline';
    }

    private function cancel(array $snapshot, bool $rollback = false): array
    {
        $progress = $snapshot['progress'];
        $phase = $progress['phase'] ?? 'new';
        if (!in_array($phase, $rollback ? ['restored', 'rolling_back', 'rolled_back'] : ['new', 'copying', 'publishing', 'ready', 'maintenance_running', 'offline', 'backup_running', 'backup_failed', 'backed_up', 'backup_skipped', 'cancelling', 'cancelled'], true)) {
            throw new RuntimeException('Cette étape ne permet plus d’annuler la mise à jour.');
        }
        $operation = $snapshot['operation'];
        $root = $this->store->root();
        if ($phase !== 'new' && ($progress['source_release'] ?? null) !== $snapshot['state']['active_release']
            && !($rollback && $snapshot['state']['active_release'] === 'v' . str_replace('.', '_', $operation['version']))) {
            throw new RuntimeException('La release source a changé ; annulation refusée.');
        }
        if (in_array($phase, ['maintenance_running', 'offline', 'backup_running', 'backup_failed', 'backed_up', 'backup_skipped'], true) && !is_bool($progress['source_offline'] ?? null)) {
            throw new RuntimeException('État de maintenance incohérent.');
        }
        if ($phase === 'new') $progress = ['operation_id' => $operation['id'], 'source_release' => $snapshot['state']['active_release'], 'owns_targets' => false];
        if (!is_bool($progress['owns_targets'] ?? null)) throw new RuntimeException('État de préparation incohérent.');
        if (in_array($phase, ['backup_running', 'backup_failed'], true)) $this->removeIncompleteBackup($snapshot);
        $progress['phase'] = $rollback ? 'rolling_back' : 'cancelling';
        $this->store->saveProgress($progress);

        if ($rollback && $snapshot['state']['active_release'] !== $progress['source_release']) {
            $state = $snapshot['state'];
            $state['active_release'] = $progress['source_release'];
            $this->store->saveReleaseState($state);
            $snapshot['state'] = $state;
        }

        // Une annulation rétablit exactement la valeur antérieure si nous avons commencé la maintenance.
        if (array_key_exists('source_offline', $progress)) {
            if (!is_bool($progress['source_offline'])) throw new RuntimeException('État de maintenance incohérent.');
            Maintenance::setOffline($this->sourceConfig($snapshot), $progress['source_offline']);
        }
        if ($progress['owns_targets']) {
            $this->sourceConfig($snapshot);
            foreach ([$this->targetRelease($snapshot), $this->targetAssets($snapshot)] as $target) {
                if (file_exists($target) || is_link($target)) {
                    ReleasePreparer::assertOwner($target, $operation['id']);
                    $this->removeTree($target);
                }
            }
        }
        $work = $root . '/var/updates/' . $operation['id'];
        if ($progress['owns_targets']) {
            if (is_link($work) || (file_exists($work) && (!is_dir($work) || realpath($work) !== $work))) {
                throw new RuntimeException('Dossier de travail invalide.');
            }
            if (is_dir($work)) {
                $this->removeTree($work . '/stage');
                if (count(scandir($work)) === 2 && !@rmdir($work)) throw new RuntimeException('Nettoyage impossible.');
            }
        }
        $packageDirectory = $root . '/var/updates/packages/' . $operation['package_id'];
        if (is_link($root . '/var/updates/packages') || realpath($root . '/var/updates/packages') !== $root . '/var/updates/packages'
            || is_link($packageDirectory) || !is_dir($packageDirectory) || realpath($packageDirectory) !== $packageDirectory) {
            throw new RuntimeException('Dossier de paquet inaccessible.');
        }
        $this->removeTree($packageDirectory . '/files');

        // Libérer le lien seulement après le nettoyage, pour qu'une interruption reste reprenable.
        $progress['phase'] = $rollback ? 'rolled_back' : 'cancelled';
        $this->store->saveProgress($progress);
        $state = $snapshot['state'];
        $state['active_operation'] = null;
        $state['available_update'] = null;
        $this->store->saveReleaseState($state);
        try {
            $this->store->saveProgress([]);
        } catch (\Throwable) {
            // L'état terminal reste reconnu si l'effacement est interrompu après révocation.
            error_log('Tycms updater : état terminal conservé après révocation.');
        }
        $entrypoint = $root . '/public/' . $operation['entrypoint'];
        if (is_link($entrypoint) || (is_file($entrypoint) && !@unlink($entrypoint))) {
            error_log('Tycms updater : lien révoqué, suppression physique impossible.');
        }
        return ['version' => $operation['version'], 'phase' => $rollback ? 'rolled_back' : 'cancelled', 'closed' => true];
    }

    private function sourceConfig(array $snapshot): string
    {
        return SourceConfiguration::path($this->store->root(), $snapshot['progress']['source_release'] ?? $snapshot['state']['active_release']);
    }

    private function targetRelease(array $snapshot): string
    {
        return $this->store->root() . '/releases/v' . str_replace('.', '_', $snapshot['operation']['version']);
    }

    private function targetAssets(array $snapshot): string
    {
        return $this->store->root() . '/public/assets_core_v' . str_replace('.', '_', $snapshot['operation']['version']);
    }

    /** Ne suit jamais les liens rencontrés dans les dossiers propres à l'opération. */
    private function removeTree(string $path): void
    {
        if (is_link($path)) throw new RuntimeException('Lien symbolique inattendu pendant l’annulation.');
        if (!file_exists($path)) return;
        if (!is_dir($path)) throw new RuntimeException('Dossier de mise à jour invalide.');
        foreach (new \FilesystemIterator($path) as $entry) {
            $child = $entry->getPathname();
            if ($entry->isLink()) {
                if (!@unlink($child)) throw new RuntimeException('Nettoyage impossible.');
            } elseif ($entry->isDir()) $this->removeTree($child);
            elseif (!$entry->isFile() || !@unlink($child)) throw new RuntimeException('Nettoyage impossible.');
        }
        if (!@rmdir($path)) throw new RuntimeException('Nettoyage impossible.');
    }
}
