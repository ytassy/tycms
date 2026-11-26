<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Helper;

use Throwable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Exception\PublicationException;
use YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogExtension;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogVersion;
use YTassy\Tycms\CoreExts\TyCatalog\Traits\TraitTyCatalogHelpers;
use YTassy\Tycms\Extensions\ExtensionPackage;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Tools\CriticalSection;
use ZipArchive;

/**
 * Dépôt, vérification et publication des versions.
 *
 * La clé privée des paquets n'entre jamais ici : seul le vérificateur public
 * est utilisé, avec la clé de confiance configurée pour le catalogue.
 */
final class TyCatalogPublicationHelper extends BaseHelper
{
    use TraitTyCatalogHelpers;

    private const MAX_ENVELOPE_BYTES = 131072;
    private const MAX_DESCRIPTION_LENGTH = 2000;

    /** @return array<string, mixed> */
    public function listVersions(Catalog $catalog): array
    {
        $rows = $this->db->select(
            'SELECT version.*, extension.extension_identifier, extension.type, extension.folder,
                    extension.display_name, extension.description, extension.image_url
             FROM ty_catalog_versions version
             INNER JOIN ty_catalog_extensions extension ON extension.id = version.extension_id
             WHERE extension.catalog_id = %',
            [$catalog->id],
        );

        $versions = array_map($this->versionView(...), $rows);
        usort($versions, static function (array $first, array $second): int {
            return $first['extension_identifier'] <=> $second['extension_identifier']
                ?: version_compare($second['version'], $first['version']);
        });

        return ['versions' => $versions];
    }

    /**
     * Déposer un paquet déjà présent dans un fichier temporaire privé.
     *
     * @param array<string, mixed> $archive
     * @param array<string, mixed> $notes
     * @param array<string, mixed> $signature
     * @return array{version_id:int}
     */
    public function depositLocal(
        Catalog $catalog,
        array $archive,
        array $notes,
        array $signature,
        ?string $displayName,
        ?string $description,
        ?int $userId,
    ): array {
        $this->assertRuntime();
        $envelope = $this->readUploaded($signature, self::MAX_ENVELOPE_BYTES, 'signature');
        $notesContents = $this->readUploaded($notes, ExtensionPackage::MAX_NOTES_BYTES, 'notes');
        $temporary = $this->tyCatalogStorage->receiveUploadedFile($archive, ExtensionPackage::MAX_ZIP_BYTES, 'zip');

        try {
            return $this->deposit(
                $catalog, $envelope, $notesContents, $temporary, true, null, null,
                $displayName, $description, $userId,
            );
        } finally {
            @unlink($temporary);
        }
    }

    /** @return array{version_id:int} */
    public function depositExternal(
        Catalog $catalog,
        string $archiveUrl,
        string $notesUrl,
        string $envelope,
        ?string $displayName,
        ?string $description,
        ?int $userId,
    ): array {
        $this->assertRuntime();
        if (strlen($envelope) > self::MAX_ENVELOPE_BYTES) {
            throw new PublicationException('L’enveloppe de signature est trop volumineuse.');
        }

        $temporary = $this->tyCatalogStorage->temporaryFile('zip');
        try {
            $this->tyCatalogDownload->downloadToFile($archiveUrl, $temporary, ExtensionPackage::MAX_ZIP_BYTES);
            $notes = $this->tyCatalogDownload->get($notesUrl, ExtensionPackage::MAX_NOTES_BYTES);

            return $this->deposit(
                $catalog, $envelope, $notes, $temporary, false, $archiveUrl, $notesUrl,
                $displayName, $description, $userId,
            );
        } finally {
            @unlink($temporary);
        }
    }

