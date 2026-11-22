<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\ActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;

final class RecordingActionDispatcher implements ActionDispatcher
{
    /** @var list<array{identifier: string, arguments: array<mixed>}> */
    public array $calls = [];

    /** @param array<string, mixed> $results */
    public function __construct(private readonly array $results = [])
    {
    }

    public function dispatch(
        string $identifier,
        array $arguments,
        SourceSpan $span,
        RuntimeServices $services,
    ): mixed {
        $this->calls[] = ['identifier' => $identifier, 'arguments' => $arguments];

        return $this->results[$identifier] ?? null;
    }
}
