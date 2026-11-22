<?php

namespace YTassy\Tycms\Session;

use YTassy\Tycms\Application;

/**
 * Session en mémoire utilisée pour isoler une exécution de run.
 *
 * Elle ne démarre rien, n'écrit ni en base ni dans la session PHP et ne sert
 * qu'à porter le principal et le Space courant dans une Application dédiée.
 */
final class RunSession extends Session
{
    /** @var array<string, string|array|object|int|float|bool|null> */
    private array $data = [];

    public function __construct(Application $app, ?int $userId = null, ?int $spaceId = null)
    {
        parent::__construct($app);

        $this->setUser($userId);
        $this->setSpaceId($spaceId);
    }

    public function initialize(): void
    {
    }

    public function destroy(): void
    {
        $this->data = [];
    }

    public function rotateId(): void
    {
    }

    public function get(
        string $key,
        string|array|object|int|float|bool|null $defaultValue = null,
    ): string|array|object|int|float|bool|null {
        return $this->data[$key] ?? $defaultValue;
    }

    public function set(string $key, string|array|object|int|float|bool|null $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}
