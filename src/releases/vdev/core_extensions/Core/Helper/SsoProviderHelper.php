<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\DB\FormatIn;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Sso\SsoProviderSettings;

final class SsoProviderHelper extends BaseHelper
{
    /** @param list<int> $spaceIds @return list<SsoProvider> */
    public function getProvidersOwnedBySpaces(array $spaceIds): array
    {
        if ($spaceIds === []) return [];
        return $this->db->selectOrm(SsoProvider::class,
            'SELECT * FROM sso_providers WHERE space_id IN (%) ORDER BY name, id', [FormatIn::get($spaceIds)]);
    }

    public function getProvider(int $providerId): ?SsoProvider
    {
        return $this->db->selectOrmOneOrNull(SsoProvider::class,
            'SELECT * FROM sso_providers WHERE id = %', [$providerId]);
    }

    /** @param array<string,mixed> $data */
    public function createProvider(array $data): int
    {
        $this->db->execute(
            'INSERT INTO sso_providers (space_id, name, enabled, protocol, login_enabled, integration_enabled, auto_link_account, config, secret_data, mapping, login_button_text, register_button_text) VALUES (%, %, %, %, %, %, %, %, %, %, %, %)',
            [$data['space_id'], $data['name'], $data['enabled'], $data['protocol'], $data['login_enabled'], $data['integration_enabled'], $data['auto_link_account'], $data['config'], $data['secret_data'], $data['mapping'], $data['login_button_text'], $data['register_button_text']]
        );
        return $this->db->getLastInsertId();
    }

    /** @param array<string,mixed> $data */
    public function updateProvider(int $providerId, array $data): void
    {
        $this->db->execute(
            'UPDATE sso_providers SET name = %, enabled = %, login_enabled = %, integration_enabled = %, auto_link_account = %, config = %, secret_data = %, mapping = %, login_button_text = %, register_button_text = %, updated = CURRENT_TIMESTAMP WHERE id = %',
            [$data['name'], $data['enabled'], $data['login_enabled'], $data['integration_enabled'], $data['auto_link_account'], $data['config'], $data['secret_data'], $data['mapping'], $data['login_button_text'], $data['register_button_text'], $providerId]
        );
    }

    /** @return list<SsoProvider> */
    public function getProvidersForSpace(Space $space): array
    {
        $spaceIds = $this->coreSpaceHelper->getSpaceIdsWithParents($space->id);
        if ($spaceIds === []) return [];

        return $this->getProvidersOwnedBySpaces($spaceIds);
    }

    public function isLoginAvailable(SsoProvider $provider): bool
    {
        if ((int) $provider->enabled !== 1 || (int) $provider->login_enabled !== 1
            || $provider->protocol !== 'oidc'
            || (int) $provider->secret_key_version !== 1
            || !is_string($provider->secret_data) || $provider->secret_data === '') return false;
        $config = json_decode($provider->config, true);
        if (!is_array($config)) return false;
        $mapping = json_decode($provider->mapping, true);
        if (!is_array($mapping)) return false;
        try {
            if (($config['driver'] ?? null) === 'google') {
                SsoProviderSettings::assertActiveGoogle($config, $mapping);
            } else {
                SsoProviderSettings::assertActiveGeneric($config, 'oidc');
                SsoProviderSettings::assertOidcMapping($mapping);
            }
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    /** @return list<SsoProvider> */
    public function getLoginProviders(Space $space): array
    {
        $params = is_object($space->params) ? (array) $space->params : $space->params;
        $ids = is_array($params) ? ($params['sso_provider_ids'] ?? []) : [];
        if (!is_array($ids)) return [];

        $allowedSpaces = $this->coreSpaceHelper->getSpaceIdsWithParents($space->id);
        if ($allowedSpaces === []) return [];
        $selectedIds = [];
        foreach ($ids as $id) {
            if (is_int($id) && $id > 0) $selectedIds[$id] = $id;
        }
        if ($selectedIds === []) return [];

        $availableById = [];
        foreach ($this->db->selectOrm(SsoProvider::class,
            'SELECT * FROM sso_providers WHERE id IN (%) AND space_id IN (%)',
            [FormatIn::get(array_values($selectedIds)), FormatIn::get($allowedSpaces)]) as $provider) {
            if ($this->isLoginAvailable($provider)) $availableById[$provider->id] = $provider;
        }
        $providers = [];
        foreach ($selectedIds as $id) {
            if (isset($availableById[$id])) $providers[] = $availableById[$id];
        }
        return $providers;
    }

    public function getLoginProvider(Space $space, int $providerId): ?SsoProvider
    {
        if ($providerId < 1) return null;
        $params = is_object($space->params) ? (array) $space->params : $space->params;
        $ids = is_array($params) ? ($params['sso_provider_ids'] ?? []) : [];
        if (!is_array($ids) || !in_array($providerId, $ids, true)) return null;

        $provider = $this->getProvider($providerId);
        if ($provider === null || !in_array($provider->space_id,
            $this->coreSpaceHelper->getSpaceIdsWithParents($space->id), true)) return null;
        return $this->isLoginAvailable($provider) ? $provider : null;
    }

    public function getIntegrationProvider(Space $space, int $providerId): ?SsoProvider
    {
        $provider = $this->getProvider($providerId);
        return $provider !== null && $this->isIntegrationAvailable($provider)
            && in_array($provider->space_id, $this->coreSpaceHelper->getSpaceIdsWithParents($space->id), true)
            ? $provider : null;
    }

    public function isIntegrationAvailable(SsoProvider $provider): bool
    {
        if ($provider->protocol !== 'oauth2' || !$provider->enabled || !$provider->integration_enabled
            || (int) $provider->secret_key_version !== 1) return false;
        $config = json_decode($provider->config, true);
        if (!is_array($config)) return false;
        try {
            SsoProviderSettings::assertActiveGeneric($config, 'oauth2');
            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
