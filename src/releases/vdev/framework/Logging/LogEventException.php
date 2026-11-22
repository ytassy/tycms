<?php

declare(strict_types=1);

namespace YTassy\Tycms\Logging;

/** Raison contrôlée par le code métier, sans données HTTP dans le message. */
final class LogEventException extends \RuntimeException
{
    public function __construct(
        public readonly string $eventCode,
        string $message,
        public readonly string $channel,
        public readonly array $context = [],
        public readonly string $level = 'WARNING',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
