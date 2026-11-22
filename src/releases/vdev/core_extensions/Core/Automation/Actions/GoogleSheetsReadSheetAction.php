<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\Response\ExceptionResponse;

final class GoogleSheetsReadSheetAction extends GoogleSheetsAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $spreadsheetId = $this->spreadsheetId($params, $context);
            $sheetId = $this->sheetId($params, $context);

            if ($sheetId === null) {
                ExceptionResponse::throwWarning('La feuille Google est obligatoire pour lire toute la feuille.');
            }

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

            return $response->getValues() ?? [];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
