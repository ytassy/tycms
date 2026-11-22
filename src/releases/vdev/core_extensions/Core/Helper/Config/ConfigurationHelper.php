<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Config;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;

/** Règles de configuration propres au plugin Core, hors du workflow générique. */
final class ConfigurationHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    private const HLS_FIELDS = ['video_hls_libraries', 'video_hls_password', 'video_hls_password_encrypted'];

    public function hiddenSections(int $spaceId): array
    {
        return $this->canManageHls($spaceId) ? [] : ['hls'];
    }

    public function assertSubmissionAllowed(int $spaceId, object $params): void
    {
        if ($this->canManageHls($spaceId)) return;

        // Le formulaire masqué côté navigateur ne suffit pas à protéger l'API.
        foreach (self::HLS_FIELDS as $field) {
            if (property_exists($params, $field)) throw new AccessDeniedException();
        }
    }

    public function prepareForStorage(int $spaceId, array $params): array
    {
        $params = $this->coreShortCodeHelper->prepareConfiguration($spaceId, $params);
        if ($this->canManageHls($spaceId)) {
            return $this->coreSecureVideoHelper->prepareConfiguration($spaceId, $params);
        }

        // Une modification des autres réglages conserve les champs HLS existants.
        foreach (self::HLS_FIELDS as $field) unset($params[$field]);
        $existing = $this->coreExtensionsHelper->getExtensionsConfForSpaces([$spaceId])['plg_core'][$spaceId] ?? null;
        $stored = (array) ($existing?->params ?? []);
        foreach (self::HLS_FIELDS as $field) {
            if (array_key_exists($field, $stored)) $params[$field] = $stored[$field];
        }

        return $params;
    }

    public function prepareForEditor(int $spaceId, array $params): array
    {
        $params = $this->coreShortCodeHelper->configurationForEditor($spaceId, $params);
        if ($this->canManageHls($spaceId)) {
            return $this->coreSecureVideoHelper->configurationForEditor($spaceId, $params);
        }

        foreach (self::HLS_FIELDS as $field) unset($params[$field]);
        return $params;
    }

    private function canManageHls(int $spaceId): bool
    {
        return $this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_ADMIN, $spaceId);
    }
}
