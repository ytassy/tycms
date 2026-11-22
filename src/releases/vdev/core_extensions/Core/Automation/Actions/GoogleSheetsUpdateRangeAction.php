<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use Google\Service\Sheets\ValueRange;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class GoogleSheetsUpdateRangeAction extends GoogleSheetsAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $spreadsheetId = $this->spreadsheetId($params, $context);
            $sheetId = $this->sheetId($params, $context);
            $range = ValueTools::assertString($params['range'] ?? null, $context->span);
            $range = $this->client()->rangeFor(
                $context->spaceId,
                $spreadsheetId,
                $sheetId,
                $range,
                true,
            );
            $rows = $this->flexibleRows($params['values'] ?? null, $context);
            $input = $this->client()->valueInputOption($params['value_input_option'] ?? null);
            $response = $this->client()->service($context->spaceId)->spreadsheets_values->update(
                $spreadsheetId,
                $range,
                new ValueRange(['values' => $rows]),
                ['valueInputOption' => $input],
            );

            return (int) ($response->getUpdatedRows() ?? count($rows));
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
