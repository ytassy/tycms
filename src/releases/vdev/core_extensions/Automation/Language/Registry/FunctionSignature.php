<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Registry;

final readonly class FunctionSignature
{
    public function __construct(
        public string $identifier,
        public int $minArity,
        public ?int $maxArity,
        public string $effect = FunctionEffect::PURE,
    ) {
    }

    public function accepts(int $arity): bool
    {
        return $arity >= $this->minArity
            && ($this->maxArity === null || $arity <= $this->maxArity);
    }

    public function describeArity(): string
    {
        if ($this->maxArity === null) {
            return $this->minArity . ' argument(s) ou plus';
        }

        if ($this->minArity === $this->maxArity) {
            return $this->minArity . ' argument(s)';
        }

        return $this->minArity . ' à ' . $this->maxArity . ' arguments';
    }
}
