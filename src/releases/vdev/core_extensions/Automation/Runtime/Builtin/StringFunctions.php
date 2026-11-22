<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime\Builtin;

use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class StringFunctions
{
    /** @return array<string, callable(array<mixed>, RuntimeServices, SourceSpan): mixed> */
    public function definitions(): array
    {
        return [
            'str::length' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->length($args, $span),
            'str::byteLength' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => strlen(Args::string($args, 0, $span)),
            'str::lower' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => mb_strtolower(Args::string($args, 0, $span), 'UTF-8'),
            'str::upper' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => mb_strtoupper(Args::string($args, 0, $span), 'UTF-8'),
            'str::trim' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => trim(
                Args::string($args, 0, $span),
                Args::optionalString($args, 1, $span, " \t\n\r\0\x0B") ?? " \t\n\r\0\x0B",
            ),
            'str::trimLeft' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => ltrim(
                Args::string($args, 0, $span),
                Args::optionalString($args, 1, $span, " \t\n\r\0\x0B") ?? " \t\n\r\0\x0B",
            ),
            'str::trimRight' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => rtrim(
                Args::string($args, 0, $span),
                Args::optionalString($args, 1, $span, " \t\n\r\0\x0B") ?? " \t\n\r\0\x0B",
            ),
            'str::startsWith' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => str_starts_with(
                Args::string($args, 0, $span),
                Args::string($args, 1, $span),
            ),
            'str::endsWith' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => str_ends_with(
                Args::string($args, 0, $span),
                Args::string($args, 1, $span),
            ),
            'str::contains' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => str_contains(
                Args::string($args, 0, $span),
                Args::string($args, 1, $span),
            ),
            'str::indexOf' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->indexOf($args, $span),
            'str::compare' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => strcmp(
                Args::string($args, 0, $span),
                Args::string($args, 1, $span),
            ) <=> 0,
            'str::replace' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => str_replace(
                Args::string($args, 1, $span),
                Args::string($args, 2, $span),
                Args::string($args, 0, $span),
            ),
            'str::split' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->split($args, $span),
            'str::join' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->join($args, $span),
            'str::substr' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => mb_substr(
                Args::string($args, 0, $span),
                Args::int($args, 1, $span),
                Args::optionalInt($args, 2, $span),
                'UTF-8',
            ),
            'str::repeat' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->repeat($args, $span),
            'str::padLeft' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => str_pad(
                Args::string($args, 0, $span),
                Args::int($args, 1, $span),
                Args::optionalString($args, 2, $span, ' ') ?? ' ',
                STR_PAD_LEFT,
            ),
            'str::padRight' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => str_pad(
                Args::string($args, 0, $span),
                Args::int($args, 1, $span),
                Args::optionalString($args, 2, $span, ' ') ?? ' ',
                STR_PAD_RIGHT,
            ),
            'str::truncate' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->truncate($args, $span),
            'str::capitalize' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->capitalize($args, $span),
            'str::slug' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => $this->slug($args, $span),
            'str::isBlank' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => trim(Args::string($args, 0, $span)) === '',
            'str::escapeHtml' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => htmlspecialchars(
                Args::string($args, 0, $span),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ),
            'str::escapeAttr' => fn (array $args, RuntimeServices $services, SourceSpan $span): mixed => htmlspecialchars(
                Args::string($args, 0, $span),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8',
            ),
        ];
    }

    /** @param array<mixed> $args */
    private function length(array $args, SourceSpan $span): int
    {
        return mb_strlen(Args::string($args, 0, $span), 'UTF-8');
    }

    /** @param array<mixed> $args */
    private function indexOf(array $args, SourceSpan $span): int
    {
        $position = mb_strpos(
            Args::string($args, 0, $span),
            Args::string($args, 1, $span),
            0,
            'UTF-8',
        );

        return $position === false ? -1 : $position;
    }

    /** @param array<mixed> $args */
    private function split(array $args, SourceSpan $span): array
    {
        $separator = Args::string($args, 1, $span);

        if ($separator === '') {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Le séparateur de str::split ne peut pas être vide.',
                $span,
            );
        }

        return explode($separator, Args::string($args, 0, $span));
    }

    /** @param array<mixed> $args */
    private function join(array $args, SourceSpan $span): string
    {
        $items = Args::array($args, 0, $span);
        $separator = Args::string($args, 1, $span);
        $strings = [];

        foreach ($items as $item) {
            $strings[] = ValueTools::toConcatString($item, $span);
        }

        return implode($separator, $strings);
    }

    /** @param array<mixed> $args */
    private function repeat(array $args, SourceSpan $span): string
    {
        $times = Args::int($args, 1, $span);

        if ($times < 0) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'Le nombre de répétitions doit être positif.', $span);
        }

        return str_repeat(Args::string($args, 0, $span), $times);
    }

    /** @param array<mixed> $args */
    private function truncate(array $args, SourceSpan $span): string
    {
        $value = Args::string($args, 0, $span);
        $length = Args::int($args, 1, $span);
        $suffix = Args::optionalString($args, 2, $span, '…') ?? '…';

        if ($length < 0) {
            throw ValueTools::error(DiagnosticCode::INVALID_ARGUMENT, 'La longueur de troncature doit être positive.', $span);
        }

        if (mb_strlen($value, 'UTF-8') <= $length) {
            return $value;
        }

        $keep = max(0, $length - mb_strlen($suffix, 'UTF-8'));

        return mb_substr($value, 0, $keep, 'UTF-8') . $suffix;
    }

    /** @param array<mixed> $args */
    private function capitalize(array $args, SourceSpan $span): string
    {
        $value = Args::string($args, 0, $span);

        if ($value === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($value, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($value, 1, null, 'UTF-8');
    }

    /** @param array<mixed> $args */
    private function slug(array $args, SourceSpan $span): string
    {
        $value = Args::string($args, 0, $span);
        $ascii = function_exists('iconv')
            ? @iconv('UTF-8', 'ASCII//TRANSLIT', $value)
            : false;
        $source = $ascii === false ? $value : $ascii;
        $slug = preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($source, 'UTF-8')) ?? '';

        return trim($slug, '-');
    }
}
