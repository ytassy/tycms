<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Support;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Menu\MenuItemDef;

/**
 * Catalogue des types de menu exposés à l'IA.
 *
 * Le serveur MCP n'expose pas un couple `extension`/`type` libre : chaque type
 * marqué `ai: true` par son extension devient une action nommée par
 * `aiMcpAction`. Ce catalogue décrit ces types et, surtout, dérive le schéma
 * de leurs `params` depuis `paramsFormDefinition`.
 *
 * Dériver plutôt que recopier évite la divergence : si un champ du formulaire
 * change, le catalogue suit sans maintenance.
 */
final class MenuTypeCatalog
{
    /** Types de formulaire du CMS vers le type attendu par le langage. */
    private const TYPE_MAP = [
        'int' => 'int',
        'float' => 'float',
        'bool' => 'bool',
        'array' => 'array',
    ];

    /**
     * @param array<string, MenuItemDef> $definitions Indexées par `extension__type`.
     * @return list<array<string, mixed>>
     */
    public static function exposed(array $definitions): array
    {
        $types = [];

        foreach ($definitions as $definition) {
            // Un type non marqué reste réservé à l'éditeur.
            if (!$definition->ai || $definition->aiMcpAction === null) {
                continue;
            }

            $types[] = [
                'key' => $definition->extension . '__' . $definition->type,
                'extension' => $definition->extension,
                'type' => $definition->type,
                'label' => $definition->ui_name,
                'action' => $definition->aiMcpAction,
                'doc' => $definition->aiMcpDoc ?? '',
                'params' => self::paramsSchema($definition->paramsFormDefinition),
            ];
        }

        usort($types, static fn (array $left, array $right): int => strcmp($left['key'], $right['key']));

        return $types;
    }

    /**
     * Schéma des `params` d'un type, extrait de son formulaire.
     *
     * Le formulaire décrit une liste de sections, chacune portant des champs.
     * On aplatit cette structure : côté langage, `params` reste une map plate
     * de clé/valeur, pas un arbre de sections.
     *
     * @param array<string, mixed>|null $formDefinition
     * @return array<string, array<string, mixed>>
     */
    public static function paramsSchema(?array $formDefinition): array
    {
        if ($formDefinition === null) {
            return [];
        }

        $schema = [];

        foreach ($formDefinition['sections'] ?? [] as $section) {
            foreach ($section['fields'] ?? [] as $field) {
                if (!is_array($field) || !is_string($field['name'] ?? null)) {
                    continue;
                }

                $schema[$field['name']] = [
                    'type' => self::languageType($field),
                    'required' => (bool) ($field['required'] ?? false),
                    'label' => (string) ($field['label'] ?? $field['name']),
                    'fieldType' => (string) ($field['type'] ?? 'text'),
                ];
            }
        }

        return $schema;
    }

    /**
     * Type du langage pour un champ de formulaire.
     *
     * Les champs de catalogue (par exemple `catalog.core_page_item`) portent
     * un identifiant de ressource : ils sont des entiers côté langage.
     *
     * @param array<string, mixed> $field
     */
    private static function languageType(array $field): string
    {
        $type = (string) ($field['type'] ?? 'text');

        if (str_starts_with($type, 'catalog.')) {
            return 'int';
        }

        return self::TYPE_MAP[$type] ?? 'string';
    }
}
