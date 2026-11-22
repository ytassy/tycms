<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use Google\Service\Sheets\ValueRange;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;

final class GoogleSheetsAppendRowAction extends GoogleSheetsAction
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
            $values = $this->rowValues($params['values'] ?? null, $context);
            $input = $this->client()->valueInputOption($params['value_input_option'] ?? null);
            $response = $this->client()->service($context->spaceId)->spreadsheets_values->append(
                $spreadsheetId,
                $range,
                new ValueRange(['values' => [$values]]),
                ['valueInputOption' => $input, 'insertDataOption' => 'INSERT_ROWS'],
            );

            return (int) ($response->getUpdates()?->getUpdatedRows() ?? 1);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
