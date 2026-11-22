<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Ai;

use YTassy\Tycms\CoreExts\Core\Models\AiProvider;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class AiProviderHelper extends BaseHelper
{
    /** @return list<AiProvider> */
    public function listForSpace(int $spaceId): array
    {
        return $this->db->selectOrm(AiProvider::class,
            'SELECT * FROM ai_providers WHERE space_id = % ORDER BY name, id', [$spaceId]);
    }

    public function get(int $id): ?AiProvider
    {
        return $this->db->selectOrmOneOrNull(AiProvider::class,
            'SELECT * FROM ai_providers WHERE id = %', [$id]);
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $this->db->execute(
            'INSERT INTO ai_providers (space_id, name, type, base_url, enabled, config, secret_data) VALUES (%, %, %, %, %, %, %)',
            [$data['space_id'], $data['name'], $data['type'], $data['base_url'], $data['enabled'], $data['config'], $data['secret_data']]
        );
        return $this->db->getLastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE ai_providers SET name = %, base_url = %, enabled = %, config = %, secret_data = %, updated = CURRENT_TIMESTAMP WHERE id = %',
            [$data['name'], $data['base_url'], $data['enabled'], $data['config'], $data['secret_data'], $id]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM ai_providers WHERE id = %', [$id]);
    }

    /** @param array<string,mixed> $params */
    public function saveSpaceModels(int $spaceId, array $params): void
    {
        $this->db->execute('UPDATE spaces SET params = % WHERE id = %',
            [json_encode($params, JSON_THROW_ON_ERROR), $spaceId]);
    }
}
