<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;

final class UtilFunctions
{
    /** @return array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    public function definitions(): array
    {
        return [
            'util::log' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->log($args, $services, $span),
            'util::uuid' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $services->context->ids->uuid(),
        ];
    }

    /** @param array<mixed> $args */
    private function log(array $args, RuntimeServices $services, SourceSpan $span): mixed
    {
        $message = Args::string($args, 0, $span);
        $context = array_key_exists(1, $args) && $args[1] !== null
            ? Args::array($args, 1, $span)
            : null;

        $services->journal->add('log', 'util::log', $span, [$message, $context], null);

        return null;
    }
}
