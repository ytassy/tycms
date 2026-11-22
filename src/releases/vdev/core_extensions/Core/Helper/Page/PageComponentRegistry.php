<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageLinks;
use YTassy\Tycms\CoreExts\Core\PageComponents\Support\PageColors;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageComponent;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageCanvasMode;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageDataMode;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Lib\Forms\FormJson;
use YTassy\Tycms\Response\ExceptionResponse;

/** Répertoire de classes : aucune instance ni requête métier pendant l'enregistrement. */
final class PageComponentRegistry extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    /** @var array<string,class-string<PageComponent>> */
    private array $types = [];
    private array $owners = [];
    private array $configuredLocalesBySpace = [];

    private string $registrationOwner = 'plg_core';

    public function withOwner(string $owner, \Closure $register): void
    {
        $previous = $this->registrationOwner;
        $this->registrationOwner = $owner;
        try { $register(); }
        finally { $this->registrationOwner = $previous; }
    }

    public function register(string $class): void
    {
        $owner = $this->registrationOwner;
        if (!is_subclass_of($class, PageComponent::class) || (new \ReflectionClass($class))->isAbstract()) {
            throw new TechException('Classe de composant de page invalide : ' . $class);
        }
        $id = $class::getId();
        if (preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/D', $id) !== 1 || isset($this->types[$id])
            || preg_match('/^(?:plg|mod|tpl)_[a-z][a-z0-9_]*$/D', $owner) !== 1) {
            throw new TechException('Identifiant de composant invalide ou répété : ' . $id);
        }
        if ($class::getCanvasMode() === PageCanvasMode::VUE && $class::getCanvasVueComponentName() === null) {
            throw new TechException('Composant Vue de canevas manquant : ' . $id);
        }
        foreach ($class::getChildZones() as $name => $definition) {
            $min = $definition['min'] ?? 0;
            $max = $definition['max'] ?? PageDocument::MAX_ZONE_BODIES;
            $initial = $definition['initial'] ?? $min;
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1
                || !is_int($min) || !is_int($max) || !is_int($initial)
                || $min < 0 || $max > PageDocument::MAX_ZONE_BODIES || $min > $initial || $initial > $max) {
                throw new TechException('Définition de zone invalide : ' . $id);
            }
        }
        foreach ($class::getEditorFields() as $field) {
            if (($field['localized'] ?? false) !== true) continue;
            $name = $field['name'] ?? null;
            $schema = $class::getPropsSchema();
            if (!is_string($name) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name) !== 1
                || !in_array($field['type'] ?? 'text', ['text', 'textarea', 'html', 'rich', 'url'], true)
                || ($schema !== null && ($schema['properties'][$name]['type'] ?? null) !== 'string')
                || (isset($field['maxLength']) && (!is_int($field['maxLength']) || $field['maxLength'] < 1 || $field['maxLength'] > 500000))) {
                throw new TechException('Champ localisé invalide : ' . $id);
            }
        }
        FormJson::assertValue($class::getDefaultData(), $id . '.defaults');
        FormJson::assertValue($class::getChildZones(), $id . '.zones');
        $this->types[$id] = $class;
        $this->owners[$id] = $owner;
    }

    public function get(string $id): ?string
    {
        return $this->types[$id] ?? null;
    }

    public function create(string $id): ?PageComponent
    {
        $class = $this->get($id);
        return $class === null ? null : new $class($this);
    }

    public function catalogue(): array
    {
        $result = [];
        foreach ($this->types as $id => $class) {
            $result[] = [
                'id' => $id, 'label' => $class::getLabel(), 'category' => $class::getCategory(),
                'defaults' => (object) $class::getDefaultData(), 'propsSchema' => $class::getPropsSchema(),
                'zones' => (object) $class::getChildZones(), 'nodeReferenceFields' => (object) $class::getNodeReferenceFields(),
                'exclusiveReferenceFields' => (object) $class::getExclusiveReferenceFields(),
                'formVue' => $class::getFormVueComponentName(), 'canvasVue' => $class::getCanvasVueComponentName(),
                'canvasMode' => $class::getCanvasMode()->value, 'publicVueId' => $class::getPublicVueComponentName(),
                'editorFields' => $class::getEditorFields(),
            ];
        }
        return $result;
    }

    /** Précharger tous les types ajoutables, même s'ils ne figurent pas encore sur la page. */
    public function prepareEditor(int $spaceId, array $common = []): array
    {
        $instances = [];
        $context = $this->collectEditContext($spaceId, $common, $instances);
        $forms = [];
        foreach ($instances as $id => $component) {
            $form = $component->getGeneralForm($context);
            if ($form === null) continue;
            if ($component::getFormVueComponentName() !== null) throw new TechException('Deux formulaires pour ' . $id);
            $forms[$id] = $form->getDefForRia([], ['spaceId' => $spaceId, 'pageContext' => $context->toArray()]);
        }
        return ['context' => (object) $context->toArray(), 'forms' => (object) $forms];
    }

    private function collectEditContext(int $spaceId, array $common, array &$instances): PageEditContext
    {
        $context = new PageEditContext($spaceId);
        $context->add('plg_core', array_replace([
            'plg_core_page_max_size' => PageSize::pageMaximum($this->app->getConf()),
            'plg_core_template_colors' => $this->coreTemplateHelper->getEditorColorVariables($spaceId),
            'plg_core_menu_items' => (new PageLinks($this->coreMenuHelper, $this->coreMenuRouteHelper, $this->coreSpaceLanguageHelper))->menuOptions($spaceId),
        ], $common));
        foreach ($this->types as $id => $class) {
            $instances[$id] ??= new $class($this);
            $context->add($this->owners[$id], $instances[$id]->getEditContext($context));
        }
        return $context;
    }

    /** L'appelant a déjà contrôlé les droits sur le Space et la page. */
    public function normalizeDocument(object $document, int $spaceId, PageDataMode $mode = PageDataMode::INPUT, array &$instances = []): object
    {
        if ($mode === PageDataMode::INPUT) PageSize::assertDocument($document, PageSize::pageMaximum($this->app->getConf()));
        $document = PageDocument::normalize($document);
        $editor = $mode === PageDataMode::INPUT ? $this->collectEditContext($spaceId, [], $instances) : null;
        $context = new PageNormalizationContext($spaceId, $mode, $editor);
        $this->normalizeItems($document->items, $context, $instances);

        // Vérifier le catalogue reconstruit au serveur, jamais celui reçu du navigateur.
        if ($context->isInput()) {
            PageColors::validateDocumentReferences($document, $editor->get('plg_core_template_colors', []));
            PageSize::assertDocument($document, PageSize::pageMaximum($this->app->getConf()));
        }
        return $document;
    }

    public function validateDocument(object $document, int $spaceId): void
    {
        $normalized = $this->normalizeDocument($document, $spaceId);
        $document->items = $normalized->items;
    }

    private function normalizeItems(array $items, PageNormalizationContext $context, array &$instances): void
    {
        foreach ($items as $item) {
            if ($context->isInput()) $this->validateCommon($item->props, $context->spaceId);
            $class = $this->get($item->type);
            if ($class !== null) {
                if (!isset($instances[$item->type])) {
                    $instances[$item->type] = new $class($this);
                }
                $component = $instances[$item->type];
                $definitions = $class::getChildZones();
                foreach (get_object_vars($item->zones) as $name => $bodies) {
                    if (!array_key_exists($name, $definitions)) ExceptionResponse::throwWarning('Zone inconnue pour ' . $item->type . '.');
                }
                foreach ($definitions as $name => $definition) {
                    $bodies = $item->zones->{$name} ?? [];
                    if (count($bodies) < ($definition['min'] ?? 0) || count($bodies) > ($definition['max'] ?? PageDocument::MAX_ZONE_BODIES)) {
                        ExceptionResponse::throwWarning('Nombre de sections invalide pour ' . $item->type . '.');
                    }
                    $item->zones->{$name} = array_map(fn ($zone) => $component->normalizeZone($name, $zone, $context), $bodies);
                }
                $common = $item->props->common ?? null;
                $specific = clone $item->props;
                unset($specific->common);
                $specific = (object) array_replace($class::getDefaultData(), get_object_vars($specific));
                $specific = $component->normalizeData($specific, $context);
                $specific = $this->normalizeLocalized($class, $specific, $context);
                if ($context->isInput()) {
                    $this->validateData($component, $specific, $context);
                    $form = $component->getGeneralForm($context->editor);
                    if ($form !== null) {
                        foreach ($form->getDefinition()['sections'] as $section) {
                            foreach ($section['fields'] as $field) {
                                if (($field['type'] ?? '') !== 'core.localized_text' || !property_exists($specific, $field['name'])) continue;
                                $specific->{$field['name']} = PageLocalizedText::normalize($specific->{$field['name']},
                                    $field['maxLength'] ?? 500, in_array($field['mode'] ?? '', ['html', 'rich'], true));
                            }
                        }
                        $result = $form->validateSubmittedValues($specific, ['spaceId' => $context->spaceId, 'pageContext' => $context->editor->toArray()]);
                        if (!$result->isValid()) ExceptionResponse::throwWarning('Formulaire invalide pour ' . $class::getLabel() . '.');
                        $specific = $result->value;
                    }
                }
                $item->props = $specific;
                if ($common !== null) $item->props->common = $common;
                $component->validateNode($item, $context);
            }
            // Les blocs d'une extension absente gardent leurs zones, sans rendu public.
            foreach (PageDocument::childZones($item) as $zone) {
                if ($context->isInput()) $this->validateCommon($zone->props, $context->spaceId);
                $this->normalizeItems($zone->items, $context, $instances);
            }
        }
    }

    private function normalizeLocalized(string $class, object $props, PageNormalizationContext $context): object
    {
        if (!$context->isInput()) return $props;
        foreach ($class::getEditorFields() as $field) {
            if (($field['localized'] ?? false) !== true || !property_exists($props, $field['name'])) continue;
            $html = in_array($field['type'] ?? '', ['html', 'rich'], true);
            $props->{$field['name']} = PageLocalizedText::normalize($props->{$field['name']}, $field['maxLength'] ?? ($html ? 500000 : 500), $html);
        }
        return $props;
    }

    public function projectPropsForLocale(string $class, object $props, string $locale, int $spaceId): object
    {
        $projected = clone $props;
        foreach ($class::getEditorFields() as $field) {
            if (($field['localized'] ?? false) !== true || !property_exists($projected, $field['name'])) continue;
            $projected->{$field['name']} = $this->coreLanguageProjectionHelper->projectLocalizedTextValue($projected->{$field['name']}, $locale, $spaceId);
        }
        return $projected;
    }

    private function validateData(PageComponent $component, object $props, PageNormalizationContext $context): void
    {
        $locales = [];
        foreach ($component::getEditorFields() as $field) {
            if (($field['localized'] ?? false) !== true) continue;
            $locales = array_keys($this->configuredLocales($context->spaceId));
            break;
        }
        foreach ($component::getEditorFields() as $field) {
            if (($field['localized'] ?? false) !== true) continue;
            $value = $props->{$field['name']} ?? null;
            if ($value instanceof \stdClass) $locales = array_merge($locales, array_keys(get_object_vars($value)));
        }
        foreach ($locales === [] ? [null] : array_unique($locales) as $locale) {
            $projected = $locale === null ? $props : $this->projectPropsForLocale($component::class, $props, $locale, $context->spaceId);
            if ($component::getPropsSchema() !== null && !$this->sharedObjectValidator->validate($projected, $component::getPropsSchema())->isValid()) {
                ExceptionResponse::throwWarning('Paramètres invalides pour ' . $component::getLabel() . '.');
            }
            $component->validateData($projected, $context);
        }
    }

    /** @return array<string,true> */
    private function configuredLocales(int $spaceId): array
    {
        if (!isset($this->configuredLocalesBySpace[$spaceId])) {
            $this->configuredLocalesBySpace[$spaceId] = array_fill_keys(array_map(
                static fn ($language) => $language->locale,
                $this->coreSpaceLanguageHelper->listForSpace($spaceId),
            ), true);
        }

        return $this->configuredLocalesBySpace[$spaceId];
    }

    private function getAvailableRoles(int $spaceId): array
    {
        return $this->coreSpaceHelper->getRolesForSpace($spaceId);
    }

    private function validateCommon(object $props, int $spaceId): void
    {
        $common = $props->common ?? null;
        if ($common === null) return;
        if (!is_object($common)) ExceptionResponse::throwWarning('Options communes invalides.');
        PageDocument::validateCommonVisibility($props);
        PageCommonStyles::validate($props);
        $roles = $common->role_ids ?? [];
        if (!is_array($roles) || count($roles) > 32) ExceptionResponse::throwWarning('Liste de rôles invalide.');
        $available = $this->getAvailableRoles($spaceId);
        foreach ($roles as $roleId) {
            if (!is_int($roleId) || !isset($available[$roleId])) ExceptionResponse::throwWarning('Rôle de composant invalide.');
        }

        // Une liste vide conserve la visibilité historique dans toutes les langues.
        // Une liste renseignée peut conserver une langue désactivée : sa
        // condition restera éditable si elle est réactivée plus tard.
        $languages = property_exists($common, 'languages') ? $common->languages : [];
        if (!is_array($languages) || count($languages) > 20) {
            ExceptionResponse::throwWarning('Langues visibles invalides.');
        }

        if ($languages === []) return;

        $seen = [];

        foreach ($languages as $locale) {
            if (!is_string($locale) || !isset($this->configuredLocales($spaceId)[$locale])
                || isset($seen[$locale])) {
                ExceptionResponse::throwWarning('Langue visible inconnue ou répétée.');
            }

            $seen[$locale] = true;
        }
    }
}
