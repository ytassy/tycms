<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;

/**
 * Registre explicite des actions fournies par les extensions.
 *
 * Le registre associe chaque action à son extension propriétaire, refuse les
 * collisions et instancie les handlers uniquement au premier appel.
 */
final class AutomationActionRegistry extends BaseApplicationItem
{
    /** @var array<string, AutomationActionDefinition> */
    private array $actions = [];

    /** @var array<string, string> */
    private array $owners = [];

    private ?string $ownerId = null;

    public function registerProvider(string $ownerId, IAutomationProvider $provider): void
    {
        $this->ownerId = $ownerId;

        try {
            $provider->coreOnRegisterAutomationActions($this);
        } finally {
            $this->ownerId = null;
        }
    }

    public function register(AutomationActionDefinition $definition): void
    {
        if ($this->ownerId === null) {
            throw new \LogicException('Une action doit être enregistrée par un IAutomationProvider.');
        }

        $this->assertIdentifier($definition->id);

        if (array_key_exists($definition->id, $this->actions)) {
            throw new \LogicException('Action d’automation déjà enregistrée : ' . $definition->id);
        }

        if (!class_exists($definition->handlerClass)
            || !is_subclass_of($definition->handlerClass, BaseAutomationAction::class)) {
            throw new \LogicException(
                'Le handler de « ' . $definition->id . ' » doit étendre BaseAutomationAction.',
            );
        }

        if (!in_array($definition->effect, [
            FunctionEffect::PURE,
            FunctionEffect::READ,
            FunctionEffect::WRITE,
            FunctionEffect::EXTERNAL,
        ], true)) {
            throw new \LogicException('Classe d’effet invalide pour « ' . $definition->id . ' ».');
        }

        if (!in_array($definition->targetSpace, ['scenario', 'resource'], true)) {
            throw new \LogicException('Portée de Space invalide pour « ' . $definition->id . ' ».');
        }

        $parameterNames = [];

        foreach ($definition->parameters as $parameter) {
            if ($parameter->name === '' || isset($parameterNames[$parameter->name])) {
                throw new \LogicException('Paramètre invalide ou dupliqué pour « ' . $definition->id . ' ».');
            }

            $parameterNames[$parameter->name] = true;
        }

        $this->actions[$definition->id] = $definition;
        $this->owners[$definition->id] = $this->ownerId;
    }

    public function find(string $id): ?AutomationActionDefinition
    {
        return $this->actions[$id] ?? null;
    }

    public function has(string $id): bool
    {
        return array_key_exists($id, $this->actions);
    }

    /** @return array<string, AutomationActionDefinition> */
    public function all(): array
    {
        return $this->actions;
    }

    public function ownerOf(string $id): ?string
    {
        return $this->owners[$id] ?? null;
    }

    public function createHandler(string $id): BaseAutomationAction
    {
        $definition = $this->find($id);

        if ($definition === null) {
            throw new \LogicException('Action d’automation inconnue : ' . $id);
        }

        $handlerClass = $definition->handlerClass;

        /** @var BaseAutomationAction $handler */
        $handler = new $handlerClass($this->app);

        return $handler;
    }

    private function assertIdentifier(string $id): void
    {
        if (preg_match('/\A[a-z][a-z0-9_]*::[A-Za-z][A-Za-z0-9_]*\z/', $id) !== 1) {
            throw new \LogicException('Identifiant d’action invalide : ' . $id);
        }
    }
}
