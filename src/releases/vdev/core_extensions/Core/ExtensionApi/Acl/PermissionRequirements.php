<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;

use YTassy\Tycms\Exceptions\TechException;

/** Graphe du catalogue ; ne participe jamais au calcul des droits effectifs. */
final class PermissionRequirements
{
    /** @var array<string, PermDef> */
    private array $definitions = [];

    /** @var array<string, list<string>> */
    private array $requirements = [];

    /** @param array<string, array<string, PermDef>> $definitionsByExtension */
    public function __construct(array $definitionsByExtension)
    {
        foreach ($definitionsByExtension as $definitions) {
            foreach ($definitions as $definition) {
                if (isset($this->definitions[$definition->name])) {
                    throw new TechException('Permission déclarée plusieurs fois : ' . $definition->name);
                }
                $this->definitions[$definition->name] = $definition;
            }
        }

        // Résoudre tout le catalogue, même les permissions absentes du rôle édité.
        foreach (array_keys($this->definitions) as $permission) {
            $this->resolve($permission, []);
        }
    }

    /** @return list<string> Prérequis transitifs, sans la permission elle-même. */
    public function forPermission(string $permission): array
    {
        if (!isset($this->requirements[$permission])) {
            throw new TechException('Permission inconnue dans le catalogue : ' . $permission);
        }

        return $this->requirements[$permission];
    }

    /**
     * Les anciennes permissions d’extensions absentes peuvent être conservées,
     * mais ne satisfont que leur propre nom : aucun prérequis n’est inventé.
     * @param list<string> $permissions
     * @return array<string, list<string>> Prérequis manquants par permission.
     */
    public function missingFrom(array $permissions): array
    {
        $selected = array_fill_keys($permissions, true);
        $missing = [];
        foreach ($permissions as $permission) {
            $required = array_values(array_filter($this->requirements[$permission] ?? [],
                static fn (string $name): bool => !isset($selected[$name])));
            if ($required !== []) $missing[$permission] = $required;
        }

        return $missing;
    }

    /** @param list<string> $path @return list<string> */
    private function resolve(string $permission, array $path): array
    {
        if (isset($this->requirements[$permission])) return $this->requirements[$permission];

        if (!isset($this->definitions[$permission])) {
            throw new TechException('Prérequis de permission inconnu : ' . $permission);
        }
        if (in_array($permission, $path, true)) {
            throw new TechException('Cycle de prérequis de permissions : ' . implode(' → ', [...$path, $permission]));
        }

        // Une résolution terminée seulement est mise en cache ; un cycle ne passe pas par un résultat partiel.
        $required = [];
        foreach ($this->definitions[$permission]->requiresPermissions as $dependency) {
            $required[$dependency] = true;
            foreach ($this->resolve($dependency, [...$path, $permission]) as $ancestor) {
                $required[$ancestor] = true;
            }
        }

        $names = array_keys($required);
        sort($names);

        return $this->requirements[$permission] = $names;
    }
}
