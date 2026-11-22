<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Environment;

final class Environment
{
    /** @var array<string, array{value: mixed, readOnly: bool}> */
    private array $variables = [];

    public function __construct(private readonly ?self $parent = null)
    {
    }

    public function define(string $name, mixed $value, bool $readOnly = false): void
    {
        if (array_key_exists($name, $this->variables)) {
            throw new \LogicException('Variable déjà définie dans cette portée : $' . $name);
        }

        $this->variables[$name] = ['value' => $value, 'readOnly' => $readOnly];
    }

    public function defineReadOnly(string $name, mixed $value): void
    {
        $this->define($name, $value, true);
    }

    public function get(string $name): mixed
    {
        if (array_key_exists($name, $this->variables)) {
            return $this->variables[$name]['value'];
        }

        if ($this->parent !== null) {
            return $this->parent->get($name);
        }

        throw new UndefinedVariableException($name);
    }

    public function assign(string $name, mixed $value): void
    {
        // Une affectation met à jour la variable existante la plus proche.
        // Une variable nouvelle reste dans le bloc courant : elle ne remonte
        // jamais jusqu'au contexte global, sinon un helper verrait les locales
        // de son appelant.
        if ($this->contains($name)) {
            $this->updateExisting($name, $value);

            return;
        }

        $this->define($name, $value);
    }

    public function has(string $name): bool
    {
        return $this->contains($name);
    }

    private function contains(string $name): bool
    {
        return array_key_exists($name, $this->variables)
            || ($this->parent?->contains($name) ?? false);
    }

    private function updateExisting(string $name, mixed $value): void
    {
        if (array_key_exists($name, $this->variables)) {
            if ($this->variables[$name]['readOnly']) {
                throw new ReadOnlyVariableException($name);
            }

            $this->variables[$name]['value'] = $value;

            return;
        }

        if ($this->parent !== null) {
            $this->parent->updateExisting($name, $value);

            return;
        }

        throw new \LogicException('Variable existante introuvable : $' . $name);
    }
}
