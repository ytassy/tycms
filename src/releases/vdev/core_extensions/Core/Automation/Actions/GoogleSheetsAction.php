<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\Automation\Support\GoogleSheetsClient;

/** Base commune des actions Google Sheets. */
abstract class GoogleSheetsAction extends GoogleApiAction
{
    protected function client(): GoogleSheetsClient
    {
        /** @var GoogleSheetsClient $client */
        $client = $this->getSharedExtensionsClass(GoogleSheetsClient::class);

        return $client;
    }

    /** @param array<string, mixed> $params */
    protected function spreadsheetId(array $params, ActionContext $context): string
    {
        $spreadsheetId = ValueTools::assertString($params['spreadsheet_id'] ?? null, $context->span);
        $this->client()->assertSpreadsheetId($spreadsheetId);

        return $spreadsheetId;
    }

    /** @param array<string, mixed> $params */
    protected function sheetId(array $params, ActionContext $context): int|string|null
    {
        $sheetId = $params['sheet_id'] ?? null;

        if ($sheetId === null || $sheetId === '') {
            return null;
        }

        if (!is_int($sheetId) && !is_string($sheetId)) {
            throw ValueTools::typeError('int|string', $sheetId, $context->span);
        }

        return $sheetId;
    }

    protected function optionalString(mixed $value, ActionContext $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ValueTools::assertString($value, $context->span);
    }

    protected function requiredInt(mixed $value, ActionContext $context): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        return ValueTools::assertInt($value, $context->span);
    }

    protected function optionalInt(
        mixed $value,
        ActionContext $context,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        if ($value === null || $value === '') {
            return $default;
        }

        $integer = $this->requiredInt($value, $context);

        if ($integer < $minimum || $integer > $maximum) {
            throw ValueTools::typeError('int', $integer, $context->span);
        }

        return $integer;
    }

    /** @param array<string, mixed> $params */
    protected function rowValues(mixed $value, ActionContext $context): array
    {
        $row = ValueTools::assertArray($value, $context->span);
        $row = array_values($row);

        foreach ($row as $cell) {
            if (!is_scalar($cell) && $cell !== null) {
                throw ValueTools::typeError('scalar', $cell, $context->span);
            }
        }

        return $row;
    }

    protected function multipleRows(mixed $value, ActionContext $context): array
    {
        $rows = ValueTools::assertArray($value, $context->span);
        $result = [];

        foreach ($rows as $row) {
            $result[] = $this->rowValues($row, $context);
        }

        return $result;
    }

    /** Accepte une ligne seule ou une liste de lignes. */
    protected function flexibleRows(mixed $value, ActionContext $context): array
    {
        $rows = ValueTools::assertArray($value, $context->span);

        if ($rows === []) {
            return [];
        }

        $first = reset($rows);

        return is_array($first)
            ? $this->multipleRows($rows, $context)
            : [$this->rowValues($rows, $context)];
    }
}
