<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Language;

use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Catalogues versionnés fournis par les extensions ; aucun accès SQL. */
final class LanguageCatalogRegistry extends BaseHelper
{
    /** @var array<string,array{owner:string,source:string,translations:array<string,string>,recommended:bool,description:string}> */
    private array $entries = [];

    /** @param array<string,string> $files Locale => chemin du fichier PHP. */
    public function registerExtension(string $owner, array $files): void
    {
        // Le français définit les clés de référence de l'extension.
        if (!isset($files['fr'])) {
            throw new TechException("L'extension {$owner} doit déclarer son catalogue source fr.");
        }

        // Charger les fichiers de l'extension : leur contenu est du code de confiance.
        $catalogues = [];
        $metadata = [];
        foreach ($files as $locale => $path) {
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $locale) !== 1
                || !is_string($path) || !is_file($path) || !is_readable($path)) {
                throw new TechException("Catalogue de langue invalide pour {$owner}.");
            }
            $catalogue = require $path;
            if (!is_array($catalogue)) {
                throw new TechException("Le catalogue {$path} doit retourner un tableau.");
            }

            foreach ($catalogue as $key => $value) {
                if (!self::isKey($key)) {
                    throw new TechException("Clé invalide dans le catalogue {$path}.");
                }

                // Seul le catalogue source porte les indications destinées à l'administration.
                if (is_array($value) && $locale === 'fr') {
                    if (array_diff(array_keys($value), ['value', 'recommended', 'description']) !== []
                        || !is_string($value['value'] ?? null)
                        || (array_key_exists('recommended', $value) && !is_bool($value['recommended']))
                        || (array_key_exists('description', $value) && !is_string($value['description']))) {
                        throw new TechException("Déclaration enrichie invalide pour {$key} dans le catalogue {$path}.");
                    }

                    $metadata[$key] = [
                        'recommended' => $value['recommended'] ?? false,
                        'description' => $value['description'] ?? '',
                    ];
                    $value = $value['value'];
                }

                // Les traductions restent des chaînes, y compris lorsqu'elles sont vides.
                if (!is_string($value)) {
                    throw new TechException("Valeur invalide pour {$key} dans le catalogue {$path}.");
                }
                $catalogue[$key] = $value;
            }

            $catalogues[$locale] = $catalogue;
        }

        // Une traduction ne peut pas introduire une clé absente du français.
        foreach ($catalogues as $locale => $catalogue) {
            foreach ($catalogue as $key => $_) {
                if (!array_key_exists($key, $catalogues['fr'])) {
                    throw new TechException("La clé {$key} du catalogue {$locale} de {$owner} manque dans fr.");
                }
            }
        }

        // Le nom d'une clé reste unique entre toutes les extensions.
        foreach ($catalogues['fr'] as $key => $_) {
            if (isset($this->entries[$key])) {
                throw new TechException("La clé {$key} est déjà déclarée par {$this->entries[$key]['owner']}.");
            }
        }

        // Regrouper la source et ses traductions dans une seule entrée.
        foreach ($catalogues['fr'] as $key => $source) {
            $translations = [];
            foreach ($catalogues as $locale => $catalogue) {
                if (array_key_exists($key, $catalogue)) {
                    $translations[$locale] = $catalogue[$key];
                }
            }

            $this->entries[$key] = [
                'owner' => $owner,
                'source' => $source,
                'translations' => $translations,
                'recommended' => $metadata[$key]['recommended'] ?? false,
                'description' => $metadata[$key]['description'] ?? '',
            ];
        }
    }

    public static function isKey(mixed $key): bool
    {
        return is_string($key) && strlen($key) <= 150
            && preg_match('/^LG_[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/', $key) === 1;
    }

    /** @return array<string,array{owner:string,source:string,translations:array<string,string>,recommended:bool,description:string}> */
    public function all(): array
    {
        return $this->entries;
    }

    /** @return array{owner:string,source:string,translations:array<string,string>,recommended:bool,description:string}|null */
    public function get(string $key): ?array
    {
        return $this->entries[$key] ?? null;
    }
}
