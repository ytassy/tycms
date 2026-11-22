<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Forms;

use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Lib\Forms\FormFieldType;
use YTassy\Tycms\Lib\Forms\FormOptionContext;
use YTassy\Tycms\Lib\Forms\CallbackFormOptionsProvider;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Tools\ArrayTools;

class FormTypesHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public function registerCoreFieldTypes(): void {
        $formFactory = $this->formFactory;

        $this->registerFontFamilyFieldType();
        $this->registerLocalizedTextFieldType();
        $this->registerRichTextFieldType();

        $formFactory->registerType(new FormFieldType(
            id: 'core.media_file',
            vueComponent: 'forms/media-file',
            implicitDefault: '',
            valueSchema: static function (array $field): array {
                if (!in_array($field['mediaType'] ?? 'all', ['all', 'image', 'video', 'audio'], true)) {
                    throw new TechException('Le type de média demandé doit être « all », « image », « video » ou « audio ».');
                }
                if (isset($field['allowManualInput']) && !is_bool($field['allowManualInput'])) {
                    throw new TechException('allowManualInput doit être un booléen.');
                }
                if (!in_array($field['manualInputMode'] ?? 'media', ['media', 'any'], true)) {
                    throw new TechException('manualInputMode doit être « media » ou « any ».');
                }
                if (($field['allowManualInput'] ?? true) === false && ($field['manualInputMode'] ?? 'media') === 'any') {
                    throw new TechException('manualInputMode « any » requiert allowManualInput.');
                }
                return ['type' => 'string', 'maxLength' => 2048];
            },
        ));

        // DateTimeZone::ALL intentionally excludes legacy BC identifiers.
        $timezones = timezone_identifiers_list(\DateTimeZone::ALL);
        $formFactory->registerType(new FormFieldType(
            id: 'core.timezone',
            vueComponent: 'forms/select',
            acceptsOptions: true,
            implicitDefault: 'Europe/Paris',
            defaultOptions: array_combine($timezones, $timezones),
            valueSchema: static fn (array $field): array => [
                'type' => ($field['required'] ?? false) ? 'string' : ['string', 'null'],
                'enum' => $timezones,
            ],
        ));

        $spaces = $this->coreSpaceHelper->getAllSpacesByIds();

        // Menus by ID
        $menus = ArrayTools::indexArray($this->coreAdminHelper->getMenusICanlinkForEditors(), 'id');

        $menuOptions = array_map(function($menu) use ($spaces) {
            return $spaces[$menu->space_id]->title . ' - ' . $menu->name;
        }, $menus);
        $menuSpaces = array_map(static fn($menu): int => (int) $menu->space_id, $menus);

        $formFactory->registerType(new FormFieldType(
            id: 'catalog.core_menu',
            vueComponent: 'forms/select',
            acceptsOptions: true,
            defaultOptionsProvider: new CallbackFormOptionsProvider(
                static fn (FormOptionContext $context): array =>
                    self::filterOptionsBySpace($menuOptions, $menuSpaces, $context)
            ),
            valueSchema: static fn (array $field): array => [
                'type' => ($field['required'] ?? false)
                    ? 'integer' : ['integer', 'null'],
                'enum' => ($field['required'] ?? false) ? array_keys($menus) : [...array_keys($menus), null]
            ],
        ));


        $menuItemOptions = [];
        $menuItemSpaces = [];
        foreach($menus as $menu) {
            foreach($menu->items as $menuItem) {
                $menuItemOptions[$menuItem->id] = $menu->name . ' - ' . $menuItem->title;
                $menuItemSpaces[$menuItem->id] = (int) $menu->space_id;
            }
        }

        $formFactory->registerType(new FormFieldType(
            id: 'catalog.core_menu_item',
            vueComponent: 'forms/select',
            acceptsOptions: true,
            defaultOptionsProvider: new CallbackFormOptionsProvider(
                static fn (FormOptionContext $context): array =>
                    self::filterOptionsBySpace($menuItemOptions, $menuItemSpaces, $context)
            ),
            valueSchema: static fn (array $field): array => [
                'type' => ($field['required'] ?? false)
                    ? 'integer' : ['integer', 'null'],
                'enum' => array_keys($menuItemOptions)
            ],
        ));

