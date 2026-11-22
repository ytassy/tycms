<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\Response\ExceptionResponse;

final class GoogleSheetsFindRowsAction extends GoogleSheetsAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $spreadsheetId = $this->spreadsheetId($params, $context);
            $sheetId = $this->sheetId($params, $context);

            if ($sheetId === null) {
                ExceptionResponse::throwWarning('La feuille Google est obligatoire pour la recherche.');
            }

            $keyColumn = ValueTools::assertString($params['key_column'] ?? null, $context->span);
            $keyIndex = $this->client()->columnIndex($keyColumn);
            $keyValue = $this->client()->cellString($params['key_value'] ?? null);
            $limit = $this->optionalInt($params['limit'] ?? null, $context, 50, 1, 500);
            $range = $this->client()->rangeFor(
                $context->spaceId,
                $spreadsheetId,
                $sheetId,
                null,
                false,
            );
            $render = $this->client()->valueRenderOption($params['value_render_option'] ?? null);
            $response = $this->client()->service($context->spaceId)->spreadsheets_values->get(
                $spreadsheetId,
                $range,
                ['valueRenderOption' => $render, 'majorDimension' => 'ROWS'],
            );
            $result = [];

            foreach ($response->getValues() ?? [] as $index => $row) {
                if (!is_array($row)) {
                    continue;
                }

                if ($this->client()->cellString($row[$keyIndex] ?? null) !== $keyValue) {
                    continue;
                }

                $result[] = ['row' => $index + 1, 'values' => array_values($row)];

                if (count($result) >= $limit) {
                    break;
                }
            }

            return $result;
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
