<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Extension;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Models\CatalogSource;

/** Sources de catalogues du client Core, avec licence chiffrée. */
final class CatalogSourceHelper extends BaseHelper
{
    private const LICENSE_PURPOSE = 'extension-catalog-license:v1';

    private CryptHelper $crypt {
        get { return $this->getSharedExtensionsClass(CryptHelper::class); }
    }

    /** @return list<CatalogSource> */
    public function listSources(): array
    {
        return $this->db->selectOrm(
            CatalogSource::class,
            'SELECT * FROM extension_catalog_sources ORDER BY name, id',
        );
    }

    public function get(int $id): ?CatalogSource
    {
        return $this->db->selectOrmOneOrNull(
            CatalogSource::class,
            'SELECT * FROM extension_catalog_sources WHERE id = %',
            [$id],
        );
    }

    public function baseUrlExists(string $baseUrl, ?int $exceptId = null): bool
    {
        $existing = $this->db->selectOneOrNull(
            'SELECT id FROM extension_catalog_sources WHERE base_url = % LIMIT 1',
            [$baseUrl],
        );

        return $existing !== null && (int) $existing->id !== $exceptId;
    }

    /**
     * @param array{name:string,base_url:string,key_id:string,public_key:string,
     *     enabled:bool,license:?string,clear_license:bool} $data
     */
    public function save(?int $id, array $data, ?int $userId): CatalogSource
    {
        $existing = $id !== null ? $this->get($id) : null;
        if ($id !== null && $existing === null) {
            throw new RuntimeException('Source de catalogue introuvable.');
        }

        $licenseEncrypted = $existing?->license_encrypted;
        if ($data['clear_license']) {
            $licenseEncrypted = null;
        } elseif ($data['license'] !== null) {
            $licenseEncrypted = $this->crypt->encrypt($data['license'], self::LICENSE_PURPOSE);
        }

        if ($existing === null) {
            $this->db->execute(
                'INSERT INTO extension_catalog_sources
                    (name, base_url, key_id, public_key, license_encrypted, enabled, created_by)
                 VALUES (%, %, %, %, %, %, %)',
                [$data['name'], $data['base_url'], $data['key_id'], $data['public_key'],
                    $licenseEncrypted, $data['enabled'], $userId],
            );
            $id = $this->db->getLastInsertId();
        } else {
            $this->db->execute(
                'UPDATE extension_catalog_sources
                 SET name = %, base_url = %, key_id = %, public_key = %, license_encrypted = %,
                     enabled = %, updated = CURRENT_TIMESTAMP
                 WHERE id = %',
                [$data['name'], $data['base_url'], $data['key_id'], $data['public_key'],
                    $licenseEncrypted, $data['enabled'], $id],
            );
        }

        $saved = $this->get((int) $id);
        if ($saved === null) {
            throw new RuntimeException('La source enregistrée est introuvable.');
        }

        return $saved;
    }

    public function license(CatalogSource $source): ?string
    {
        if ($source->license_encrypted === null || $source->license_encrypted === '') {
            return null;
        }

        return $this->crypt->decrypt($source->license_encrypted, self::LICENSE_PURPOSE);
    }

    /** @return array<string, mixed> */
    public function view(CatalogSource $source): array
    {
        return [
            'id' => $source->id,
            'name' => $source->name,
            'base_url' => $source->base_url,
            'key_id' => $source->key_id,
            'public_key' => $source->public_key,
            'enabled' => $source->enabled,
            'has_license' => $source->license_encrypted !== null && $source->license_encrypted !== '',
            'created' => $source->created->format(DATE_ATOM),
            'updated' => $source->updated?->format(DATE_ATOM),
        ];
    }
}
