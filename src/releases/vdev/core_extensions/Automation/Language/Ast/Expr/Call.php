<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Ast\Expr;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;

final readonly class Call extends Expression
{
    /** @param list<Expression> $arguments */
    public function __construct(
        public ?string $namespace,
        public string $name,
        public array $arguments,
        SourceSpan $span,
    ) {
        parent::__construct($span);
    }

    public function isUserFunction(): bool
    {
        return $this->namespace === null;
    }

    public function identifier(): string
    {
        return $this->namespace === null ? $this->name : $this->namespace . '::' . $this->name;
    }
}