    /**
     * Publier un brouillon dans un canal de diffusion.
     *
     * Le canal est choisi ici et ne change plus : une version release ne
     * redevient jamais bêta. `promote()` est le seul passage autorisé, de la
     * bêta vers la release.
     */
    public function publish(Catalog $catalog, int $versionId, ?int $userId,
        string $channel = CatalogVersion::CHANNEL_RELEASE): void
    {
        if (!in_array($channel, CatalogVersion::CHANNELS, true)) {
            throw new PublicationException('Canal de diffusion invalide.');
        }
        $version = $this->versionForCatalog($catalog, $versionId);
        if ($version->status !== CatalogVersion::STATUS_DRAFT) {
            throw new PublicationException('Seule une version en brouillon peut être publiée.');
        }
        if ($version->archive_removed_at !== null) {
            throw new PublicationException('Le ZIP de cette version a été supprimé ; redéposez-la avant de la publier.');
        }

        // Un fichier local altéré ou disparu ne doit pas devenir public.
        if ($version->storage_type === CatalogVersion::STORAGE_LOCAL) {
            try {
                $this->verifyStoredVersion($catalog, $version);
            } catch (PublicationException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                $this->logException(new LogEventException(
                    'TY_CATALOG_PUBLISH_REFUSED',
                    'La publication d’une version locale a été refusée.',
                    'extensions',
                    ['catalog_id' => $catalog->id, 'version_id' => $versionId],
                    'WARNING',
                    $exception,
                ));
                throw new PublicationException('Le paquet conservé ne correspond plus au manifeste signé.');
            }
        }

        $this->db->execute(
            'UPDATE ty_catalog_versions
             SET status = %, channel = %, published_by = %, published_at = CURRENT_TIMESTAMP,
                 withdrawn_by = NULL, withdrawn_at = NULL, updated = CURRENT_TIMESTAMP
             WHERE id = %',
            [CatalogVersion::STATUS_PUBLISHED, $channel, $userId, $versionId],
        );

        $this->logInfo('Version d’extension publiée.',
            ['catalog_id' => $catalog->id, 'version_id' => $versionId, 'channel' => $channel],
            'TY_CATALOG_VERSION_PUBLISHED', 'extensions');
    }

    /**
     * Promouvoir une version bêta en release. Sens unique : le retour en bêta
     * est refusé, pour qu'un client ayant reçu la version ne la voie pas
     * changer de canal en arrière.
     */
    public function promote(Catalog $catalog, int $versionId, ?int $userId): void
    {
        $version = $this->versionForCatalog($catalog, $versionId);
        if ($version->status !== CatalogVersion::STATUS_PUBLISHED) {
            throw new PublicationException('Seule une version publiée peut être promue.');
        }
        if ($version->channel !== CatalogVersion::CHANNEL_BETA) {
            throw new PublicationException('Seule une version en bêta peut être promue en release.');
        }

        $this->db->execute(
            'UPDATE ty_catalog_versions
             SET channel = %, updated = CURRENT_TIMESTAMP
             WHERE id = %',
            [CatalogVersion::CHANNEL_RELEASE, $versionId],
        );

        $this->logInfo('Version d’extension promue en release.',
            ['catalog_id' => $catalog->id, 'version_id' => $versionId],
            'TY_CATALOG_VERSION_PROMOTED', 'extensions');
    }

    public function withdraw(Catalog $catalog, int $versionId, ?int $userId): void
    {
        $version = $this->versionForCatalog($catalog, $versionId);
        if ($version->status !== CatalogVersion::STATUS_PUBLISHED) {
            throw new PublicationException('Seule une version publiée peut être retirée.');
        }

        $this->db->execute(
            'UPDATE ty_catalog_versions
             SET status = %, withdrawn_by = %, withdrawn_at = CURRENT_TIMESTAMP, updated = CURRENT_TIMESTAMP
             WHERE id = %',
            [CatalogVersion::STATUS_WITHDRAWN, $userId, $versionId],
        );

        $this->logInfo('Version d’extension retirée.',
            ['catalog_id' => $catalog->id, 'version_id' => $versionId],
            'TY_CATALOG_VERSION_WITHDRAWN', 'extensions');
    }

