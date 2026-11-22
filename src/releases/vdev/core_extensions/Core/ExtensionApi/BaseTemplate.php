<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\Extensions\BaseTemplate as ExtensionsBaseTemplate;

abstract class BaseTemplate extends ExtensionsBaseTemplate
{
    use TraitCoreSharedHelpers;

    /** Variables globales de couleur ; les valeurs restent définies par le CSS du thème. */
    public function getEditorColorVariables(): array
    {
        return [
            ['variable' => '--bs-primary', 'label' => 'Principale'],
            ['variable' => '--bs-secondary', 'label' => 'Secondaire'],
            ['variable' => '--bs-success', 'label' => 'Succès'],
            ['variable' => '--bs-info', 'label' => 'Information'],
            ['variable' => '--bs-warning', 'label' => 'Avertissement'],
            ['variable' => '--bs-danger', 'label' => 'Danger'],
            ['variable' => '--bs-body-color', 'label' => 'Texte'],
            ['variable' => '--bs-heading-color', 'label' => 'Titres'],
        ];
    }

    protected function getRiaGlobalVariable(): array{

        $user = $this->coreUserHelper->getUser();
        $userIsRoot = $this->coreAclHelper->isRoot();
        $userGlobalRoles = $this->coreAclHelper->getUserGlobalRoles();
        $userGlobalPermissions = $this->coreAclHelper->getUserGlobalPermissions(true);

        $space = $this->coreSpaceHelper->getCurrentSpace();
        
        static $timezones;
        if ($timezones === null) {
            // Garder l'identifiant IANA comme clé et afficher le décalage UTC actuel.
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $entries = [];
            foreach (\DateTimeZone::listIdentifiers() as $identifier) {
                $offset = (new \DateTimeZone($identifier))->getOffset($now);
                $sign = $offset < 0 ? '-' : '+';
                $absoluteOffset = abs($offset);

                $entries[] = [
                    'identifier' => $identifier,
                    'offset' => $offset,
                    'label' => sprintf(
                        'UTC%s%02d:%02d — %s',
                        $sign,
                        intdiv($absoluteOffset, 3600),
                        intdiv($absoluteOffset % 3600, 60),
                        $identifier
                    ),
                ];
            }

            // L'ordre de la table devient celui du menu Vue : décalage, puis nom.
            usort($entries, static fn (array $left, array $right): int =>
                ($left['offset'] <=> $right['offset']) ?: strcmp($left['identifier'], $right['identifier']));
            $timezones = array_column($entries, 'label', 'identifier');
        }

        $publicLocale = $this->corePublicLocaleHelper->locale() ?? $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);

        $defaultLocale = $this->coreSpaceLanguageHelper->getDefaultLocale($space->id);
        $availableLocales = array_map(
            static fn ($language) => $language->locale,
            $this->coreSpaceLanguageHelper->listActiveForSpace($space->id),
        );
        $languageMessages = $this->coreLanguageTranslator->riaDictionaryForSpace($space->id, $publicLocale);

        
        return [
            'templateColors' => $this->getEditorColorVariables(),
            'acl'=> [
                'user_is_root' => $userIsRoot,
                'user_global_roles' => $userGlobalRoles,
                'space_parent_ids' => $this->coreSpaceHelper->getSpaceParentIds(),
                //'user_global_roles_of_space' => $userGlobalRolesOfSpace,
                'user_global_permissions' => $userGlobalPermissions,
                //'user_global_permissions_of_space' => $userGlobalPermissionsOfSpace,
            ],
            'shortcodes' => ['mermaid' => \YTassy\Tycms\CoreExts\Core\Helper\ShortCode\DiagramShortCode::getDefaultOptions($this->coreShortCodeHelper->configuration($space->id))],
            'space' => $space,
            'user' => $user,
            'extensions' => array_keys($this->app->getExtensions()),
            'csrfToken' => $this->session->getCsrfToken(),
            'timezones' => $timezones,
            'language' => [
                'locale' => $publicLocale,
                'defaultLocale' => $defaultLocale,
                'availableLocales' => $availableLocales,
                'messages' => $languageMessages,
            ],
        ];
    }
    
}
