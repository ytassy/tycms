<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Cron;

/**
 * Instant d'un tick CRON, partagé par tous les hooks d'une même requête.
 *
 * Les fréquences 15 minutes et horaire sont calculées sur l'heure du serveur.
 * Une extension qui a besoin du fuseau d'un Space le résout elle-même.
 */
final readonly class CronTick
{
    public function __construct(public \DateTimeImmutable $now)
    {
    }

    public static function now(): self
    {
        return new self(new \DateTimeImmutable('now'));
    }

    public static function fromDateTime(\DateTimeImmutable $now): self
    {
        return new self($now);
    }

    public function minute(): int
    {
        return (int) $this->now->format('i');
    }

    public function hour(): int
    {
        return (int) $this->now->format('G');
    }

    public function isQuarterHour(): bool
    {
        return $this->minute() % 15 === 0;
    }

    public function isHour(): bool
    {
        return $this->minute() === 0;
    }

    public function iso(): string
    {
        return $this->now->format(DATE_ATOM);
    }
}
