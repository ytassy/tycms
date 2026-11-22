<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class DateFunctions
{
    /** @return array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    public function definitions(): array
    {
        return [
            'date::now' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $services->context->clock->now(),
            'date::today' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $services->context->clock->now()->startOfDay(),
            'date::parse' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->parse($args, $services, $span),
            'date::format' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->format($args, $span),
            'date::toIso' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->toIso(),
            'date::toTimestamp' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->timestamp(),
            'date::fromTimestamp' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->fromTimestamp($args, $services, $span),
            'date::addSeconds' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->add($args, 'seconds', $span),
            'date::addMinutes' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->add($args, 'minutes', $span),
            'date::addHours' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->add($args, 'hours', $span),
            'date::addDays' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->add($args, 'days', $span),
            'date::addWeeks' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->add($args, 'weeks', $span),
            'date::addMonths' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->add($args, 'months', $span),
            'date::addYears' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->add($args, 'years', $span),
            'date::startOfDay' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->startOfDay(),
            'date::endOfDay' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->endOfDay(),
            'date::startOfWeek' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->startOfWeek(),
            'date::startOfMonth' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->startOfMonth(),
            'date::endOfMonth' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->endOfMonth(),
            'date::startOfYear' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->startOfYear(),
            'date::endOfYear' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->endOfYear(),
            'date::diffInSeconds' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->diffSeconds(Args::date($args, 1, $span)),
            'date::diffInDays' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => intdiv(
                Args::date($args, 0, $span)->diffSeconds(Args::date($args, 1, $span)),
                86400,
            ),
            'date::isBefore' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->isBefore(Args::date($args, 1, $span)),
            'date::isAfter' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->isAfter(Args::date($args, 1, $span)),
            'date::isSameDay' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->isSameDay(Args::date($args, 1, $span)),
            'date::year' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->year(),
            'date::month' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->month(),
            'date::day' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->day(),
            'date::hour' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->hour(),
            'date::minute' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->minute(),
            'date::second' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->second(),
            'date::weekday' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->weekday(),
            'date::setTimezone' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->setTimezone($args, $span),
            'date::timezone' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => Args::date($args, 0, $span)->timezoneName(),
        ];
    }

    /** @param array<mixed> $args */
    private function parse(array $args, RuntimeServices $services, SourceSpan $span): DateValue
    {
        $value = Args::string($args, 0, $span);
        $timezone = Args::optionalString($args, 1, $span) ?? $services->context->timezone();

        try {
            return DateValue::parse($value, $timezone);
        } catch (\Throwable) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Date invalide : « ' . $value . ' ».', $span);
        }
    }

    /** @param array<mixed> $args */
    private function format(array $args, SourceSpan $span): string
    {
        $date = Args::date($args, 0, $span);
        $pattern = Args::string($args, 1, $span);

        if (strlen($pattern) > 256) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Patron de date trop long.', $span);
        }

        try {
            return $date->format($pattern, Args::optionalString($args, 2, $span));
        } catch (\Throwable) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Format ou fuseau de date invalide.', $span);
        }
    }

    /** @param array<mixed> $args */
    private function fromTimestamp(array $args, RuntimeServices $services, SourceSpan $span): DateValue
    {
        $timestamp = Args::int($args, 0, $span);
        $timezone = Args::optionalString($args, 1, $span) ?? $services->context->timezone();

        try {
            return DateValue::fromTimestamp($timestamp, $timezone);
        } catch (\Throwable) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Fuseau horaire invalide.', $span);
        }
    }

    /** @param array<mixed> $args */
    private function add(array $args, string $unit, SourceSpan $span): DateValue
    {
        $date = Args::date($args, 0, $span);
        $amount = Args::int($args, 1, $span);
        $modifier = ($amount >= 0 ? '+' : '') . $amount . ' ' . $unit;

        try {
            return $date->modify($modifier);
        } catch (\Throwable) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Modification de date invalide.', $span);
        }
    }

    /** @param array<mixed> $args */
    private function setTimezone(array $args, SourceSpan $span): DateValue
    {
        $date = Args::date($args, 0, $span);
        $timezone = Args::string($args, 1, $span);

        try {
            return $date->withTimezone($timezone);
        } catch (\Throwable) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Fuseau horaire invalide.', $span);
        }
    }
}
