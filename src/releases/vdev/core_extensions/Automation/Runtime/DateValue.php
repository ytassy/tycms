<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use DateTimeImmutable;
use DateTimeZone;

/** Valeur date du langage, encapsulant un instant et son fuseau. */
final readonly class DateValue
{
    public function __construct(public DateTimeImmutable $value)
    {
    }

    public static function parse(string $value, ?string $timezone = null): self
    {
        $zone = $timezone === null ? null : new DateTimeZone($timezone);

        return new self(new DateTimeImmutable($value, $zone));
    }

    public static function fromTimestamp(int $timestamp, ?string $timezone = null): self
    {
        $zone = $timezone === null ? null : new DateTimeZone($timezone);
        $value = (new DateTimeImmutable('@' . $timestamp))->setTimezone($zone ?? new DateTimeZone(date_default_timezone_get()));

        return new self($value);
    }

    public function toIso(): string
    {
        return $this->value->format(DATE_ATOM);
    }

    public function format(string $pattern, ?string $timezone = null): string
    {
        $value = $timezone === null
            ? $this->value
            : $this->value->setTimezone(new DateTimeZone($timezone));

        return $value->format($pattern);
    }

    public function timestamp(): int
    {
        return $this->value->getTimestamp();
    }

    public function withTimezone(string $timezone): self
    {
        return new self($this->value->setTimezone(new DateTimeZone($timezone)));
    }

    public function timezoneName(): string
    {
        return $this->value->getTimezone()->getName();
    }

    public function compare(self $other): int
    {
        return $this->value <=> $other->value;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    public function diffSeconds(self $other): int
    {
        return $this->timestamp() - $other->timestamp();
    }

    public function isBefore(self $other): bool
    {
        return $this->compare($other) < 0;
    }

    public function isAfter(self $other): bool
    {
        return $this->compare($other) > 0;
    }

    public function isSameDay(self $other): bool
    {
        return $this->value->format('Y-m-d') === $other->value->format('Y-m-d');
    }

    public function modify(string $modifier): self
    {
        $value = $this->value->modify($modifier);

        if ($value === false) {
            throw new \InvalidArgumentException('Modification de date invalide.');
        }

        return new self($value);
    }

    public function startOfDay(): self
    {
        return $this->withTime(0, 0, 0);
    }

    public function endOfDay(): self
    {
        return $this->withTime(23, 59, 59, 999999);
    }

    public function startOfWeek(): self
    {
        return new self($this->value->modify('monday this week')->setTime(0, 0, 0));
    }

    public function startOfMonth(): self
    {
        return new self($this->value->modify('first day of this month')->setTime(0, 0, 0));
    }

    public function endOfMonth(): self
    {
        return new self($this->value->modify('last day of this month')->setTime(23, 59, 59, 999999));
    }

    public function startOfYear(): self
    {
        return new self($this->value->setDate((int) $this->value->format('Y'), 1, 1)->setTime(0, 0, 0));
    }

    public function endOfYear(): self
    {
        return new self($this->value->setDate((int) $this->value->format('Y'), 12, 31)->setTime(23, 59, 59, 999999));
    }

    public function year(): int
    {
        return (int) $this->value->format('Y');
    }

    public function month(): int
    {
        return (int) $this->value->format('m');
    }

    public function day(): int
    {
        return (int) $this->value->format('d');
    }

    public function hour(): int
    {
        return (int) $this->value->format('H');
    }

    public function minute(): int
    {
        return (int) $this->value->format('i');
    }

    public function second(): int
    {
        return (int) $this->value->format('s');
    }

    public function weekday(): int
    {
        return (int) $this->value->format('N');
    }

    public function __toString(): string
    {
        return $this->toIso();
    }

    private function withTime(int $hour, int $minute, int $second, int $microsecond = 0): self
    {
        return new self($this->value->setTime($hour, $minute, $second, $microsecond));
    }
}
