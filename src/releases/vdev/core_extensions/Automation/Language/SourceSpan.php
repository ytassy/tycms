<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language;

/** Intervalle source, début inclus et fin exclue. */
final readonly class SourceSpan
{
    public function __construct(
        public SourcePosition $start,
        public SourcePosition $end,
    ) {
    }

    public static function point(SourcePosition $position): self
    {
        return new self($position, $position);
    }

    public function merge(self $other): self
    {
        $start = $this->start->offset <= $other->start->offset ? $this->start : $other->start;
        $end = $this->end->offset >= $other->end->offset ? $this->end : $other->end;

        return new self($start, $end);
    }

    public function length(): int
    {
        return $this->end->offset - $this->start->offset;
    }
}
