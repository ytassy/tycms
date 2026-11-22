<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\ManifestVerifier;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

class WFSystemUpdate extends BaseWF
{
    use TraitAllHelpers;

    public function context(): array
    {
        $this->assertRoot();
        $error = null;
        $servers = [];
        try {
            $servers = $this->coreSystemUpdateHelper->configuration()['servers'];
        } catch (RuntimeException $exception) {
            // Cette erreur est renvoyée comme donnée d'écran et ne remonte pas au runner.
            $this->logException(new LogEventException('UPDATE_CONFIGURATION_INVALID',
                'La configuration des mises à jour est invalide.', 'system.update', level: 'ERROR', previous: $exception));
            $error = $exception->getMessage();
        }

        return ['installed_version' => $this->installedVersion(), 'php_version' => PHP_VERSION,
            'simulation_allowed' => $this->installedVersion() === 'dev',
            'cleanup_available' => $this->coreSystemUpdatePreparationHelper->cleanupAvailable(),
            'servers' => $servers, 'configuration_error' => $error,
            'download_error' => extension_loaded('zip') ? null : 'L’extension PHP zip est nécessaire pour télécharger et contrôler les paquets.'];
    }

    public function check(?string $sourceVersion = null): array
    {
        // ROOT porte sur l'espace racine, indépendamment du Space actuellement affiché.
        $this->assertRoot();
        $installed = $this->installedVersion();
        $simulation = $installed === 'dev';
        try {
            $source = $this->sourceVersion($sourceVersion);
            $result = $this->coreSystemUpdateHelper->check($source, PHP_VERSION, $simulation);
        } catch (RuntimeException $exception) {
            ExceptionResponse::throwDanger($exception->getMessage(), new LogEventException(
                'UPDATE_CHECK_FAILED', 'La recherche de mises à jour a échoué.', 'system.update', previous: $exception,
            ));
        }

        return $result + ['installed_version' => $installed, 'simulation' => $simulation];
    }

    public function download(string $version, string $sha256, ?string $sourceVersion = null): array
    {
        $this->assertRoot();

        // Tracer la demande autorisée, sans annoncer un succès avant la fin de la route.
        $this->logInfo('Téléchargement d’une mise à jour demandé.', [], 'UPDATE_DOWNLOAD_REQUESTED', 'system.update');

        try {
            $source = $this->sourceVersion($sourceVersion);
            $result = $this->coreSystemUpdatePackageHelper->download($source, PHP_VERSION, $version, $sha256, $this->installedVersion() === 'dev');
        } catch (RuntimeException $exception) {
            ExceptionResponse::throwDanger($exception->getMessage(), new LogEventException(
                'UPDATE_DOWNLOAD_FAILED', 'Le téléchargement de la mise à jour a échoué.', 'system.update', previous: $exception,
            ));
        }

        return $result + ['installed_version' => $this->installedVersion(), 'source_version' => $source,
            'simulation' => $this->installedVersion() === 'dev'];
    }

    public function prepare(string $version, string $sha256, ?string $sourceVersion = null): array
    {
        // Autoriser avant tout accès au cache, au moteur et au secret de l'opération.
        $this->assertRoot();
        $this->logInfo('Préparation d’une mise à jour demandée.', [], 'UPDATE_PREPARE_REQUESTED', 'system.update');

        try {
            $source = $this->sourceVersion($sourceVersion);
            return $this->coreSystemUpdatePreparationHelper->prepare($source, PHP_VERSION, $version, $sha256, $this->installedVersion() === 'dev');
        } catch (RuntimeException $exception) {
            ExceptionResponse::throwDanger($exception->getMessage(), new LogEventException(
                'UPDATE_PREPARE_FAILED', 'La préparation de la mise à jour a échoué.', 'system.update', previous: $exception,
            ));
        }
    }

    public function reset(): array
    {
        $this->assertRoot();
        $this->logInfo('Réinitialisation des fichiers de mise à jour demandée.', [], 'UPDATE_RESET_REQUESTED', 'system.update');

        try {
            return $this->coreSystemUpdatePreparationHelper->reset();
        } catch (RuntimeException $exception) {
            ExceptionResponse::throwDanger($exception->getMessage(), new LogEventException(
                'UPDATE_RESET_FAILED', 'La réinitialisation des fichiers de mise à jour a échoué.', 'system.update', previous: $exception,
            ));
        }
    }

    public function cleanup(): array
    {
        $this->assertRoot();
        $this->logInfo('Nettoyage des fichiers de mise à jour demandé.', [], 'UPDATE_CLEANUP_REQUESTED', 'system.update');

        try {
            return $this->coreSystemUpdatePreparationHelper->cleanup();
        } catch (RuntimeException $exception) {
            ExceptionResponse::throwDanger($exception->getMessage(), new LogEventException(
                'UPDATE_CLEANUP_FAILED', 'Le nettoyage des fichiers de mise à jour a échoué.', 'system.update', previous: $exception,
            ));
        }
    }

    private function sourceVersion(?string $sourceVersion): string
    {
        $installed = $this->installedVersion();
        if ($installed !== 'dev' && $sourceVersion !== null) {
            throw new RuntimeException('Une version source simulée est autorisée uniquement sur la release dev.');
        }

        return ManifestVerifier::version($installed === 'dev' ? $sourceVersion : $installed);
    }

    private function assertRoot(): void
    {
        if (!$this->coreAclHelper->isRoot()) {
            throw new AccessDeniedException();
        }
    }

    protected function installedVersion(): string
    {
        return APP_VERSION;
    }
}
