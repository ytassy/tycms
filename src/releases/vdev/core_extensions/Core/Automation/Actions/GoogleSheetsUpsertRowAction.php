<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use Google\Service\Sheets\ValueRange;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\Response\ExceptionResponse;

final class GoogleSheetsUpsertRowAction extends GoogleSheetsAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $spreadsheetId = $this->spreadsheetId($params, $context);
            $sheetId = $this->sheetId($params, $context);

            if ($sheetId === null) {
                ExceptionResponse::throwWarning('La feuille Google est obligatoire pour upsertRow.');
            }

            $keyColumn = ValueTools::assertString($params['key_column'] ?? null, $context->span);
            $keyIndex = $this->client()->columnIndex($keyColumn);
            $keyValue = $this->client()->cellString($params['key_value'] ?? null);
            $values = $this->rowValues($params['values'] ?? null, $context);
            $input = $this->client()->valueInputOption($params['value_input_option'] ?? null);
            $service = $this->client()->service($context->spaceId);
            $sheetRange = $this->client()->rangeFor(
                $context->spaceId,
                $spreadsheetId,
                $sheetId,
                null,
                false,
            );
            $render = $this->client()->valueRenderOption($params['value_render_option'] ?? null);
            $existing = $service->spreadsheets_values->get(
                $spreadsheetId,
                $sheetRange,
                ['valueRenderOption' => $render, 'majorDimension' => 'ROWS'],
            );
            $rowNumber = null;

            foreach ($existing->getValues() ?? [] as $index => $row) {
                if (is_array($row)
                    && $this->client()->cellString($row[$keyIndex] ?? null) === $keyValue) {
                    $rowNumber = $index + 1;
                    break;
                }
            }

            if ($rowNumber !== null) {
                $updateRange = $this->client()->rangeFor(
                    $context->spaceId,
                    $spreadsheetId,
                    $sheetId,
                    'A' . $rowNumber,
                    true,
                );
                $service->spreadsheets_values->update(
                    $spreadsheetId,
                    $updateRange,
                    new ValueRange(['values' => [$values]]),
                    ['valueInputOption' => $input],
                );

                return ['action' => 'updated', 'row' => $rowNumber];
            }

            $service->spreadsheets_values->append(
                $spreadsheetId,
                $sheetRange,
                new ValueRange(['values' => [$values]]),
                ['valueInputOption' => $input, 'insertDataOption' => 'INSERT_ROWS'],
            );

            return ['action' => 'appended', 'row' => null];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
