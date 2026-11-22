<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use Google\Service\Sheets\ValueRange;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\Response\ExceptionResponse;

final class GoogleSheetsAppendRowsAction extends GoogleSheetsAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $spreadsheetId = $this->spreadsheetId($params, $context);
            $sheetId = $this->sheetId($params, $context);
            $range = $this->optionalString($params['range'] ?? null, $context);
            $range = $this->client()->rangeFor(
                $context->spaceId,
                $spreadsheetId,
                $sheetId,
                $range,
                false,
            );
            $rows = $this->multipleRows($params['rows'] ?? null, $context);

            if ($rows === []) {
                ExceptionResponse::throwWarning('Aucune ligne Google Sheets à ajouter.');
            }

            $input = $this->client()->valueInputOption($params['value_input_option'] ?? null);
            $response = $this->client()->service($context->spaceId)->spreadsheets_values->append(
                $spreadsheetId,
                $range,
                new ValueRange(['values' => $rows]),
                ['valueInputOption' => $input, 'insertDataOption' => 'INSERT_ROWS'],
            );

            return (int) ($response->getUpdates()?->getUpdatedRows() ?? count($rows));
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
