<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use Throwable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Models\Space;

/** Références et configurations publiées ; les décisions d'autorisation restent dans le WF. */
class AuthenticationHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public function pageUrl(Space $space, string $kind, ?string $locale = null): ?string
    {
        $id = $kind === 'login' ? $space->menu_item_login_id : $space->menu_item_register_id;
        if ($id === null) return null;
        if ($locale === null) {
            $locale = $this->corePublicLocaleHelper->locale();
            if (!in_array($locale, array_column($this->coreSpaceLanguageHelper->listActiveForSpace($space->id), 'locale'), true)) {
                $locale = $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);
            }
        }
        return $this->coreMenuRouteHelper->getUrlForContext($id, $space->id, $locale);
    }

    public function registrationEnabled(Space $space): bool
    {
        // Le lien de navigation est facultatif pour les composants placés dans les pages.
        return in_array(((array) $space->params)['registration_enabled'] ?? false, [true, 1], true);
    }

    /** Relire la page source et ses options ; les liens du Space ne limitent pas le placement. */
    public function configuration(Space $space, string $kind, string $nodeId, string $locale, int $pageId): ?array
    {
        $page = $this->corePageHelper->getPage((int) $pageId);
        if ($page === null || $page->space_id !== $space->id || $page->status !== 1
            || $page->type !== 2 || ($page->data->version ?? null) !== PageDocument::VERSION) return null;

        if (!in_array($locale, array_column($this->coreSpaceLanguageHelper->listActiveForSpace($space->id), 'locale'), true)) return null;
        $node = $this->findNode($page->data->items, $nodeId, $locale, $space->id);
        if ($node === null || $node->type !== 'core.' . $kind) return null;
        return ['page_id' => $page->id, 'settings' => $node->props,
            'digest' => hash('sha256', json_encode($node->props, JSON_THROW_ON_ERROR))];
    }

    private function findNode(array $items, string $id, string $locale, int $spaceId): ?object
    {
        foreach ($items as $item) {
            if (!$this->isVisible($item->props, $locale, $spaceId)) continue;
            if ($item->id === $id) return $item;
            foreach (PageDocument::childZones($item) as $zone) {
                if (!$this->isVisible($zone->props, $locale, $spaceId)) continue;
                $found = $this->findNode($zone->items, $id, $locale, $spaceId);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    private function isVisible(object $props, string $locale, int $spaceId): bool
    {
        $common = $props->common ?? (object) [];
        if (($common->visible ?? true) !== true
            || (($common->languages ?? []) !== [] && !in_array($locale, $common->languages, true))) return false;
        $roles = $common->role_ids ?? [];
        if ($roles === []) return true;
        foreach ($roles as $roleId) {
            if ($this->coreAclHelper->hasGlobalRole($roleId, $spaceId)) return true;
        }
        return false;
    }

    public function token(Space $space, string $kind, string $locale, string $nodeId = '', ?int $pageId = null): ?string
    {
        if ($kind !== 'fallback' && $pageId === null) return null;
        $configuration = $kind === 'fallback' ? null : $this->configuration($space, $kind, $nodeId, $locale, $pageId);
        if ($kind !== 'fallback' && ($configuration === null || $configuration['page_id'] !== $pageId)) return null;
        return $this->coreCryptHelper->encrypt(json_encode([
            'space_id' => $space->id, 'kind' => $kind, 'locale' => $locale, 'node_id' => $nodeId,
            'digest' => $configuration['digest'] ?? '', 'page_id' => $pageId, 'expires' => time() + 3600,
        ], JSON_THROW_ON_ERROR), 'authentication:v1', $this->tokenKey());
    }

    public function readToken(string $token): ?object
    {
        if ($token === '' || strlen($token) > 4096) return null;
        $key = $this->session->get('authentication_token_key');
        if (!is_string($key) || strlen($key) !== 64) return null;
        try {
            $data = json_decode($this->coreCryptHelper->decrypt($token, 'authentication:v1', $key), false, 16, JSON_THROW_ON_ERROR);
            return is_object($data) && is_int($data->space_id ?? null)
                && in_array($data->kind ?? null, ['login', 'register', 'fallback'], true)
                && is_string($data->node_id ?? null) && is_string($data->locale ?? null)
                && ($data->kind === 'fallback' || is_int($data->page_id ?? null))
                && is_string($data->digest ?? null) && is_int($data->expires ?? null)
                && $data->expires >= time() ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function tokenKey(): string
    {
        // La clé reste privée en session. Le secours local fonctionne même sans secret applicatif configuré.
        $key = $this->session->get('authentication_token_key');
        if (!is_string($key) || strlen($key) !== 64) {
            $key = bin2hex(random_bytes(32));
            $this->session->set('authentication_token_key', $key);
        }
        return $key;
    }

    public function developmentCredentials(): array
    {
        // Ne jamais sérialiser ces identifiants dans une réponse de production.
        $configuration = $this->app->getConf();
        if ($configuration->dev !== true) return [];
        return ['email' => (string) $configuration->first_admin_email,
            'password' => (string) $configuration->first_admin_password];
    }

    public function destination(): string
    {
        return $this->coreSpaceSwitchHelper->destination($this->coreSpaceHelper->getCurrentSpace(), 'user');
    }
}
