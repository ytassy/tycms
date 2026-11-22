<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Page;

use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Lib\Forms\FormJson;

/** Données JSON d'édition, collectées côté serveur et isolées par Space. */
final class PageEditContext
{
    private array $data = [];

    public function __construct(public readonly int $spaceId) {}

    public function add(string $owner, array $values): void
    {
        foreach ($values as $key => $value) {
            if (!is_string($key) || !str_starts_with($key, $owner . '_')
                || preg_match('/^[a-z][a-z0-9_]*$/D', $key) !== 1 || array_key_exists($key, $this->data)) {
                throw new TechException('Clé de contexte de page invalide ou répétée : ' . (string) $key);
            }
            FormJson::assertValue($value, 'page context.' . $key);
            $this->data[$key] = $value;
        }
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function toArray(): array
    {
        return $this->data;
    }
}
