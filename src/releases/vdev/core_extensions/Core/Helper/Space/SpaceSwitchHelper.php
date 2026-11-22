<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Space;

use Throwable;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;

/** Préparation des seules informations publiques du sélecteur, sans mutation métier. */
class SpaceSwitchHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public function configurationToken(object $settings): string
    {
        // Le navigateur ne peut pas élargir la liste éditoriale en forgeant ses paramètres.
        return $this->coreCryptHelper->encrypt(json_encode([
            'settings' => $settings, 'expires' => time() + 86400,
        ], JSON_THROW_ON_ERROR), 'space-switch:v1');
    }

    public function readConfiguration(string $token): ?object
    {
        if (strlen($token) > 16000) return null;
        try {
            $data = json_decode($this->coreCryptHelper->decrypt($token, 'space-switch:v1'), false, 32, JSON_THROW_ON_ERROR);
            return is_object($data) && is_object($data->settings ?? null)
                && is_int($data->expires ?? null) && $data->expires >= time() ? $data->settings : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{value:int,label:string}> */
    public function editorOptions(int $sourceSpaceId): array
    {
        $visible = array_fill_keys(array_map(static fn (Space $space): int => $space->id,
            $this->coreSpaceHelper->getSpacesMatchingRequestHost()), true);
        $options = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            if ($space->id !== $sourceSpaceId && !isset($visible[$space->id])
                && !$this->coreAclHelper->hasGlobalPermission(Permissions::MEMBER, $space->id)
                && !$this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_EDIT, $space->id)
                && !$this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_ADMIN, $space->id)) continue;
            $options[] = ['value' => $space->id, 'label' => $space->title];
        }
        usort($options, static fn (array $a, array $b): int => strnatcasecmp($a['label'], $b['label']) ?: $a['value'] <=> $b['value']);
        return $options;
    }

    /** @return array<int,Space> */
    public function candidates(object $settings): array
    {
        $spaces = [];
        if ($settings->from_domain) {
            foreach ($this->coreSpaceHelper->getSpacesMatchingRequestHost() as $space) $spaces[$space->id] = $space;
        }
        if ($settings->from_member) {
            foreach ($this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::MEMBER) as $id) {
                $space = $this->coreSpaceHelper->getAllSpacesByIds()[$id] ?? null;
                if ($space !== null) $spaces[$id] = $space;
            }
        }
        if ($settings->from_list) {
            foreach ($this->coreSpaceHelper->getSpacesByIds($settings->space_ids) as $space) $spaces[$space->id] = $space;
        }
        uasort($spaces, static fn (Space $a, Space $b): int => strnatcasecmp($a->title, $b->title) ?: $a->id <=> $b->id);
        return $spaces;
    }

    public function registrationEnabled(Space $space): bool
    {
        return $this->registrationUrl($space) !== null;
    }

    public function registrationUrl(Space $space): ?string
    {
        // Un non-membre doit pouvoir rejoindre la destination configurée dans le Space cible.
        if (!$this->coreAuthenticationHelper->registrationEnabled($space)) return null;

        return $this->coreAuthenticationHelper->pageUrl($space, 'register', $this->localeFor($space));
    }

    public function localeFor(Space $space): string
    {
        $locale = $this->corePublicLocaleHelper->locale();
        $active = array_column($this->coreSpaceLanguageHelper->listActiveForSpace($space->id), 'locale');
        return in_array($locale, $active, true) ? $locale : $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);
    }

    public function destination(Space $space, string $destination): string
    {
        $locale = $this->localeFor($space);
        if ($destination === 'user') {
            $itemId = $this->coreSpaceLanguageHelper->getUserSpaceMenuItemId($space->id, $locale)
                ?? $space->menu_item_user_space_id;
            if ($itemId !== null) {
                $url = $this->coreMenuRouteHelper->getUrlForContext($itemId, $space->id, $locale);
                if ($url !== null) return $url;
            }
        }
        return '/' . $locale . '/';
    }

    /** Projection publique : aucun paramètre SMTP, SSO ou secret n'est sérialisé. */
    public function present(Space $space, object $settings): array
    {
        $params = (array) $space->params;
        $member = $this->coreAclHelper->hasGlobalPermission(Permissions::MEMBER, $space->id);
        $registrationUrl = $this->registrationUrl($space);
        $logo = is_string($params['logo'] ?? null) ? $params['logo'] : '';
        if ($logo !== '' && $settings->show_logo) {
            try {
                $this->coreMediaHelper->assertSelectableFile($space, $logo, 'image');
            } catch (\RuntimeException) {
                $logo = '';
            }
        }
        return [
            'id' => $space->id, 'title' => $space->title,
            'logo' => $settings->show_logo ? $logo : '',
            'short_description' => $settings->show_short_description && is_string($params['short_description'] ?? null)
                ? $params['short_description'] : '',
            // Les descriptions historiques n'étaient pas purifiées à leur enregistrement.
            'description' => $settings->show_description && is_string($params['description'] ?? null)
                ? HtmlSanitizer::sanitize($params['description']) : '',
            'member' => $member, 'registration_enabled' => $registrationUrl !== null,
            'url' => $member ? $this->destination($space, $settings->destination)
                : $registrationUrl,
        ];
    }
}