    /**
     * Réactiver une version retirée uniquement si les octets restent identiques
     * au manifeste signé conservé.
     */
    public function reactivate(Catalog $catalog, int $versionId, ?int $userId): void
    {
        $version = $this->versionForCatalog($catalog, $versionId);
        if ($version->status !== CatalogVersion::STATUS_WITHDRAWN) {
            throw new PublicationException('Seule une version retirée peut être réactivée.');
        }
        if ($version->archive_removed_at !== null) {
            throw new PublicationException('Le ZIP de cette version a été supprimé ; elle ne peut pas être réactivée.');
        }

        try {
            $manifest = $this->verifyStoredVersion($catalog, $version);
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'TY_CATALOG_REACTIVATION_REFUSED',
                'La réactivation d’une version a été refusée.',
                'extensions',
                ['catalog_id' => $catalog->id, 'version_id' => $versionId],
                'WARNING',
                $exception,
            ));
            throw new PublicationException('La version ne peut pas être réactivée : ses octets ne correspondent plus au manifeste signé.');
        }

        // Le canal enregistré est conservé : réactiver une version retirée depuis
        // la bêta ne doit pas la publier pour tous sans décision.
        $this->db->execute(
            'UPDATE ty_catalog_versions
             SET status = %, published_by = %, published_at = CURRENT_TIMESTAMP,
                 withdrawn_by = NULL, withdrawn_at = NULL, updated = CURRENT_TIMESTAMP
             WHERE id = %',
            [CatalogVersion::STATUS_PUBLISHED, $userId, $versionId],
        );

        $this->logInfo('Version d’extension réactivée.',
            ['catalog_id' => $catalog->id, 'version_id' => $versionId,
                'channel' => $version->channel, 'bytes' => $manifest['archive']['bytes']],
            'TY_CATALOG_VERSION_REACTIVATED', 'extensions');
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function findOrCreateExtension(
        Catalog $catalog,
        array $metadata,
        ?string $displayName,
        ?string $description,
    ): CatalogExtension {        $existing = $this->db->selectOrmOneOrNull(
            CatalogExtension::class,
            'SELECT * FROM ty_catalog_extensions WHERE catalog_id = % AND extension_identifier = %',
            [$catalog->id, $metadata['id']],
        );

        $name = trim($displayName ?? '') ?: $metadata['folder'];
        $text = trim($description ?? '');
        if (mb_strlen($name) > 150 || mb_strlen($text) > self::MAX_DESCRIPTION_LENGTH) {
            throw new PublicationException('Le nom ou la description de l’extension est trop long.');
        }

        if ($existing !== null) {
            // L'identité technique est figée par le manifeste signé.
            if ($existing->type !== $metadata['type'] || $existing->folder !== $metadata['folder']) {
                throw new PublicationException('Le type ou le dossier de l’extension ne correspond pas à la fiche du catalogue.');
            }

            if ($displayName !== null || $description !== null) {
                $this->db->execute(
                    'UPDATE ty_catalog_extensions
                     SET display_name = %, description = %, updated = CURRENT_TIMESTAMP
                     WHERE id = %',
                    [$displayName === null ? $existing->display_name : $name,
                        $description === null ? $existing->description : $text, $existing->id],
                );
            }

            return $existing;
        }

        $this->db->execute(
            'INSERT INTO ty_catalog_extensions
                (catalog_id, extension_identifier, type, folder, display_name, description)
             VALUES (%, %, %, %, %, %)',
            [$catalog->id, $metadata['id'], $metadata['type'], $metadata['folder'], $name, $text],
        );

        return $this->db->selectOrmOneOrFail(
            CatalogExtension::class,
            'SELECT * FROM ty_catalog_extensions WHERE id = %',
            [$this->db->getLastInsertId()],
        );
    }

    /**
     * @return array{version_id:int}
     */
    private function deposit(
        Catalog $catalog,
        string $envelope,
        string $notes,
        string $zipPath,
        bool $storeArchive,
        ?string $archiveUrl,
        ?string $notesUrl,
        ?string $displayName,
        ?string $description,
        ?int $userId,
    ): array {
        if (!$catalog->key_id || !$catalog->public_key) {
            throw new PublicationException('Configurez la clé de confiance du catalogue avant de déposer une version.');
        }

        try {
            $manifest = ExtensionPackage::verify($envelope, [ExtensionPackage::keyId($catalog->key_id) => $catalog->public_key]);
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'TY_CATALOG_SIGNATURE_INVALID',
                'La signature d’un paquet déposé est invalide.',
                'extensions',
                ['catalog_id' => $catalog->id],
                'WARNING',
                $exception,
            ));
            throw new PublicationException('La signature du paquet est invalide ou ne correspond pas à la clé du catalogue.');
        }

        $metadata = $manifest['metadata'];
        if (strlen($notes) !== $manifest['release_notes']['bytes']
            || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notes))) {
            throw new PublicationException('Les notes de version ne correspondent pas au manifeste signé.');
        }

        try {
            ExtensionPackage::inspectArchive($zipPath, $manifest);
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'TY_CATALOG_ARCHIVE_INVALID',
                'Le ZIP d’un paquet déposé est invalide.',
                'extensions',
                ['catalog_id' => $catalog->id],
                'WARNING',
                $exception,
            ));
            throw new PublicationException('Le ZIP est invalide ou ne correspond pas au manifeste signé.');
        }

        $versionNumber = $metadata['version'];
        $lock = new CriticalSection('ty_catalog_publish');

        return $lock->execute(function () use (
            $catalog, $manifest, $metadata, $notes, $envelope, $zipPath, $storeArchive,
            $archiveUrl, $notesUrl, $displayName, $description, $userId, $versionNumber,
        ): array {
            $extension = $this->findOrCreateExtension($catalog, $metadata, $displayName, $description);
            $existing = $this->db->selectOrmOneOrNull(
                CatalogVersion::class,
                'SELECT * FROM ty_catalog_versions WHERE extension_id = % AND version = %',
                [$extension->id, $versionNumber],
            );

            if ($existing !== null && $existing->status !== CatalogVersion::STATUS_DRAFT) {
                throw new PublicationException('Cette version est déjà publiée ou retirée. Déposez une nouvelle version.');
            }

            $storageType = $storeArchive ? CatalogVersion::STORAGE_LOCAL : CatalogVersion::STORAGE_EXTERNAL;
            $directory = null;
            $reference = '';
            if ($storeArchive) {
                // Chaque dépôt possède son dossier : le brouillon remplacé ne
                // partage jamais les mêmes octets en cours d'écriture.
                $directory = $this->tyCatalogStorage->createVersionDirectory(
                    $catalog->id, $metadata['id'], $versionNumber,
                );
                $this->tyCatalogStorage->storeFile($zipPath, $directory['absolute'], 'archive.zip', ExtensionPackage::MAX_ZIP_BYTES);
                $this->tyCatalogStorage->storeContents($directory['absolute'], 'release_notes.md', $notes, ExtensionPackage::MAX_NOTES_BYTES);
                $this->tyCatalogStorage->storeContents($directory['absolute'], 'signature.json', $envelope, self::MAX_ENVELOPE_BYTES);
                $reference = $directory['relative'];
            } else {
                if (!is_string($archiveUrl) || !is_string($notesUrl)) {
                    throw new PublicationException('Les URL externes du paquet sont absentes.');
                }
                $reference = json_encode(
                    ['archive' => $archiveUrl, 'release_notes' => $notesUrl],
                    JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                );
            }

            $metadataJson = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $sha256 = $manifest['archive']['sha256'];
            $bytes = $manifest['archive']['bytes'];

            try {
                if ($existing === null) {
                    $this->db->execute(
                        'INSERT INTO ty_catalog_versions
                            (extension_id, version, status, signature_envelope, metadata, release_notes,
                             archive_bytes, archive_sha256, storage_type, storage_reference, created_by)
                         VALUES (%, %, %, %, %, %, %, %, %, %, %)',
                        [$extension->id, $versionNumber, CatalogVersion::STATUS_DRAFT, $envelope, $metadataJson,
                            $notes, $bytes, $sha256, $storageType, $reference, $userId],
                    );
                    $versionId = $this->db->getLastInsertId();
                } else {
                    $versionId = $existing->id;
                    $this->db->execute(
                        'UPDATE ty_catalog_versions
                         SET signature_envelope = %, metadata = %, release_notes = %, archive_bytes = %,
                             archive_sha256 = %, storage_type = %, storage_reference = %,
                             updated = CURRENT_TIMESTAMP
                         WHERE id = %',
                        [$envelope, $metadataJson, $notes, $bytes, $sha256, $storageType, $reference, $versionId],
                    );
                }
            } catch (Throwable $exception) {
                if ($directory !== null) {
                    try { $this->tyCatalogStorage->deleteDirectory($directory['relative']); }
                    catch (Throwable) { /* le dossier orphelin sera diagnostiqué plus tard */ }
                }
                throw $exception;
            }

            // L'ancien brouillon n'est supprimé qu'après la réussite SQL.
            if ($existing !== null && $existing->storage_type === CatalogVersion::STORAGE_LOCAL
                && $existing->storage_reference !== $reference) {
                try {
                    $this->tyCatalogStorage->deleteDirectory($existing->storage_reference);
                } catch (Throwable $exception) {
                    $this->logWarning('Le dossier de stockage du brouillon précédent n’a pas pu être supprimé.',
                        ['catalog_id' => $catalog->id, 'version_id' => $versionId],
                        'TY_CATALOG_DRAFT_CLEANUP_FAILED', 'extensions');
                }
            }

            $this->logInfo('Version d’extension déposée en brouillon.',
                ['catalog_id' => $catalog->id, 'version_id' => $versionId,
                    'extension' => $metadata['id'], 'version' => $versionNumber, 'storage' => $storageType],
                'TY_CATALOG_VERSION_DEPOSITED', 'extensions');

            return ['version_id' => $versionId];
        });
    }

    /**
     * Nettoyer les versions et archives au-delà des fenêtres du catalogue.
     * Protégé par un verrou par catalogue ; les erreurs de fichiers sont
     * rapportées sans annuler la publication déjà validée.
     *
     * @return array{versions_deleted:int,archives_deleted:int,errors:list<string>}
     */
    public function cleanupSafely(Catalog $catalog): array
    {
        try {
            return $this->cleanup($catalog);
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'TY_CATALOG_CLEANUP_FAILED',
                'Le nettoyage de rétention TyCatalog a échoué.',
                'extensions',
                ['catalog_id' => $catalog->id],
                'WARNING',
                $exception,
            ));
            return [
                'versions_deleted' => 0,
                'archives_deleted' => 0,
                'errors' => ['Nettoyage interrompu.'],
            ];
        }
    }

    /** @return array{versions_deleted:int,archives_deleted:int,errors:list<string>} */
    public function cleanup(Catalog $catalog): array
    {
        $lock = new CriticalSection('ty_catalog_cleanup_' . $catalog->id);

        return $lock->execute(function () use ($catalog): array {
            $versions = $this->db->selectOrm(
                CatalogVersion::class,
                'SELECT version.*
                 FROM ty_catalog_versions version
                 INNER JOIN ty_catalog_extensions extension ON extension.id = version.extension_id
                 WHERE extension.catalog_id = %',
                [$catalog->id],
            );

            $byExtension = [];
            foreach ($versions as $version) {
                $byExtension[$version->extension_id][] = $version;
            }

            $deleted = 0;
            $archives = 0;
            $errors = [];

            foreach ($byExtension as $group) {
                $byId = [];
                $entries = [];
                foreach ($group as $version) {
                    $byId[$version->id] = $version;
                    $entries[] = $this->retentionEntry($version);
                }

                $plan = TyCatalogRetention::plan(
                    $entries,
                    $catalog->keep_versions,
                    $catalog->keep_archives,
                );

                foreach ($plan['delete_version_ids'] as $versionId) {
                    if (!isset($byId[$versionId])) continue;
                    try {
                        $this->deleteVersion($byId[$versionId]);
                        $deleted++;
                    } catch (Throwable $exception) {
                        $errors[] = 'Version #' . $versionId . ' : suppression impossible.';
                        $this->logException(new LogEventException(
                            'TY_CATALOG_CLEANUP_VERSION_FAILED',
                            'La suppression d’une version ancienne a échoué.',
                            'extensions',
                            ['catalog_id' => $catalog->id, 'version_id' => $versionId],
                            'WARNING',
                            $exception,
                        ));
                    }
                }

                foreach ($plan['remove_archive_ids'] as $versionId) {
                    if (!isset($byId[$versionId])) continue;
                    try {
                        $this->removeArchive($byId[$versionId]);
                        $archives++;
                    } catch (Throwable $exception) {
                        $errors[] = 'Version #' . $versionId . ' : suppression du ZIP impossible.';
                        $this->logException(new LogEventException(
                            'TY_CATALOG_CLEANUP_ARCHIVE_FAILED',
                            'La suppression d’une archive ancienne a échoué.',
                            'extensions',
                            ['catalog_id' => $catalog->id, 'version_id' => $versionId],
                            'WARNING',
                            $exception,
                        ));
                    }
                }
            }

            $this->logInfo(
                'Nettoyage de rétention TyCatalog effectué.',
                ['catalog_id' => $catalog->id, 'versions_deleted' => $deleted,
                    'archives_deleted' => $archives, 'errors' => count($errors)],
                'TY_CATALOG_CLEANUP',
                'extensions',
            );

            return ['versions_deleted' => $deleted, 'archives_deleted' => $archives, 'errors' => $errors];
        });
    }

    /** @return array{id:int,status:string,last_activity:string,storage_type:string,archive_removed:bool} */
    private function retentionEntry(CatalogVersion $version): array
    {
        $lastActivity = $version->updated ?? $version->published_at ?? $version->created;

        return [
            'id' => $version->id,
            'status' => $version->status,
            'last_activity' => $lastActivity->format('Y-m-d H:i:s'),
            'storage_type' => $version->storage_type,
            'archive_removed' => $version->archive_removed_at !== null,
        ];
    }

    private function deleteVersion(CatalogVersion $version): void
    {
        if ($version->storage_type === CatalogVersion::STORAGE_LOCAL && $version->storage_reference !== '') {
            try {
                $this->tyCatalogStorage->deleteDirectory($version->storage_reference);
            } catch (Throwable $exception) {
                // La ligne doit tout de même disparaître ; le reliquat éventuel
                // est signalé et pourra être purgé par un nettoyage d'orphelins.
                $this->logWarning(
                    'Le dossier d’une version supprimée n’a pas pu être retiré.',
                    ['version_id' => $version->id],
                    'TY_CATALOG_CLEANUP_DIRECTORY_FAILED',
                    'extensions',
                );
            }
        }

        $this->db->execute('DELETE FROM ty_catalog_versions WHERE id = %', [$version->id]);
    }

    private function removeArchive(CatalogVersion $version): void
    {
        if ($version->storage_type !== CatalogVersion::STORAGE_LOCAL || $version->archive_removed_at !== null) {
            return;
        }

        if ($version->storage_reference !== '') {
            try {
                $this->tyCatalogStorage->deleteDirectory($version->storage_reference);
            } catch (Throwable $exception) {
                $this->logWarning(
                    'Le dossier d’une archive retirée n’a pas pu être supprimé.',
                    ['version_id' => $version->id],
                    'TY_CATALOG_CLEANUP_ARCHIVE_DIRECTORY_FAILED',
                    'extensions',
                );
            }
        }

        $this->db->execute(
            'UPDATE ty_catalog_versions
             SET archive_removed_at = CURRENT_TIMESTAMP, updated = CURRENT_TIMESTAMP
             WHERE id = %',
            [$version->id],
        );
    }

    private function verifyStoredVersion(Catalog $catalog, CatalogVersion $version): array
    {
        if (!$catalog->key_id || !$catalog->public_key) {
            throw new PublicationException('La clé de confiance du catalogue est absente.');
        }

        $manifest = ExtensionPackage::verify(
            $version->signature_envelope,
            [ExtensionPackage::keyId($catalog->key_id) => $catalog->public_key],
        );
        if (strlen($version->release_notes) !== $manifest['release_notes']['bytes']
            || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $version->release_notes))) {
            throw new PublicationException('Les notes conservées ne correspondent plus au manifeste.');
        }

        if ($version->storage_type === CatalogVersion::STORAGE_LOCAL) {
            $root = $this->tyCatalogStorage->root();
            $relative = trim($version->storage_reference, '/');
            $directory = realpath($root . '/' . $relative);
            if ($directory === false || !str_starts_with($directory, $root . '/') || !is_dir($directory)) {
                throw new PublicationException('Le dossier de stockage de la version est introuvable.');
            }

            $archive = $directory . '/archive.zip';
            if (!is_file($archive) || is_link($archive)
                || filesize($archive) !== $manifest['archive']['bytes']
                || !hash_equals($manifest['archive']['sha256'], hash_file('sha256', $archive))) {
                throw new PublicationException('Le ZIP conservé ne correspond plus au manifeste.');
            }
            ExtensionPackage::inspectArchive($archive, $manifest);
        } else {
            $reference = json_decode($version->storage_reference, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($reference) || !is_string($reference['archive'] ?? null) || !is_string($reference['release_notes'] ?? null)) {
                throw new PublicationException('La référence externe de la version est invalide.');
            }

            $temporary = $this->tyCatalogStorage->temporaryFile('zip');
            try {
                $this->tyCatalogDownload->downloadToFile($reference['archive'], $temporary, ExtensionPackage::MAX_ZIP_BYTES);
                ExtensionPackage::inspectArchive($temporary, $manifest);
                $notes = $this->tyCatalogDownload->get($reference['release_notes'], ExtensionPackage::MAX_NOTES_BYTES);
                if (strlen($notes) !== $manifest['release_notes']['bytes']
                    || !hash_equals($manifest['release_notes']['sha256'], hash('sha256', $notes))) {
                    throw new PublicationException('Les notes externes ne correspondent plus au manifeste.');
                }
            } finally {
                @unlink($temporary);
            }
        }

        return $manifest;
    }

    private function versionForCatalog(Catalog $catalog, int $versionId): CatalogVersion
    {
        $version = $this->db->selectOrmOneOrNull(
            CatalogVersion::class,
            'SELECT version.*
             FROM ty_catalog_versions version
             INNER JOIN ty_catalog_extensions extension ON extension.id = version.extension_id
             WHERE version.id = % AND extension.catalog_id = %',
            [$versionId, $catalog->id],
        );

        if ($version === null) {
            throw new PublicationException('Version introuvable dans ce catalogue.');
        }

        return $version;
    }

    /** @return array<string, mixed> */
    private function versionView(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'extension_identifier' => (string) $row->extension_identifier,
            'extension_name' => (string) $row->display_name,
            'extension_description' => (string) $row->description,
            'extension_image_url' => $row->image_url === null ? null : (string) $row->image_url,
            'type' => (string) $row->type,
            'folder' => (string) $row->folder,
            'version' => (string) $row->version,
            'status' => (string) $row->status,
            'channel' => (string) $row->channel,
            'archive_bytes' => (int) $row->archive_bytes,
            'archive_sha256' => (string) $row->archive_sha256,
            'storage_type' => (string) $row->storage_type,
            'created' => (string) $row->created,
            'published_at' => $row->published_at === null ? null : (string) $row->published_at,
            'withdrawn_at' => $row->withdrawn_at === null ? null : (string) $row->withdrawn_at,
            'archive_removed_at' => $row->archive_removed_at === null ? null : (string) $row->archive_removed_at,
        ];
    }

    /** @param array<string, mixed> $file */
    private function readUploaded(array $file, int $maxBytes, string $label): string
    {
        $error = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $tmpName = $file['tmp_name'] ?? null;
        $size = $file['size'] ?? null;
        if (!is_int($error) || $error !== UPLOAD_ERR_OK
            || !is_string($tmpName) || !is_file($tmpName) || !is_uploaded_file($tmpName)
            || !is_int($size) || $size < 1 || $size > $maxBytes) {
            throw new PublicationException('Le fichier ' . $label . ' est absent ou dépasse la limite autorisée.');
        }

        $contents = file_get_contents($tmpName);
        if ($contents === false) {
            throw new PublicationException('Impossible de lire le fichier ' . $label . '.');
        }

        return $contents;
    }

    private function assertRuntime(): void
    {
        if (!extension_loaded('sodium') || !class_exists(ZipArchive::class)) {
            throw new PublicationException('Les extensions PHP sodium et zip sont nécessaires pour vérifier un paquet.');
        }
    }
}
