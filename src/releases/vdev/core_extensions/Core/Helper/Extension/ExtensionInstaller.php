<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Extension;

use RuntimeException;
use Throwable;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Models\CatalogSource;
use YTassy\Tycms\CoreExts\Core\Models\ExtensionInstallation;
use YTassy\Tycms\DB\Migrations\Migration;
use YTassy\Tycms\Extensions\ExtensionMetadata;
use YTassy\Tycms\Extensions\ExtensionPackage;
use YTassy\Tycms\Http\SafeDownload;
use YTassy\Tycms\Tools\CriticalSection;
use ZipArchive;

/**
 * Installation neuve et mise à jour d'une extension depuis un catalogue vérifié.
 *
 * Le paquet est téléchargé puis signé/contrôlé avant toute pose. Les chemins
 * sont déduits des métadonnées signées, jamais d'une valeur du navigateur. En
 * cas d'échec, seuls les fichiers posés par cette tentative sont retirés ; les
 * migrations déjà exécutées ne sont pas annulées.
 *
 * Une mise à jour se fait en deux requêtes : la première sauvegarde l'ancienne
 * version et pose la nouvelle, la seconde exécute les migrations dans un
 * processus PHP neuf où la nouvelle classe est chargée.
 */
final class ExtensionInstaller extends BaseHelper
{
    private const DOWNLOAD_TIMEOUT = 120;
    private const LOCK_NAME = 'extension_operation';
    private const INSTALLING = 'installing';
    private const INSTALLED = 'installed';
    private const UPDATING = 'updating';
    private const FAILED = 'failed';

    private SafeDownload $download {
        get { return $this->getSharedExtensionsClass(SafeDownload::class); }
    }

    private CatalogSourceClient $client {
        get { return $this->getSharedExtensionsClass(CatalogSourceClient::class); }
    }

    private ExtensionUpdateState $updateState {
        get { return $this->getSharedExtensionsClass(ExtensionUpdateState::class); }
    }

    /**
     * @return array<string, mixed>
     */
    public function install(
        CatalogSource $source,
        string $identifier,
        string $version,
        ?int $userId,
    ): array {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('L’extension PHP zip est nécessaire pour installer une extension.');
        }

        $lock = new CriticalSection(self::LOCK_NAME);
        $stage = null;
        $placed = [];
        $reserved = false;

