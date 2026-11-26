<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Job;

/**
 * Résultat d'un handler.
 *
 * Un échec retourné est un échec métier : le worker décide de rejouer ou non
 * selon les tentatives restantes. Une exception levée par le handler est
 * traitée comme un échec technique, journalisée et comptée de la même façon.
 */
final class JobResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $message,
    ) {
    }

    public static function success(string $message = ''): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message = ''): self
    {
        return new self(false, $message);
    }
}
