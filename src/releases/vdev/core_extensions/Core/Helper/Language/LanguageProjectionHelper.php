<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Language;

use ReflectionClass;
use stdClass;
use SplObjectStorage;
use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\BaseOrmObject;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageLocalizedText;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Construit des données de rendu sans modifier les modèles ORM. */
final class LanguageProjectionHelper extends BaseHelper
{
    private const MAX_DEPTH = 32;
    private const MAX_NODES = 100000;
    private const MAX_TEXT_BYTES = 16777216;

    /** @var array<class-string,array<string,FieldType>> */
    private static array $typesByClass = [];

    /** @return array<string,mixed> */
    public function projectOrm(BaseOrmObject $object, string $locale, int $spaceId): array
    {
        // La sérialisation du modèle conserve les champs masqués et les règles
        // propres à celui-ci. Le résultat est une copie de présentation.
        $projection = $object->jsonSerialize();

        foreach ($this->fieldTypes($object::class) as $name => $type) {
            if (!array_key_exists($name, $projection)) {
                continue;
            }

            $projection[$name] = $this->projectFieldValue(
                $projection[$name],
                $type,
                $locale,
                $spaceId,
            );
        }

        return $projection;
    }

    public function projectFieldValue(mixed $value, FieldType $type, string $locale, int $spaceId): mixed
    {
        // Les types classiques ne participent jamais à la traduction.
        if ($type === FieldType::TEXT) {
            return is_string($value)
                ? $this->coreLanguageTranslator->resolve($value, $locale, $spaceId)
                : $value;
        }

        if ($type !== FieldType::JSON_TEXT && $type !== FieldType::JSON_ARRAY_TEXT) {
            return $value;
        }

        // Le budget évite qu'une structure anormalement grande ou cyclique
        // accapare le rendu. Aucune donnée brute n'est modifiée en cas d'erreur.
        $budget = (object) ['nodes' => 0, 'bytes' => 0];
        $seen = new SplObjectStorage();
        $resolved = [];

        return $this->projectJsonValue(
            $value,
            $locale,
            $spaceId,
            0,
            $budget,
            $seen,
            $resolved,
        );
    }

    public function projectLocalizedTextValue(mixed $value, string $locale, int $spaceId): string
    {
        // Un objet de langues est interprété uniquement quand le champ appelant
        // déclare explicitement qu'il contient du texte localisable.
        $selected = PageLocalizedText::select($value, $locale);

        return $this->coreLanguageTranslator->resolve($selected, $locale, $spaceId);
    }

    /** @return array<string,FieldType> */
    private function fieldTypes(string $className): array
    {
        if (isset(self::$typesByClass[$className])) {
            return self::$typesByClass[$className];
        }

        $types = [];
        foreach ((new ReflectionClass($className))->getProperties() as $property) {
            $attributes = $property->getAttributes(Field::class);
            if ($attributes === []) {
                continue;
            }

            $types[$property->getName()] = $attributes[0]->newInstance()->type;
        }

        return self::$typesByClass[$className] = $types;
    }

    /** @param array<string,string> $resolved */
    private function projectJsonValue(
        mixed $value,
        string $locale,
        int $spaceId,
        int $depth,
        object $budget,
        SplObjectStorage $seen,
        array &$resolved,
    ): mixed {
        if ($depth > self::MAX_DEPTH || ++$budget->nodes > self::MAX_NODES) {
            throw new TechException('La structure JSON traduisible dépasse les limites de profondeur ou de taille.');
        }

        if (is_string($value)) {
            $budget->bytes += strlen($value);
            if ($budget->bytes > self::MAX_TEXT_BYTES) {
                throw new TechException('La structure JSON traduisible contient trop de texte.');
            }

            if (!LanguageCatalogRegistry::isKey($value)) {
                return $value;
            }

            if (!array_key_exists($value, $resolved)) {
                $resolved[$value] = $this->coreLanguageTranslator->resolve($value, $locale, $spaceId);
            }

            return $resolved[$value];
        }

        if (is_array($value)) {
            $projected = [];
            foreach ($value as $key => $child) {
                $projected[$key] = $this->isProtectedJsonKey($key)
                    ? $child
                    : $this->projectJsonValue($child, $locale, $spaceId, $depth + 1,
                        $budget, $seen, $resolved);
            }

            return $projected;
        }

        if (!$value instanceof stdClass) {
            return $value;
        }

        if ($seen->offsetExists($value)) {
            throw new TechException('La structure JSON traduisible contient une référence cyclique.');
        }

        $seen->offsetSet($value);
        $projected = new stdClass();

        try {
            foreach (get_object_vars($value) as $key => $child) {
                $projected->{$key} = $this->isProtectedJsonKey($key)
                    ? $child
                    : $this->projectJsonValue($child, $locale, $spaceId, $depth + 1,
                        $budget, $seen, $resolved);
            }
        } finally {
            $seen->offsetUnset($value);
        }

        return $projected;
    }

    private function isProtectedJsonKey(int|string $key): bool
    {
        if (is_int($key)) {
            return false;
        }

        // Ces noms décrivent des identifiants techniques, même dans un champ
        // JSON_TEXT. Leur sous-arbre reste intégralement brut.
        $normalized = strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $key));

        return preg_match(
            '/(?:^|_)(?:id|identifier|uuid|url|href|src|path|route|slug|alias|css|class|style|type|extension|permission|role|key|model|media|file|filename|icon)$/',
            $normalized,
        ) === 1 || preg_match(
            '/(?:class|route|model|permission|role|extension)_name$/',
            $normalized,
        ) === 1;
    }
}
