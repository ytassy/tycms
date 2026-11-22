<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Extension;

use RuntimeException;
use Throwable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Models\ExtensionInstallation;
use YTassy\Tycms\Extensions\ExtensionMetadata;
use YTassy\Tycms\Tools\CriticalSection;

/**
 * Désinstallation d’une extension suivie par un catalogue.
 *
 * L’extension exécute d’abord son hook de nettoyage (tables, données,
 * configuration, ACL). Le Core sauvegarde ensuite code et assets dans un
 * dossier privé, supprime les versions de migration et la provenance, puis
 * efface la sauvegarde. Un échec avant la suppression de la provenance
 * restaure les fichiers ; les changements SQL déjà exécutés ne sont pas
 * annulés.
 */
final class ExtensionUninstaller extends BaseHelper
{
    private const LOCK_NAME = 'extension_operation';
    private const IDENTIFIER_PATTERN = '/\A[a-z0-9_]{1,64}\z/';

    private ExtensionUpdateState $updateState {
        get { return $this->getSharedExtensionsClass(ExtensionUpdateState::class); }
    }

    /** @return array<string, mixed> */
    public function uninstall(string $identifier): array
    {
        if (preg_match(self::IDENTIFIER_PATTERN, $identifier) !== 1) {
            throw new RuntimeException('Identifiant d’extension invalide.');
        }

        $lock = new CriticalSection(self::LOCK_NAME);
        $backupDirectory = null;
        $backupExtension = null;
        $backupAssets = null;

        try {
            return $lock->execute(function () use (
                $identifier,
                &$backupDirectory,
                &$backupExtension,
                &$backupAssets,
            ): array {
                $extension = $this->app->getExtensions()[$identifier] ?? null;
                if ($extension === null) {
                    throw new RuntimeException('Cette extension n’est pas chargée.');
                }
                if ($this->app->isCoreExtension($identifier)) {
                    throw new RuntimeException('Une extension Core ne peut pas être désinstallée.');
                }

                $installation = $this->installation($identifier);
                if ($installation === null || $installation->status !== 'installed') {
                    throw new RuntimeException('Cette extension n’est pas une installation catalogue active.');
                }
                if ($this->extensionVersion($extension) !== $installation->version) {
                    throw new RuntimeException('La version locale ne correspond pas à la provenance enregistrée.');
                }
                if ($this->updateState->pending($identifier) !== null) {
                    throw new RuntimeException('Une mise à jour doit être finalisée ou annulée avant la désinstallation.');
                }

                $extensionTarget = $this->extensionTarget($installation->type, $installation->folder);
                $assetsTarget = $this->assetsTarget($identifier);
                if (is_link($extensionTarget) || !is_dir($extensionTarget)) {
                    throw new RuntimeException('Le dossier de l’extension est introuvable ou invalide.');
                }

                // Le hook utilise encore le code de l’extension en place.
                try {
                    $extension->uninstallExtension();
                } catch (Throwable $exception) {
                    throw new RuntimeException(
                        'Le nettoyage spécifique de l’extension a échoué : ' . $exception->getMessage(),
                        0,
                        $exception,
                    );
                }

                $backupDirectory = $this->privateDirectory(
                    $this->updateState->directory() . '/.uninstall-' . bin2hex(random_bytes(16)),
                    0700,
                );
                $backupExtension = $backupDirectory . '/extension';
                if (!@rename($extensionTarget, $backupExtension)) {
                    throw new RuntimeException('Impossible de retirer le dossier de l’extension.');
                }

                if (is_dir($assetsTarget)) {
                    if (is_link($assetsTarget)) {
                        throw new RuntimeException('Le dossier des assets de l’extension est invalide.');
                    }
                    $backupAssets = $backupDirectory . '/assets';
                    if (!@rename($assetsTarget, $backupAssets)) {
                        throw new RuntimeException('Impossible de retirer les assets de l’extension.');
                    }
                }

                // Supprimer la provenance et l’historique de migration permet
                // une réinstallation propre, qui rejouera les migrations.
                $this->db->execute('START TRANSACTION');
                try {
                    $this->db->execute(
                        'DELETE FROM migrations WHERE extension = %',
                        [strtolower($identifier)],
                    );
                    $this->db->execute(
                        'DELETE FROM extension_installations WHERE extension_id = %',
                        [$identifier],
                    );
                    $this->db->execute('COMMIT');
                } catch (Throwable $exception) {
                    $this->db->execute('ROLLBACK');
                    throw $exception;
                }

                $this->removeTreeQuietly($backupDirectory);

                return [
                    'identifier' => $identifier,
                    'version' => $installation->version,
                    'status' => 'uninstalled',
                ];
            }, false);
        } catch (Throwable $exception) {
            // Restaurer les fichiers si la provenance n'a pas encore été retirée.
            $this->restore($backupExtension, $backupAssets, $identifier);
            if ($backupDirectory !== null) {
                $this->removeTreeQuietly($backupDirectory);
            }

            throw $exception;
        }
    }

    private function restore(?string $backupExtension, ?string $backupAssets, string $identifier): void
    {
        $installation = $this->installation($identifier);

        // Si la provenance a été supprimée, la désinstallation est allée au
        // bout : ne pas remettre les fichiers.
        if ($installation === null) {
            return;
        }

        $extensionTarget = $this->extensionTarget($installation->type, $installation->folder);
        $assetsTarget = $this->assetsTarget($identifier);

        if ($backupAssets !== null && is_dir($backupAssets) && !is_link($backupAssets)) {
            if (!@rename($backupAssets, $assetsTarget)) {
                throw new RuntimeException('Impossible de restaurer les assets de l’extension.');
            }
        }
        if ($backupExtension !== null && is_dir($backupExtension) && !is_link($backupExtension)) {
            if (!@rename($backupExtension, $extensionTarget)) {
                throw new RuntimeException('Impossible de restaurer le dossier de l’extension.');
            }
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
                'La table des installations est indisponible. Lancez les migrations Core 1.1.5.',
                0,
                $exception,
            );
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

    private function extensionTarget(string $type, string $folder): string
    {
        if (!in_array($type, ['plugin', 'module', 'template'], true)
            || !preg_match('/\A[A-Z][A-Za-z0-9]{0,63}\z/', $folder)) {
            throw new RuntimeException('Type ou dossier d’extension invalide.');
        }

        return $this->root() . '/extensions/' . ExtensionMetadata::relativePath([
            'type' => $type,
            'folder' => $folder,
        ]);
    }

    private function assetsTarget(string $identifier): string
    {
        return $this->root() . '/public/assets/' . $identifier;
    }

    private function root(): string
    {
        $root = realpath(APP_ROOT);
        if ($root === false || !is_dir($root)) {
            throw new RuntimeException('Racine de l’installation inaccessible.');
        }

        return $root;
    }

    private function privateDirectory(string $path, int $mode): string
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            throw new RuntimeException('Dossier privé de désinstallation invalide.');
        }
        if (!is_dir($path) && !@mkdir($path, $mode)) {
            throw new RuntimeException('Impossible de créer le dossier privé de désinstallation.');
        }
        if (realpath($path) !== $path) {
            throw new RuntimeException('Dossier privé de désinstallation redirigé par un lien symbolique.');
        }

        return $path;
    }

    private function removeTreeQuietly(string $path): void
    {
        try {
            $this->removeTree($path);
        } catch (Throwable) {
            // Le dossier privé peut rester ; il n'est pas accessible publiquement.
        }
    }

    private function removeTree(string $path): void
    {
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
}
