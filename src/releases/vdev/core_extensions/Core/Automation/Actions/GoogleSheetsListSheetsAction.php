<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;

final class GoogleSheetsListSheetsAction extends GoogleSheetsAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $spreadsheetId = $this->spreadsheetId($params, $context);
            $spreadsheet = $this->client()->service($context->spaceId)->spreadsheets->get(
                $spreadsheetId,
                ['fields' => 'sheets.properties'],
            );
            $result = [];

            foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
                $properties = $sheet->getProperties();

                if ($properties === null) {
                    continue;
                }

                $grid = $properties->getGridProperties();
                $result[] = [
                    'id' => (int) $properties->getSheetId(),
                    'title' => (string) $properties->getTitle(),
                    'index' => (int) $properties->getIndex(),
                    'row_count' => $grid !== null ? (int) $grid->getRowCount() : null,
                    'column_count' => $grid !== null ? (int) $grid->getColumnCount() : null,
                ];
            }

            return $result;
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