        $pages = ArrayTools::indexArray($this->coreAdminHelper->getPagesICanlinkForEditors(), 'id');

        $pageOptions = array_map(function($page) use($spaces) {
            return $spaces[$page->space_id]->title . ' - ' . $page->name;
        }, $pages);
        $pageSpaces = array_map(static fn($page): int => (int) $page->space_id, $pages);

        $formFactory->registerType(new FormFieldType(
            id: 'catalog.core_all_page_item',
            vueComponent: 'forms/select',
            acceptsOptions: true,
            defaultOptionsProvider: new CallbackFormOptionsProvider(
                static fn (FormOptionContext $context): array =>
                    self::filterOptionsBySpace($pageOptions, $pageSpaces, $context)
            ),
            valueSchema: static fn (array $field): array => [
                'type' => ($field['required'] ?? false)
                    ? 'integer' : ['integer', 'null'],
                'enum' => array_keys($pages)
            ],
        ));

        // Le catalogue standard ne propose que les pages publiées.
        $publishedPages = array_filter($pages, static fn ($page) => $page->status === 1);
        $publishedPageOptions = array_map(function($page) use ($spaces) {
            return $spaces[$page->space_id]->title . ' - ' . $page->name;
        }, $publishedPages);
        $publishedPageSpaces = array_map(static fn($page): int => (int) $page->space_id, $publishedPages);

        $formFactory->registerType(new FormFieldType(
            id: 'catalog.core_page_item',
            vueComponent: 'forms/select',
            acceptsOptions: true,
            defaultOptionsProvider: new CallbackFormOptionsProvider(
                static fn (FormOptionContext $context): array =>
                    self::filterOptionsBySpace($publishedPageOptions, $publishedPageSpaces, $context)
            ),
            valueSchema: static fn (array $field): array => [
                'type' => ($field['required'] ?? false) ? 'integer' : ['integer', 'null'],
                'enum' => ($field['required'] ?? false) ? array_keys($publishedPages) : [...array_keys($publishedPages), null],
            ],
        ));
    }

    /**
     * Filtrer les options d'un catalogue sur le Space du formulaire.
     * Sans contexte de Space, conserver les éléments déjà autorisés par l'ACL.
     * @param array<int|string,string> $options
     * @param array<int|string,int> $spacesByKey
     * @return array<int|string,string>
     */
    private static function filterOptionsBySpace(array $options, array $spacesByKey,
        FormOptionContext $context): array
    {
        $spaceId = $context->getAttribute('spaceId') ?? $context->getAttribute('space_id');
        if ($spaceId === null) return $options;

        return array_intersect_key($options, array_filter($spacesByKey,
            static fn (int $itemSpaceId): bool => $itemSpaceId === (int) $spaceId));
    }


    /** Texte riche simple ; le workflow propriétaire purifie le HTML avant stockage. */
    public function registerRichTextFieldType(): void
    {
        $this->formFactory->registerType(new FormFieldType(
            id: 'core.rich_text', vueComponent: 'forms/rich-text', implicitDefault: '',
            valueSchema: ['type' => 'string', 'maxLength' => 500000],
        ));
    }

    /** Champ localisé commun, également utilisable par les formulaires des plugins. */
    public function registerLocalizedTextFieldType(): void
    {
        $this->formFactory->registerType(new FormFieldType(
            id: 'core.localized_text',
            vueComponent: 'forms/localized-text',
            implicitDefault: '',
            valueSchema: ['oneOf' => [
                ['type' => 'string', 'maxLength' => 500000],
                ['type' => 'object', 'minProperties' => 1, 'maxProperties' => 20,
                    'additionalProperties' => ['type' => 'string', 'maxLength' => 500000]],
            ]],
        ));
    }

    /** Type autonome : réutilisable et testable sans charger les catalogues SQL des autres champs. */
    public function registerFontFamilyFieldType(): void
    {
        $this->formFactory->registerType(new FormFieldType(
            id: 'core.font_family',
            vueComponent: 'forms/font-family',
            acceptsOptions: true,
            implicitDefault: 'arial',
            defaultOptionsProvider: new CallbackFormOptionsProvider(fn (): array => $this->coreFontHelper->options()),
            valueSchema: ['type' => 'string'],
        ));
    }
}
