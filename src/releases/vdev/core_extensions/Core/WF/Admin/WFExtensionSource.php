<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF\Admin;

use Throwable;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\CatalogSourceUrl;
use YTassy\Tycms\CoreExts\Core\Helper\Extension\OfficialCatalog;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/** Sources de catalogues externes du client Core ; réservé à ROOT. */
final class WFExtensionSource extends BaseWF
{
    use TraitAllHelpers;

    /** @return array<string, mixed> */
    public function list(): array
    {
        $this->assertRoot();

        return [
            'sources' => array_map(
                fn ($source): array => $this->coreCatalogSourceHelper->view($source),
                $this->coreCatalogSourceHelper->listSources(),
            ),
            'official_url' => OfficialCatalog::URL,
            'official_public_key' => OfficialCatalog::PUBLIC_KEY,
        ];
    }

    /** @return array{source:array<string,mixed>} */
    public function save(object $data): array
    {
        $this->assertRoot();

        $id = isset($data->id) && $data->id !== null ? (int) $data->id : null;
        $name = is_string($data->name ?? null) ? trim($data->name) : '';
        if ($name === '' || mb_strlen($name) > 150) {
            ExceptionResponse::throwWarning('Le nom de la source est obligatoire, avec 150 caractères maximum.');
        }

        $baseUrl = CatalogSourceUrl::normalize(
            is_string($data->baseUrl ?? null) ? $data->baseUrl : '',
            (bool) $this->app->getConf()->dev,
        );

        $keyId = is_string($data->keyId ?? null) ? trim($data->keyId) : '';
        if (!preg_match('/\Aextensions-[a-zA-Z0-9_-]{1,53}\z/', $keyId)) {
            ExceptionResponse::throwWarning('L’identifiant de clé doit commencer par « extensions- ».');
        }

        $publicKey = is_string($data->publicKey ?? null) ? trim($data->publicKey) : '';
        $decoded = base64_decode($publicKey, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || base64_encode($decoded) !== $publicKey) {
            ExceptionResponse::throwWarning('La clé publique Ed25519 doit être encodée en base64.');
        }

        $license = is_string($data->license ?? null) ? trim($data->license) : null;
        if ($license === '') {
            $license = null;
        }
        if ($license !== null && (strlen($license) > 1000 || preg_match('/[\r\n]/', $license) === 1)) {
            ExceptionResponse::throwWarning('La licence est invalide ou trop longue.');
        }

        $clearLicense = (bool) ($data->clearLicense ?? false);
        if ($license !== null) {
            // Une nouvelle valeur remplace toujours l'ancienne ; elle ne la supprime pas.
            $clearLicense = false;
        }

        if ($this->coreCatalogSourceHelper->baseUrlExists($baseUrl, $id)) {
            ExceptionResponse::throwWarning('Cette URL de catalogue est déjà enregistrée.');
        }

        $source = $this->coreCatalogSourceHelper->save($id, [
            'name' => $name,
            'base_url' => $baseUrl,
            'key_id' => $keyId,
            'public_key' => $publicKey,
            'enabled' => (bool) ($data->enabled ?? true),
            'license' => $license,
            'clear_license' => $clearLicense,
        ], $this->session->getUserId());

        // Le journal ne contient ni URL, ni clé publique, ni licence.
        $this->logInfo(
            $id === null ? 'Source de catalogue créée.' : 'Source de catalogue modifiée.',
            ['source_id' => $source->id, 'enabled' => $source->enabled],
            'EXTENSION_SOURCE_SAVED',
            'extensions',
        );

        return ['source' => $this->coreCatalogSourceHelper->view($source)];
    }

