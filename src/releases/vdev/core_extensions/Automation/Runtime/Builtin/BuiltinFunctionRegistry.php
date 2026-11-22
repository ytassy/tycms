<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;

final class BuiltinFunctionRegistry
{
    /** @var array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    private array $functions = [];

    public function __construct()
    {
        foreach ([
            new StringFunctions(),
            new ArrayFunctions(),
            new MathFunctions(),
            new DateFunctions(),
            new JsonFunctions(),
            new TypeFunctions(),
            new UtilFunctions(),
            new ResponseFunctions(),
        ] as $group) {
            foreach ($group->definitions() as $identifier => $callable) {
                $this->register($identifier, $callable);
            }
        }
    }

    /** @param callable(array<mixed>, RuntimeServices, SourceSpan): mixed $callable */
    public function register(string $identifier, callable $callable): void
    {
        if (array_key_exists($identifier, $this->functions)) {
            throw new \LogicException('Fonction intégrée déjà enregistrée : ' . $identifier);
        }

        $this->functions[$identifier] = $callable;
    }

    public function has(string $identifier): bool
    {
        return array_key_exists($identifier, $this->functions);
    }

    /** @param array<mixed> $arguments */
    public function call(
        string $identifier,
        array $arguments,
        RuntimeServices $services,
        SourceSpan $span,
    ): mixed {
        $function = $this->functions[$identifier] ?? null;

        if ($function === null) {
            throw new \LogicException('Fonction intégrée inconnue : ' . $identifier);
        }

        return $function($arguments, $services, $span);
    }

    /** @return list<string> */
    public function identifiers(): array
    {
        return array_keys($this->functions);
    }
}
