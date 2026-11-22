<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;
use YTassy\Tycms\CoreExts\Core\Helper\Language\LanguageCatalogRegistry;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

final class WFLanguage extends BaseWF
{
    use TraitAllHelpers;

    /** @var array<int,list<string>>|null */
    private ?array $activeLocalesBySpace = null;

    /** @return array{spaces:list<array<string,mixed>>} */
    public function listSpaces(): array
    {
        // Ne montrer que les Spaces où l'utilisateur peut gérer les traductions.
        $spaces = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $space) {
            if (!$this->coreAclHelper->hasGlobalPermission(Permissions::LANG_OVERRIDE_CRUD, $space->id)) {
                continue;
            }

            $spaces[] = [
                'id' => $space->id,
                'title' => $space->title,
                'languages' => $this->getEditableLocales($space->id),
                'defaultLocale' => $this->coreSpaceLanguageHelper->getDefaultLocale($space->id),
            ];
        }

        return ['spaces' => $spaces];
    }

    /** @return array{entries:list<array<string,mixed>>} */
    public function listEntries(int $spaceId, string $locale): array
    {
        $this->assertCanEdit($spaceId);
        $locale = $this->assertEditableLocale($spaceId, $locale);

        // Conserver cette lecture par langue pour les clients de l'API existante.
        $entries = [];
        foreach (array_keys($this->coreLanguageTranslator->entriesForSpace($spaceId)) as $key) {
            $entries[] = $this->coreLanguageTranslator->describe($key, $locale, $spaceId);
        }

        return ['entries' => $entries];
    }

    /** @return array{locales:list<string>,entries:list<array<string,mixed>>} */
    public function listMatrix(int $spaceId): array
    {
        $this->assertCanEdit($spaceId);
        $locales = $this->getEditableLocales($spaceId);

        // Assembler toutes les langues d'une clé dans une seule ligne du tableau.
        $entries = [];
        foreach ($this->coreLanguageTranslator->entriesForSpace($spaceId) as $key => $entry) {
            $languages = [];
            foreach ($locales as $locale) {
                $languages[$locale] = $this->coreLanguageTranslator->describe($key, $locale, $spaceId);
            }
            $entries[] = [
                'key' => $key,
                'owner' => $entry['owner'],
                'source' => $entry['source'],
                'recommended' => $entry['recommended'] ?? false,
                'description' => $entry['description'] ?? '',
                'languages' => $languages,
            ];
        }

        return ['locales' => $locales, 'entries' => $entries];
    }

    /** @return array{entries:list<array{key:string,value:string}>} */
    public function listSelectableKeys(int $spaceId, string $locale): array
    {
        // La consultation des clés d'une page ne requiert pas le droit de modifier les traductions.
        // Les droits sont vérifiés sur le Space demandé, qui peut différer du Space en session.
        if ($spaceId < 1 || !isset($this->coreSpaceHelper->getAllSpacesByIds()[$spaceId])) {
            ExceptionResponse::throwWarning('Espace introuvable.');
        }

        $canRead = $this->coreAclHelper->hasGlobalPermission(Permissions::PAGE_READ, $spaceId);

        if (!$canRead) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas consulter les clés de langue de cet espace.');
        }

        $activeLocales = array_map(
            static fn ($language): string => $language->locale,
            $this->coreSpaceLanguageHelper->listActiveForSpace($spaceId),
        );

        if (!in_array($locale, $activeLocales, true)) {
            ExceptionResponse::throwWarning('Langue inactive ou inconnue pour cet espace.');
        }

        // Le dictionnaire calcule en bloc les surcharges locales et héritées.
        $entries = [];
        foreach ($this->coreLanguageTranslator->dictionaryForSpace($spaceId, $locale) as $key => $value) {
            $entries[] = ['key' => $key, 'value' => $value];
        }

        return ['entries' => $entries];
    }

    public function createCustomKey(int $spaceId, object $data): void
    {
        $this->assertCanEdit($spaceId);

        // Vérifier la syntaxe de la clé et la taille du texte transmis.
        $key = trim((string) ($data->key ?? ''));
        $sourceValue = (string) ($data->sourceValue ?? '');
        if (!LanguageCatalogRegistry::isKey($key) || trim($sourceValue) === '' || strlen($sourceValue) > 5000) {
            ExceptionResponse::throwWarning('Clé de langue ou texte source invalide.');
        }

        // Le texte source peut contenir du HTML, mais seul le HTML autorisé est stocké.
        $sourceValue = HtmlSanitizer::sanitize($sourceValue);
        if (trim($sourceValue) === '' || strlen($sourceValue) > 5000) {
            ExceptionResponse::throwWarning('Le texte source ne contient aucun contenu HTML autorisé.');
        }

        // La clé doit rester unique dans les catalogues et dans toute la base.
        if ($this->coreLanguageCatalogRegistry->get($key) !== null
            || $this->coreLanguageOverrideAdminHelper->customKeyExists($key)) {
            ExceptionResponse::throwWarning('Cette clé de langue existe déjà.');
        }

        $this->coreLanguageOverrideAdminHelper->createCustomKey(
            $spaceId, $key, $sourceValue, $this->session->getUserId());

        // La nouvelle clé doit être visible immédiatement dans cette requête.
        $this->coreLanguageTranslator->invalidate();
    }

    public function saveOverride(int $spaceId, object $data): void
    {
        $this->assertCanEdit($spaceId);

        // Vérifier le périmètre avant d'écrire une surcharge pour cette langue.
        $locale = $this->assertEditableLocale($spaceId, $data->locale ?? null);
        $key = $this->assertKnownKey($spaceId, $data->key ?? null);
        $value = $this->sanitizeOverrideValue($data->value ?? null);

        $this->coreLanguageOverrideAdminHelper->save(
            $spaceId, $locale, $key, $value, $this->session->getUserId());

        $this->coreLanguageTranslator->invalidate();
    }

    public function deleteOverride(int $spaceId, object $data): void
    {
        $this->assertCanEdit($spaceId);

        $locale = $this->assertEditableLocale($spaceId, $data->locale ?? null);
        $key = $this->assertKnownKey($spaceId, $data->key ?? null);

        $this->coreLanguageOverrideAdminHelper->delete($spaceId, $locale, $key);
        $this->coreLanguageTranslator->invalidate();
    }

    public function saveManyOverrides(int $spaceId, object $data): void
    {
        $this->assertCanEdit($spaceId);

        $key = $this->assertKnownKey($spaceId, $data->key ?? null);
        $changes = $data->changes ?? null;
        if (!is_array($changes) || count($changes) < 1 || count($changes) > 20) {
            ExceptionResponse::throwWarning('Liste des traductions invalide.');
        }

        // Valider toutes les langues et les actions avant la première écriture.
        $normalized = [];
        $seen = [];
        foreach ($changes as $change) {
            if (!is_object($change)) {
                ExceptionResponse::throwWarning('Traduction invalide.');
            }

            $locale = $this->assertEditableLocale($spaceId, $change->locale ?? null);
            if (isset($seen[$locale])) {
                ExceptionResponse::throwWarning('Langue répétée dans la requête.');
            }

            $seen[$locale] = true;
            $action = $change->action ?? null;

            if ($action === 'save') {
                $value = $this->sanitizeOverrideValue($change->value ?? null);
                $normalized[] = [$locale, 'save', $value];
            } elseif ($action === 'delete' && !property_exists($change, 'value')) {
                $normalized[] = [$locale, 'delete', null];
            } else {
                ExceptionResponse::throwWarning('Action de traduction invalide.');
            }
        }

        // Le runner englobe ces modifications dans une même transaction.
        foreach ($normalized as [$locale, $action, $value]) {
            if ($action === 'save') {
                $this->coreLanguageOverrideAdminHelper->save(
                    $spaceId, $locale, $key, $value, $this->session->getUserId());
            } else {
                $this->coreLanguageOverrideAdminHelper->delete($spaceId, $locale, $key);
            }
        }

        $this->coreLanguageTranslator->invalidate();
    }

    private function sanitizeOverrideValue(mixed $value): string
    {
        // Une chaîne vide est admise : elle masque volontairement le texte hérité.
        if (!is_string($value) || strlen($value) > 20000) {
            ExceptionResponse::throwWarning('La traduction est invalide ou trop longue.');
        }

        // Vérifier aussi la taille après purification, qui peut réécrire le HTML.
        $sanitized = HtmlSanitizer::sanitize($value);
        if (strlen($sanitized) > 20000) {
            ExceptionResponse::throwWarning('La traduction est trop longue.');
        }

        return $sanitized;
    }

    private function assertCanEdit(int $spaceId): void
    {
        // Le contrôle porte sur le Space demandé, indépendamment du Space en session.
        if ($spaceId < 1 || !isset($this->coreSpaceHelper->getAllSpacesByIds()[$spaceId])) {
            ExceptionResponse::throwWarning('Espace introuvable.');
        }

        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::LANG_OVERRIDE_CRUD, $spaceId)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour modifier les traductions de cet espace.');
        }
    }

    /** @return list<string> Langues actives ici ou dans un Space descendant. */
    private function getEditableLocales(int $spaceId): array
    {
        // Charger une fois les langues actives de toute la branche des Spaces.
        if ($this->activeLocalesBySpace === null) {
            $this->activeLocalesBySpace = $this->coreLanguageOverrideAdminHelper->enabledLocalesBySpace();
        }

        // Un parent peut préparer une traduction pour une langue active chez un enfant.
        $locales = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $candidate) {
            if (!in_array($spaceId, $this->coreSpaceHelper->getSpaceIdsWithParents($candidate->id), true)) {
                continue;
            }

            foreach ($this->activeLocalesBySpace[$candidate->id] ?? [] as $locale) {
                $locales[$locale] = true;
            }
        }

        return array_keys($locales);
    }

    private function assertEditableLocale(int $spaceId, mixed $locale): string
    {
        if (!is_string($locale)) {
            ExceptionResponse::throwWarning('Langue invalide.');
        }

        $locale = strtolower(trim($locale));
        if (in_array($locale, $this->getEditableLocales($spaceId), true)) {
            return $locale;
        }

        ExceptionResponse::throwWarning('Cette langue n’est active ni dans cet espace ni dans ses descendants.');
    }

    private function assertKnownKey(int $spaceId, mixed $key): string
    {
        if (!LanguageCatalogRegistry::isKey($key)
            || !isset($this->coreLanguageTranslator->entriesForSpace($spaceId)[$key])) {
            ExceptionResponse::throwWarning('Cette clé de langue n’est pas déclarée pour cet espace.');
        }

        return $key;
    }
}
