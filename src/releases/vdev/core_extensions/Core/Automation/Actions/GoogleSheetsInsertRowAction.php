<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\CellData;
use Google\Service\Sheets\DimensionRange;
use Google\Service\Sheets\ExtendedValue;
use Google\Service\Sheets\GridCoordinate;
use Google\Service\Sheets\InsertDimensionRequest;
use Google\Service\Sheets\Request as SheetsRequest;
use Google\Service\Sheets\RowData;
use Google\Service\Sheets\UpdateCellsRequest;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\Response\ExceptionResponse;

final class GoogleSheetsInsertRowAction extends GoogleSheetsAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $spreadsheetId = $this->spreadsheetId($params, $context);
            $sheetId = $this->sheetId($params, $context);

            if ($sheetId === null) {
                ExceptionResponse::throwWarning('La feuille Google est obligatoire pour insérer une ligne.');
            }

            $rowNumber = $this->requiredInt($params['row_index'] ?? null, $context);

            if ($rowNumber < 1 || $rowNumber > 1_000_000) {
                ExceptionResponse::throwWarning('La position de ligne doit être comprise entre 1 et 1 000 000.');
            }

            $values = $this->rowValues($params['values'] ?? null, $context);
            $numericSheetId = $this->client()->sheetNumericId(
                $context->spaceId,
                $spreadsheetId,
                $sheetId,
            );
            $zeroBasedRow = $rowNumber - 1;
            $dimension = new DimensionRange([
                'sheetId' => $numericSheetId,
                'dimension' => 'ROWS',
                'startIndex' => $zeroBasedRow,
                'endIndex' => $zeroBasedRow + 1,
            ]);
            $insert = new InsertDimensionRequest([
                'range' => $dimension,
                'inheritFromBefore' => false,
            ]);
            $cells = [];

            foreach ($values as $value) {
                $extended = new ExtendedValue();

                if (is_bool($value)) {
                    $extended->setBoolValue($value);
                } elseif (is_int($value) || is_float($value)) {
                    $extended->setNumberValue($value);
                } elseif (is_string($value)) {
                    // Les chaînes restent littérales : pas d'interprétation de formule.
                    $extended->setStringValue($value);
                }

                $cells[] = new CellData(['userEnteredValue' => $extended]);
            }

            $update = new UpdateCellsRequest([
                'rows' => [new RowData(['values' => $cells])],
                'fields' => '*',
                'start' => new GridCoordinate([
                    'sheetId' => $numericSheetId,
                    'rowIndex' => $zeroBasedRow,
                    'columnIndex' => 0,
                ]),
            ]);
            $batch = new BatchUpdateSpreadsheetRequest([
                'requests' => [
                    new SheetsRequest(['insertDimension' => $insert]),
                    new SheetsRequest(['updateCells' => $update]),
                ],
            ]);
            $this->client()->service($context->spaceId)->spreadsheets->batchUpdate(
                $spreadsheetId,
                $batch,
            );

            return true;
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
