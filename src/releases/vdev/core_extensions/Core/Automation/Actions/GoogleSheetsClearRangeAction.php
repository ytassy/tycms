<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use Google\Service\Sheets\ClearValuesRequest;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class GoogleSheetsClearRangeAction extends GoogleSheetsAction
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
            $this->client()->service($context->spaceId)->spreadsheets_values->clear(
                $spreadsheetId,
                $range,
                new ClearValuesRequest(),
            );

            return true;
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