    /** @return array<string, mixed> */
    public function consult(object $data): array
    {
        $this->assertRoot();

        $sourceId = (int) ($data->id ?? 0);
        $source = $this->coreCatalogSourceHelper->get($sourceId);
        if ($source === null) {
            ExceptionResponse::throwWarning('Source de catalogue introuvable.');
        }
        if (!$source->enabled) {
            ExceptionResponse::throwWarning('Cette source de catalogue est désactivée.');
        }

        try {
            $result = $this->coreCatalogSourceClient->consult($source, 1, 100);
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'EXTENSION_SOURCE_CONSULT_FAILED',
                'La consultation d’une source de catalogue a échoué.',
                'extensions',
                ['source_id' => $sourceId],
                'WARNING',
                $exception,
            ));
            ExceptionResponse::throwWarning('La consultation du catalogue a échoué : ' . $exception->getMessage());
        }

        $this->logInfo(
            'Source de catalogue consultée.',
            ['source_id' => $sourceId, 'extensions' => count($result['extensions'] ?? [])],
            'EXTENSION_SOURCE_CONSULTED',
            'extensions',
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function install(object $data): array
    {
        $this->assertRoot();

        $sourceId = (int) ($data->sourceId ?? 0);
        $source = $this->coreCatalogSourceHelper->get($sourceId);
        if ($source === null) {
            ExceptionResponse::throwWarning('Source de catalogue introuvable.');
        }
        if (!$source->enabled) {
            ExceptionResponse::throwWarning('Cette source de catalogue est désactivée.');
        }

        $identifier = is_string($data->identifier ?? null) ? $data->identifier : '';
        $version = is_string($data->version ?? null) ? $data->version : '';

        try {
            $result = $this->coreExtensionInstaller->install(
                $source,
                $identifier,
                $version,
                $this->session->getUserId(),
            );
        } catch (Throwable $exception) {
            // Le journal ne contient ni URL de source, ni licence, ni chemin local.
            $this->logException(new LogEventException(
                'EXTENSION_INSTALL_FAILED',
                'L’installation d’une extension depuis un catalogue a échoué.',
                'extensions',
                ['source_id' => $sourceId, 'extension_id' => $identifier],
                'ERROR',
                $exception,
            ));
            ExceptionResponse::throwWarning('L’installation a échoué : ' . $exception->getMessage());
        }

        $this->logInfo(
            'Extension installée depuis un catalogue.',
            [
                'source_id' => $sourceId,
                'extension_id' => $identifier,
                'version' => $result['version'] ?? $version,
            ],
            'EXTENSION_INSTALLED',
            'extensions',
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function update(object $data): array
    {
        $this->assertRoot();

        $sourceId = (int) ($data->sourceId ?? 0);
        $source = $this->coreCatalogSourceHelper->get($sourceId);
        if ($source === null) {
            ExceptionResponse::throwWarning('Source de catalogue introuvable.');
        }
        if (!$source->enabled) {
            ExceptionResponse::throwWarning('Cette source de catalogue est désactivée.');
        }

        $identifier = is_string($data->identifier ?? null) ? $data->identifier : '';
        $version = is_string($data->version ?? null) ? $data->version : '';

        try {
            $result = $this->coreExtensionInstaller->prepareUpdate(
                $source,
                $identifier,
                $version,
            );
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'EXTENSION_UPDATE_FAILED',
                'La préparation d’une mise à jour d’extension a échoué.',
                'extensions',
                ['source_id' => $sourceId, 'extension_id' => $identifier],
                'ERROR',
                $exception,
            ));
            ExceptionResponse::throwWarning('La préparation de la mise à jour a échoué : ' . $exception->getMessage());
        }

        $this->logInfo(
            'Mise à jour d’extension préparée.',
            [
                'source_id' => $sourceId,
                'extension_id' => $identifier,
                'from_version' => $result['from_version'] ?? null,
                'to_version' => $result['to_version'] ?? $version,
            ],
            'EXTENSION_UPDATE_PREPARED',
            'extensions',
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function finalizeUpdate(object $data): array
    {
        $this->assertRoot();

        $identifier = is_string($data->identifier ?? null) ? $data->identifier : '';
        $operationId = is_string($data->operationId ?? null) ? $data->operationId : '';

        try {
            $result = $this->coreExtensionInstaller->finalizeUpdate($identifier, $operationId);
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'EXTENSION_UPDATE_FAILED',
                'La finalisation d’une mise à jour d’extension a échoué.',
                'extensions',
                ['extension_id' => $identifier],
                'ERROR',
                $exception,
            ));
            ExceptionResponse::throwWarning('La finalisation de la mise à jour a échoué : ' . $exception->getMessage());
        }

        $this->logInfo(
            'Extension mise à jour depuis un catalogue.',
            ['extension_id' => $identifier, 'version' => $result['version'] ?? null],
            'EXTENSION_UPDATED',
            'extensions',
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function rollbackUpdate(object $data): array
    {
        $this->assertRoot();

        $identifier = is_string($data->identifier ?? null) ? $data->identifier : '';
        $operationId = is_string($data->operationId ?? null) ? $data->operationId : '';

        try {
            $result = $this->coreExtensionInstaller->rollbackUpdate($identifier, $operationId);
        } catch (Throwable $exception) {
            $this->logException(new LogEventException(
                'EXTENSION_UPDATE_FAILED',
                'La restauration d’une mise à jour d’extension a échoué.',
                'extensions',
                ['extension_id' => $identifier],
                'ERROR',
                $exception,
            ));
            ExceptionResponse::throwWarning('La restauration de l’ancienne version a échoué : ' . $exception->getMessage());
        }

        $this->logInfo(
            'Mise à jour d’extension annulée.',
            ['extension_id' => $identifier, 'version' => $result['version'] ?? null],
            'EXTENSION_UPDATE_ROLLED_BACK',
            'extensions',
        );

        return $result;
    }

    private function assertRoot(): void
    {
        if (!$this->coreAclHelper->isRoot()) {
            throw new AccessDeniedException();
        }
    }
}