        try {
            return $lock->execute(function () use (
                $source,
                $identifier,
                $version,
                $userId,
                &$stage,
                &$placed,
                &$reserved,
            ): array {
                // Le verrou garantit qu'aucune autre installation n'est active :
                // un statut « installing » resté après une coupure peut donc
                // être repris, alors qu'un statut « installed » reste définitif.
                $this->assertNotInstalled($identifier);

                // Seule la dernière version publiée peut être installée : une
                // requête forgée ne peut pas imposer une version plus ancienne.
                $latest = $this->client->latestVersion($source, $identifier);
                if ($latest !== $version) {
                    throw new RuntimeException('Seule la dernière version publiée peut être installée.');
                }

                $verified = $this->client->verifiedVersion($source, $identifier, $version);
                $metadata = $verified['metadata'];
                if (($metadata['id'] ?? null) !== $identifier
                    || ($metadata['version'] ?? null) !== $version) {
                    throw new RuntimeException('Métadonnées signées incohérentes avec la demande.');
                }
                if ($verified['compatible'] === false) {
                    throw new RuntimeException('Cette version n’est pas compatible avec ce CMS ou ce serveur PHP.');
                }

                $extensionTarget = $this->extensionTarget($metadata);
                $assetsTarget = $this->assetsTarget($identifier);
                $this->assertTargetsAbsent($extensionTarget, $assetsTarget);

                // Réserver la provenance avant le téléchargement : le statut
                // reste « failed » en cas d'échec et une nouvelle tentative
                // peut reprendre la même ligne.
                $this->reserve($source, $metadata, $verified['manifest'], $userId);
                $reserved = true;

                $stage = $this->stage();
                $archive = $stage . '/package.zip';

                // La licence n'est jointe que pour l'origine de la source.
                $headers = $this->client->archiveHeaders($source, $verified['archive']['url']);
                $this->download->downloadToFile(
                    $verified['archive']['url'],
                    $archive,
                    $verified['archive']['bytes'],
                    self::DOWNLOAD_TIMEOUT,
                    $headers,
                );
                $this->verifyArchive($archive, $verified);

                // Extraire dans un dossier privé avant toute pose publique.
                $work = $stage . '/work';
                $this->extract($archive, $work);
                $stagedExtension = $work . '/extension';
                if (!is_dir($stagedExtension)) {
                    throw new RuntimeException('Le paquet ne contient pas le dossier extension/.');
                }

                // Contrôler la syntaxe PHP sans exécuter le code du paquet.
                $this->assertPhpSyntax($stagedExtension);

                // Revérifier juste avant la pose : le dossier a pu être créé
                // pendant le téléchargement.
                $this->assertTargetsAbsent($extensionTarget, $assetsTarget);

                if (!@rename($stagedExtension, $extensionTarget)) {
                    throw new RuntimeException('Impossible de poser les fichiers de l’extension.');
                }
                $placed[] = $extensionTarget;

                $stagedAssets = $work . '/assets';
                if (is_dir($stagedAssets)) {
                    if (!@rename($stagedAssets, $assetsTarget)) {
                        throw new RuntimeException('Impossible de poser les assets publics de l’extension.');
                    }
                    $placed[] = $assetsTarget;
                }

                // Une nouvelle instance Application charge l'extension posée et
                // exécute ses migrations en attente, sans session ni routage.
                $application = new Application(new \Configuration());
                $application->runMigrationsOrThrow();

                $this->markInstalled($identifier, $metadata, $verified['manifest'], $source->key_id);

                return [
                    'identifier' => $identifier,
                    'version' => $version,
                    'status' => self::INSTALLED,
                    'type' => $metadata['type'],
                    'folder' => $metadata['folder'],
                    'warning' => $verified['compatible'] === null
                        ? 'Compatibilité CMS/PHP non évaluée en release DEV.'
                        : null,
                ];
            }, false);
        } catch (Throwable $exception) {
            // Ne jamais laisser une extension à moitié posée : retirer les
            // dossiers de cette tentative, puis conserver le statut d'échec.
            $cleanupError = $this->rollback($placed);
            if ($reserved) {
                $this->markFailed($identifier);
            }

            if ($cleanupError !== null) {
                throw new RuntimeException(
                    $exception->getMessage() . ' Le nettoyage des fichiers posés a échoué : '
                    . $cleanupError->getMessage(),
                    0,
                    $exception,
                );
            }

            throw $exception;
        } finally {
            if ($stage !== null) {
                $this->removeTreeQuietly($stage);
            }
        }
    }

    /**
     * Prépare une mise à jour : vérifie le paquet, sauvegarde l'ancienne version
     * et pose la nouvelle. Les migrations sont exécutées par finalizeUpdate.
     *
     * @return array<string, mixed>
     */
    public function prepareUpdate(
        CatalogSource $source,
        string $identifier,
        string $version,
    ): array {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('L’extension PHP zip est nécessaire pour mettre à jour une extension.');
        }

        $lock = new CriticalSection(self::LOCK_NAME);
        $stage = null;
        $stateDirectory = null;
        $placed = [];
        $previous = [];
        $stateWritten = false;

        try {
            return $lock->execute(function () use (
                $source,
                $identifier,
                $version,
                &$stage,
                &$stateDirectory,
                &$placed,
                &$previous,
                &$stateWritten,
            ): array {
                $extension = $this->app->getExtensions()[$identifier] ?? null;
                if ($extension === null) {
                    throw new RuntimeException('Cette extension n’est pas chargée ; seule une extension installée peut être mise à jour.');
                }
                $localVersion = $this->extensionVersion($extension);

                $installation = $this->installation($identifier);
                if ($installation === null || $installation->status !== self::INSTALLED) {
                    throw new RuntimeException('Cette extension n’a pas de provenance installée compatible avec une mise à jour.');
                }
                if ($installation->version !== $localVersion) {
                    throw new RuntimeException('La version locale ne correspond pas à la provenance enregistrée.');
                }
                if ($this->updateState->pending($identifier) !== null) {
                    throw new RuntimeException('Une mise à jour est déjà préparée ; terminez-la ou restaurez l’ancienne version.');
                }

                // Seule la dernière version publiée et strictement supérieure
                // peut être installée par une mise à jour.
                $latest = $this->client->latestVersion($source, $identifier);
                if ($latest !== $version) {
                    throw new RuntimeException('Seule la dernière version publiée peut être installée.');
                }
                if (version_compare($version, $localVersion, '>') === false) {
                    throw new RuntimeException('La version cible doit être supérieure à la version installée.');
                }

                $verified = $this->client->verifiedVersion($source, $identifier, $version);
                $metadata = $verified['metadata'];
                if (($metadata['id'] ?? null) !== $identifier
                    || ($metadata['version'] ?? null) !== $version) {
                    throw new RuntimeException('Métadonnées signées incohérentes avec la demande.');
                }
                if (($metadata['type'] ?? null) !== $installation->type
                    || ($metadata['folder'] ?? null) !== $installation->folder) {
                    throw new RuntimeException('Le paquet ne correspond pas à l’extension installée.');
                }
                if ($verified['compatible'] === false) {
                    throw new RuntimeException('Cette version n’est pas compatible avec ce CMS ou ce serveur PHP.');
                }
                if (!CatalogCompatibility::sourceAllows($metadata['source_versions'] ?? null, $localVersion)) {
                    throw new RuntimeException(
                        'Cette version n’autorise pas la mise à jour depuis la version ' . $localVersion . '.',
                    );
                }

                $extensionTarget = $this->extensionTarget($metadata);
                $assetsTarget = $this->assetsTarget($identifier);
                $this->assertUpdateTargets($extensionTarget, $assetsTarget);

                $stage = $this->stage();
                $archive = $stage . '/package.zip';

                // La licence n'est jointe que pour l'origine de la source.
                $headers = $this->client->archiveHeaders($source, $verified['archive']['url']);
                $this->download->downloadToFile(
                    $verified['archive']['url'],
                    $archive,
                    $verified['archive']['bytes'],
                    self::DOWNLOAD_TIMEOUT,
                    $headers,
                );
                $this->verifyArchive($archive, $verified);

                $work = $stage . '/work';
                $this->extract($archive, $work);
                $stagedExtension = $work . '/extension';
                if (!is_dir($stagedExtension)) {
                    throw new RuntimeException('Le paquet ne contient pas le dossier extension/.');
                }
                $stagedAssets = $work . '/assets';

                // Contrôler la syntaxe PHP avant de déplacer l'ancienne version.
                $this->assertPhpSyntax($stagedExtension);

                // Revérifier juste avant de déplacer l'ancienne version.
                $this->assertUpdateTargets($extensionTarget, $assetsTarget);

                $operationId = bin2hex(random_bytes(16));
                $stateDirectory = $this->privateDirectory(
                    $this->updateState->directory() . '/.update-' . $operationId,
                    0700,
                );

                // Sauvegarder l'ancienne extension avant toute pose.
                $backupExtension = $stateDirectory . '/previous-extension';
                if (!@rename($extensionTarget, $backupExtension)) {
                    throw new RuntimeException('Impossible de sauvegarder l’ancienne version de l’extension.');
                }
                $previous['extension'] = ['original' => $extensionTarget, 'backup' => $backupExtension];

                $backupAssets = $stateDirectory . '/previous-assets';
                if (is_dir($assetsTarget)) {
                    if (is_link($assetsTarget) || !@rename($assetsTarget, $backupAssets)) {
                        throw new RuntimeException('Impossible de sauvegarder les anciens assets de l’extension.');
                    }
                    $previous['assets'] = ['original' => $assetsTarget, 'backup' => $backupAssets];
                }

                if (!@rename($stagedExtension, $extensionTarget)) {
                    throw new RuntimeException('Impossible de poser les nouveaux fichiers de l’extension.');
                }
                $placed[] = $extensionTarget;

                if (is_dir($stagedAssets)) {
                    if (!@rename($stagedAssets, $assetsTarget)) {
                        throw new RuntimeException('Impossible de poser les nouveaux assets de l’extension.');
                    }
                    $placed[] = $assetsTarget;
                }

                $state = [
                    'schema_version' => 1,
                    'operation_id' => $operationId,
                    'extension_id' => $identifier,
                    'type' => $metadata['type'],
                    'folder' => $metadata['folder'],
                    'from_version' => $localVersion,
                    'to_version' => $version,
                    'source_id' => $source->id,
                    'source_name' => $source->name,
                    'source_url' => $source->base_url,
                    'key_id' => $source->key_id,
                    'archive_sha256' => $verified['manifest']['archive']['sha256'],
                    'backup_directory' => $stateDirectory,
                    'status' => 'files_updated',
                    'warning' => $verified['compatible'] === null
                        ? 'Compatibilité CMS/PHP non évaluée en release DEV.'
                        : null,
                    'created' => gmdate('c'),
                ];
                $this->updateState->write($identifier, $state);
                $stateWritten = true;
                $this->markUpdating($identifier);

                // Ne pas supprimer la sauvegarde : finalizeUpdate en a besoin
                // pour restaurer l'ancienne version si les migrations échouent.
                return [
                    'identifier' => $identifier,
                    'operation_id' => $operationId,
                    'from_version' => $localVersion,
                    'to_version' => $version,
                    'status' => 'files_updated',
                    'warning' => $state['warning'],
                ];
            }, false);
        } catch (Throwable $exception) {
            $cleanupError = $this->rollbackPrepared($placed, $previous);
            $stateDeleted = !$stateWritten;
            if ($stateWritten) {
                try {
                    $this->updateState->delete($identifier);
                    $stateDeleted = true;
                } catch (Throwable) {
                    // Conserver la sauvegarde si l'état n'a pas pu être retiré.
                }
            }
            if ($stateDirectory !== null && $stateDeleted) {
                $this->removeTreeQuietly($stateDirectory);
            }
            $this->markUpdateAborted($identifier);

            if ($cleanupError !== null) {
                throw new RuntimeException(
                    $exception->getMessage() . ' La restauration des fichiers a échoué : '
                    . $cleanupError->getMessage(),
                    0,
                    $exception,
                );
            }

            throw $exception;
        } finally {
            if ($stage !== null) {
                $this->removeTreeQuietly($stage);
            }
        }
    }

    /**
     * Finalise une mise à jour dans une requête neuve : la nouvelle classe est
     * chargée, les migrations de l'extension peuvent être exécutées.
     *
     * @return array<string, mixed>
     */
    public function finalizeUpdate(string $identifier, string $operationId): array
    {
        $lock = new CriticalSection(self::LOCK_NAME);
        $validated = false;

        try {
            return $lock->execute(function () use ($identifier, $operationId, &$validated): array {
                $state = $this->updateState->read($identifier);
                if ($state === null) {
                    throw new RuntimeException('Aucune mise à jour préparée pour cette extension.');
                }
                if (!hash_equals($state['operation_id'], $operationId)) {
                    throw new RuntimeException('Opération de mise à jour inconnue.');
                }
                $validated = true;

                $installation = $this->installation($identifier);

                // Nettoyage idempotent si la mise à jour est déjà enregistrée.
                if ($installation !== null && $installation->status === self::INSTALLED
                    && $installation->version === $state['to_version']) {
                    $this->cleanupUpdateState($identifier, $state);

                    return [
                        'identifier' => $identifier,
                        'version' => $state['to_version'],
                        'status' => self::INSTALLED,
                        'warning' => $state['warning'],
                    ];
                }
                if ($installation === null || $installation->status !== self::UPDATING) {
                    throw new RuntimeException('État de mise à jour incohérent.');
                }

                $extension = $this->app->getExtensions()[$identifier] ?? null;
                if ($extension === null) {
                    throw new RuntimeException('L’extension mise à jour n’est pas chargée.');
                }
                if ($this->extensionVersion($extension) !== $state['to_version']) {
                    throw new RuntimeException('La version chargée ne correspond pas à la mise à jour préparée.');
                }

                // Contrôler les fichiers réellement posés avant les migrations.
                $metadata = ExtensionMetadata::read($this->targetFromState($state) . '/version.json');
                if (($metadata['id'] ?? null) !== $identifier
                    || ($metadata['version'] ?? null) !== $state['to_version']
                    || ($metadata['type'] ?? null) !== $state['type']
                    || ($metadata['folder'] ?? null) !== $state['folder']) {
                    throw new RuntimeException('Les fichiers posés ne correspondent pas à la mise à jour.');
                }

                // La classe de la nouvelle version est déjà chargée par cette
                // requête : le runner peut exécuter ses migrations en attente.
                (new Migration($this->app))->runMigrations($identifier, $extension);
                $this->markUpdated($identifier, $state);
                $this->cleanupUpdateState($identifier, $state);

                return [
                    'identifier' => $identifier,
                    'version' => $state['to_version'],
                    'status' => self::INSTALLED,
                    'warning' => $state['warning'],
                ];
            }, false);
        } catch (Throwable $exception) {
            if (!$validated) {
                throw $exception;
            }

            $state = null;
            try {
                $state = $this->updateState->read($identifier);
            } catch (Throwable) {
                // L'état est illisible : ne pas tenter une restauration aveugle.
            }

            if ($state === null) {
                throw $exception;
            }

            // Restaurer si la provenance est absente, encore en « updating » ou
            // revenue à la version d'origine. Ne jamais écraser une mise à jour
            // déjà enregistrée vers la version cible.
            try {
                $installation = $this->installation($identifier);
            } catch (Throwable) {
                $installation = null;
            }
            $canRestore = $installation === null
                || $installation->status === self::UPDATING
                || ($installation->status === self::INSTALLED
                    && $installation->version === $state['from_version']);
            if (!$canRestore) {
                throw $exception;
            }
            try {
                $this->restoreUpdate($identifier, $state);
                $this->cleanupUpdateState($identifier, $state);
            } catch (Throwable $restoreException) {
                throw new RuntimeException(
                    'La mise à jour a échoué et la restauration automatique a échoué. '
                    . $exception->getMessage() . ' / ' . $restoreException->getMessage(),
                    0,
                    $exception,
                );
            }

            throw new RuntimeException(
                'La mise à jour a échoué ; l’ancienne version a été restaurée. '
                . 'Les migrations SQL déjà exécutées ne sont pas annulées. ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /**
     * Restaure l'ancienne version après une mise à jour interrompue.
     *
     * @return array<string, mixed>
     */
    public function rollbackUpdate(string $identifier, string $operationId): array
    {
        $lock = new CriticalSection(self::LOCK_NAME);

        try {
            return $lock->execute(function () use ($identifier, $operationId): array {
                $state = $this->updateState->read($identifier);
                if ($state === null) {
                    throw new RuntimeException('Aucune mise à jour préparée pour cette extension.');
                }
                if (!hash_equals($state['operation_id'], $operationId)) {
                    throw new RuntimeException('Opération de mise à jour inconnue.');
                }

                $installation = $this->installation($identifier);
                if ($installation === null || $installation->status !== self::UPDATING) {
                    throw new RuntimeException('Aucune mise à jour interrompue à restaurer.');
                }

                $this->restoreUpdate($identifier, $state);
                $this->cleanupUpdateState($identifier, $state);

                return [
                    'identifier' => $identifier,
                    'version' => $state['from_version'],
                    'status' => self::INSTALLED,
                    'warning' => 'Les migrations SQL déjà exécutées ne sont pas annulées.',
                ];
            }, false);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'La restauration de l’ancienne version a échoué : ' . $exception->getMessage(),
                0,
                $exception,
            );
        }
    }

    /** État de mise à jour en attente, pour la consultation. */
    public function pendingUpdate(string $identifier): ?array
    {
        return $this->updateState->pending($identifier);
    }

    /** Contrôler la syntaxe de tous les fichiers PHP du paquet, sans exécution. */
    private function assertPhpSyntax(string $root): void
    {
        $files = 0;
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()
                || !str_ends_with(strtolower($file->getFilename()), '.php')) {
                continue;
            }

            $files++;
            $size = $file->getSize();
            if ($files > 2000 || $size > 1048576 || ($total += $size) > 16777216) {
                throw new RuntimeException('Le paquet contient trop de code PHP à contrôler.');
            }

            $contents = @file_get_contents($file->getPathname());
            if (!is_string($contents)) {
                throw new RuntimeException('Un fichier PHP du paquet est illisible.');
            }

            try {
                // TOKEN_PARSE active le parseur : aucune fonction n'est appelée.
                token_get_all($contents, TOKEN_PARSE);
            } catch (\ParseError) {
                throw new RuntimeException('Fichier PHP invalide dans le paquet : ' . $file->getFilename());
            }
        }
    }

    private function assertUpdateTargets(string $extensionTarget, string $assetsTarget): void
    {        if (is_link($extensionTarget) || !is_dir($extensionTarget)
            || realpath($extensionTarget) !== $extensionTarget) {
            throw new RuntimeException('Le dossier actuel de l’extension est introuvable ou invalide.');
        }

        if ((file_exists($assetsTarget) || is_link($assetsTarget))
            && (is_link($assetsTarget) || !is_dir($assetsTarget) || realpath($assetsTarget) !== $assetsTarget)) {
            throw new RuntimeException('Le dossier actuel des assets est invalide.');
        }
    }

    private function extensionVersion(object $extension): string
    {
        try {
            return $extension->getVersion();
        } catch (Throwable $exception) {
            throw new RuntimeException('Impossible de lire la version locale de l’extension.', 0, $exception);
        }
    }

    /** @param array<string, mixed> $state */
    private function targetFromState(array $state): string
    {
        return $this->extensionTarget([
            'type' => $state['type'],
            'folder' => $state['folder'],
        ]);
    }

    private function markUpdating(string $identifier): void
    {
        $affected = $this->db->execute(
            'UPDATE extension_installations
             SET status = %, updated = CURRENT_TIMESTAMP
             WHERE extension_id = %',
            [self::UPDATING, $identifier],
        );
        if ($affected < 1) {
            throw new RuntimeException('La provenance de l’extension a changé ; mise à jour annulée.');
        }
    }

    private function markUpdateAborted(string $identifier): void
    {
        $this->db->execute(
            'UPDATE extension_installations
             SET status = %, updated = CURRENT_TIMESTAMP
             WHERE extension_id = % AND status = %',
            [self::INSTALLED, $identifier, self::UPDATING],
        );
    }

    /** @param array<string, mixed> $state */
    private function markUpdated(string $identifier, array $state): void
    {
        $affected = $this->db->execute(
            'UPDATE extension_installations
             SET type = %, folder = %, source_id = %, source_name = %, source_url = %,
                 version = %, key_id = %, archive_sha256 = %, status = %, updated = CURRENT_TIMESTAMP
             WHERE extension_id = %',
            [
                $state['type'],
                $state['folder'],
                $state['source_id'],
                $state['source_name'],
                $state['source_url'],
                $state['to_version'],
                $state['key_id'],
                $state['archive_sha256'],
                self::INSTALLED,
                $identifier,
            ],
        );
        if ($affected < 1) {
            throw new RuntimeException('La provenance de l’extension a changé ; mise à jour incomplète.');
        }
    }

    /**
     * Retirer les nouveaux dossiers puis restaurer les sauvegardes de la
     * préparation courante. Retourne la première erreur rencontrée.
     *
     * @param list<string> $placed
     * @param array<string, array{original:string,backup:string}> $previous
     */
    private function rollbackPrepared(array $placed, array $previous): ?Throwable
    {
        $error = null;
        foreach (array_reverse($placed) as $path) {
            try {
                $this->removeTree($path);
            } catch (Throwable $exception) {
                $error ??= $exception;
            }
        }

        foreach (array_reverse($previous) as $item) {
            try {
                if (is_link($item['backup']) || !is_dir($item['backup'])) {
                    throw new RuntimeException('Sauvegarde de mise à jour introuvable.');
                }
                if (!@rename($item['backup'], $item['original'])) {
                    throw new RuntimeException('Impossible de restaurer un dossier sauvegardé.');
                }
            } catch (Throwable $exception) {
                $error ??= $exception;
            }
        }

        return $error;
    }

    /** @param array<string, mixed> $state */
    private function restoreUpdate(string $identifier, array $state): void
    {
        $extensionTarget = $this->targetFromState($state);
        $assetsTarget = $this->assetsTarget($identifier);
        $backupDirectory = $state['backup_directory'];

        if (is_link($extensionTarget) || file_exists($extensionTarget)) {
            $this->removeTree($extensionTarget);
        }
        if (is_link($assetsTarget) || file_exists($assetsTarget)) {
            $this->removeTree($assetsTarget);
        }

        $backupExtension = $backupDirectory . '/previous-extension';
        if (is_link($backupExtension) || !is_dir($backupExtension)) {
            throw new RuntimeException('Sauvegarde de l’ancienne extension introuvable.');
        }
        if (!@rename($backupExtension, $extensionTarget)) {
            throw new RuntimeException('Impossible de restaurer l’ancienne extension.');
        }

        $backupAssets = $backupDirectory . '/previous-assets';
        if (is_link($backupAssets) || (file_exists($backupAssets) && !is_dir($backupAssets))) {
            throw new RuntimeException('Sauvegarde des anciens assets invalide.');
        }
        if (is_dir($backupAssets) && !@rename($backupAssets, $assetsTarget)) {
            throw new RuntimeException('Impossible de restaurer les anciens assets.');
        }

        $this->markUpdateAborted($identifier);
    }

    /** @param array<string, mixed> $state */
    private function cleanupUpdateState(string $identifier, array $state): void
    {
        $deleted = true;
        try {
            $this->updateState->delete($identifier);
        } catch (Throwable) {
            // Conserver la sauvegarde si l'état n'a pas pu être retiré.
            $deleted = false;
        }

        if ($deleted) {
            $this->removeTreeQuietly($state['backup_directory']);
        }
    }

    /** @return array<string, mixed> */
    public function view(ExtensionInstallation $installation): array
    {
        return [
            'extension_id' => $installation->extension_id,
            'type' => $installation->type,
            'folder' => $installation->folder,
            'source_id' => $installation->source_id,
            'source_name' => $installation->source_name,
            'source_url' => $installation->source_url,
            'version' => $installation->version,
            'key_id' => $installation->key_id,
            'archive_sha256' => $installation->archive_sha256,
            'status' => $installation->status,
            'created' => $installation->created->format(DATE_ATOM),
            'updated' => $installation->updated?->format(DATE_ATOM),
        ];
    }

    private function assertNotInstalled(string $identifier): void
    {
        $existing = $this->installation($identifier);
        if ($existing !== null && in_array($existing->status, [self::INSTALLED, self::UPDATING], true)) {
            throw new RuntimeException($existing->status === self::UPDATING
                ? 'Une mise à jour est en cours ; terminez-la ou restaurez l’ancienne version.'
                : 'Cette extension est déjà installée.');
        }
        if ($this->updateState->pending($identifier) !== null) {
            throw new RuntimeException('Une mise à jour préparée doit être finalisée ou annulée.');
        }
        if (array_key_exists($identifier, $this->app->getExtensions())) {
            throw new RuntimeException('Cette extension est déjà chargée par le CMS.');
        }
    }

    private function installation(string $identifier): ?ExtensionInstallation
    {
        try {
            return $this->db->selectOrmOneOrNull(
                ExtensionInstallation::class,
                'SELECT * FROM extension_installations WHERE extension_id = % LIMIT 1',
                [$identifier],
            );
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'La table des installations est indisponible. Lancez d’abord les migrations Core 1.1.4 et 1.1.5.',
                0,
                $exception,
            );
        }
    }

    /** @param array<string, mixed> $metadata */
    private function extensionTarget(array $metadata): string
    {
        $root = $this->root();
        $relative = ExtensionMetadata::relativePath($metadata);
        $parts = explode('/', $relative, 2);

        $extensions = $this->publicDirectory($root . '/extensions');
        $typeDirectory = $this->publicDirectory($extensions . '/' . $parts[0]);

        return $typeDirectory . '/' . $parts[1];
    }

    private function assetsTarget(string $identifier): string
    {
        $assets = $this->publicDirectory($this->root() . '/public/assets');

        return $assets . '/' . $identifier;
    }

    private function assertTargetsAbsent(string $extensionTarget, string $assetsTarget): void
    {
        foreach ([$extensionTarget, $assetsTarget] as $target) {
            if (file_exists($target) || is_link($target)) {
                throw new RuntimeException(
                    'Un dossier de destination existe déjà ; installation refusée : ' . basename($target),
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $manifest
     */
    private function reserve(
        CatalogSource $source,
        array $metadata,
        array $manifest,
        ?int $userId,
    ): void {
        $existing = $this->installation($metadata['id']);
        $sha256 = $manifest['archive']['sha256'];

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO extension_installations
                    (extension_id, type, folder, source_id, source_name, source_url, version,
                     key_id, archive_sha256, status, created_by)
                 VALUES (%, %, %, %, %, %, %, %, %, %, %)',
                [
                    $metadata['id'],
                    $metadata['type'],
                    $metadata['folder'],
                    $source->id,
                    $source->name,
                    $source->base_url,
                    $metadata['version'],
                    $source->key_id,
                    $sha256,
                    self::INSTALLING,
                    $userId,
                ],
            );

            return;
        }

        $this->db->execute(
            'UPDATE extension_installations
             SET type = %, folder = %, source_id = %, source_name = %, source_url = %, version = %,
                 key_id = %, archive_sha256 = %, status = %, updated = CURRENT_TIMESTAMP
             WHERE extension_id = %',
            [
                $metadata['type'],
                $metadata['folder'],
                $source->id,
                $source->name,
                $source->base_url,
                $metadata['version'],
                $source->key_id,
                $sha256,
                self::INSTALLING,
                $metadata['id'],
            ],
        );
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $manifest
     */
    private function markInstalled(
        string $identifier,
        array $metadata,
        array $manifest,
        string $keyId,
    ): void {
        $this->db->execute(
            'UPDATE extension_installations
             SET version = %, key_id = %, archive_sha256 = %, status = %, updated = CURRENT_TIMESTAMP
             WHERE extension_id = %',
            [
                $metadata['version'],
                $keyId,
                $manifest['archive']['sha256'],
                self::INSTALLED,
                $identifier,
            ],
        );
    }

    private function markFailed(string $identifier): void
    {
        try {
            $this->db->execute(
                'UPDATE extension_installations
                 SET status = %, updated = CURRENT_TIMESTAMP
                 WHERE extension_id = %',
                [self::FAILED, $identifier],
            );
        } catch (Throwable) {
            // Le nettoyage des fichiers reste prioritaire ; l'échec initial
            // remonte déjà avec son message.
        }
    }

    private function stage(): string
    {
        $root = $this->root();
        $parent = $this->privateDirectory($root . '/var', 0700);
        $parent = $this->privateDirectory($parent . '/extensions', 0700);
        $stage = $parent . '/.stage-' . bin2hex(random_bytes(16));
        $this->privateDirectory($stage, 0700);

        return $stage;
    }

    /** @param array<string, mixed> $verified */
    private function verifyArchive(string $archive, array $verified): void
    {
        clearstatcache(true, $archive);
        if (is_link($archive) || !is_file($archive)) {
            throw new RuntimeException('Archive téléchargée absente.');
        }
        if (filesize($archive) !== $verified['archive']['bytes']
            || !hash_equals($verified['archive']['sha256'], (string) hash_file('sha256', $archive))) {
            throw new RuntimeException('Taille ou empreinte de l’archive téléchargée invalide.');
        }

        // Recontrôle structurel et interne, sans exécuter le PHP du paquet.
        ExtensionPackage::inspectArchive($archive, $verified['manifest']);
    }

    private function extract(string $archive, string $destination): void
    {
        $zip = new ZipArchive();
        if (@$zip->open($archive, ZipArchive::RDONLY | ZipArchive::CHECKCONS) !== true) {
            throw new RuntimeException('Impossible d’ouvrir l’archive vérifiée.');
        }

        try {
            $this->privateDirectory($destination, 0755);
            for ($index = 0; $index < $zip->numFiles; ++$index) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    throw new RuntimeException('Entrée ZIP illisible.');
                }

                $name = $stat['name'];
                $path = rtrim($name, '/');
                ExtensionPackage::safePath($path);
                if (!str_starts_with($path, 'extension/') && !str_starts_with($path, 'assets/')) {
                    throw new RuntimeException('Racine ZIP inattendue pendant l’extraction.');
                }

                $target = $destination . '/' . $path;
                if (str_ends_with($name, '/')) {
                    $this->parents($destination, $path);
                    continue;
                }

                $this->parents($destination, dirname($path));
                $this->extractFile($zip, $name, $target, $stat);
            }
        } finally {
            $zip->close();
        }
    }

    /** Créer chaque parent dans l'ordre, même si le ZIP ne contient pas d'entrée de dossier. */
    private function parents(string $destination, string $relative): void
    {
        if ($relative === '.' || $relative === '') {
            return;
        }

        $current = $destination;
        foreach (explode('/', $relative) as $part) {
            if ($part === '') {
                continue;
            }
            $current .= '/' . $part;
            $this->privateDirectory($current, 0755);
        }
    }

    /** @param array<string, mixed> $stat */
    private function extractFile(ZipArchive $zip, string $name, string $target, array $stat): void
    {
        $input = @$zip->getStream($name);
        $output = @fopen($target, 'xb');
        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new RuntimeException('Impossible d’extraire un fichier de l’archive.');
        }

        $size = 0;
        $crc = hash_init('crc32b');
        try {
            if (!@chmod($target, 0644)) {
                throw new RuntimeException('Permissions d’extraction invalides.');
            }
            while (!feof($input)) {
                $chunk = @fread($input, 65536);
                if ($chunk === false || ($chunk === '' && !feof($input))) {
                    throw new RuntimeException('Lecture de l’archive interrompue.');
                }
                $size += strlen($chunk);
                if ($size > $stat['size'] || fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Extraction incomplète ou taille excessive.');
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

    /** @return Throwable|null */
    private function rollback(array $placed): ?Throwable
    {
        $error = null;
        foreach (array_reverse($placed) as $path) {
            try {
                $this->removeTree($path);
            } catch (Throwable $exception) {
                $error ??= $exception;
            }
        }

        return $error;
    }

    private function removeTree(string $path): void
    {
        // Supprimer un lien lui-même, sans jamais descendre dans sa cible.
        if (is_link($path)) {
            if (!@unlink($path)) {
                throw new RuntimeException('Impossible de supprimer un lien temporaire.');
            }

            return;
        }
        if (is_dir($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                $this->removeTree($entry->getPathname());
            }
            if (!@rmdir($path)) {
                throw new RuntimeException('Impossible de supprimer un dossier temporaire.');
            }

            return;
        }
        if (file_exists($path) && !@unlink($path)) {
            throw new RuntimeException('Impossible de supprimer un fichier temporaire.');
        }
    }

    private function removeTreeQuietly(string $path): void
    {
        try {
            $this->removeTree($path);
        } catch (Throwable) {
            // Le dossier privé peut rester après une coupure ; il ne contient
            // aucun fichier accessible publiquement.
        }
    }

    private function root(): string
    {
        $root = realpath(APP_ROOT);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Racine de l’installation inaccessible.');
        }

        return $root;
    }

    private function publicDirectory(string $path): string
    {
        return $this->directory($path, 0755);
    }

    private function privateDirectory(string $path, int $mode): string
    {
        return $this->directory($path, $mode);
    }

    private function directory(string $path, int $mode): string
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new RuntimeException('Dossier de destination invalide ou lien symbolique interdit.');
        }
        if (!is_dir($path) && !@mkdir($path, $mode)) {
            throw new RuntimeException('Dossier de destination introuvable.');
        }
        if (realpath($path) !== $path) {
            throw new RuntimeException('Dossier de destination redirigé par un lien symbolique.');
        }

        return $path;
    }
}
