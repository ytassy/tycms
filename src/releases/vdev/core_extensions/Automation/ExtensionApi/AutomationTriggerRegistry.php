<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

use YTassy\Tycms\BaseApplicationItem;

/** Registre explicite des déclencheurs fournis par les extensions. */
final class AutomationTriggerRegistry extends BaseApplicationItem
{
    /** @var array<string, AutomationTriggerDefinition> */
    private array $triggers = [];

    /** @var array<string, string> */
    private array $owners = [];

    private ?string $ownerId = null;

    public function registerProvider(string $ownerId, IAutomationProvider $provider): void
    {
        $this->ownerId = $ownerId;

        try {
            $provider->coreOnRegisterAutomationTriggers($this);
        } finally {
            $this->ownerId = null;
        }
    }

    public function register(AutomationTriggerDefinition $definition): void
    {
        if ($this->ownerId === null) {
            throw new \LogicException('Un déclencheur doit être enregistré par un IAutomationProvider.');
        }

        if (preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}\z/', $definition->id) !== 1) {
            throw new \LogicException('Identifiant de déclencheur invalide : ' . $definition->id);
        }

        if (array_key_exists($definition->id, $this->triggers)) {
            throw new \LogicException('Déclencheur d’automation déjà enregistré : ' . $definition->id);
        }

        if (!in_array($definition->defaultPrincipalMode, [
            AutomationPrincipalMode::TRIGGER,
            AutomationPrincipalMode::AUTHOR,
            AutomationPrincipalMode::SERVICE,
        ], true)) {
            throw new \LogicException('Mode de principal invalide pour « ' . $definition->id . ' ».');
        }

        $this->triggers[$definition->id] = $definition;
        $this->owners[$definition->id] = $this->ownerId;
    }

    public function find(string $id): ?AutomationTriggerDefinition
    {
        return $this->triggers[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->triggers);
    }

    /** @return array<string, AutomationTriggerDefinition> */
    public function all(): array
    {
        return $this->triggers;
    }

    public function ownerOf(string $id): ?string
    {
        return $this->owners[$id] ?? null;
    }
}
