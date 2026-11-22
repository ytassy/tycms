<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Support;

use Google\Service\Sheets;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\Response\ExceptionResponse;

/**
 * Client Google Sheets lié au compte de service configuré pour un Space.
 *
 * Le JSON du compte de service est chiffré en base et n'est jamais journalisé.
 * Les actions ne reçoivent que leurs paramètres métier.
 */
final class GoogleSheetsClient extends BaseHelper
{
    private const HTTP_TIMEOUT_SECONDS = 15;

    /** @var array<int, Sheets> */
    private array $services = [];

    /** @var array<int, array<string, mixed>> */
    private array $configs = [];

    public function service(int $spaceId): Sheets
    {
        if (isset($this->services[$spaceId])) {
            return $this->services[$spaceId];
        }

        $config = $this->config($spaceId);
        $client = new \Google\Client();
        $client->setApplicationName('TyCMS Automation');
        $client->setAuthConfig($config);
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAccessType('offline');
        $client->setHttpClient(new \GuzzleHttp\Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'connect_timeout' => 5,
        ]));

        return $this->services[$spaceId] = new Sheets($client);
    }

    /** @return array<string, mixed> */
    public function config(int $spaceId): array
    {
        if (isset($this->configs[$spaceId])) {
            return $this->configs[$spaceId];
        }

        $row = $this->db->selectOneOrNull(
            'SELECT params FROM config WHERE space_id = % AND extension = % LIMIT 1',
            [$spaceId, 'plg_automation'],
        );
        $params = [];

        if ($row !== null) {
            $decoded = json_decode((string) $row->params, true);
            $params = is_array($decoded) ? $decoded : [];
        }

        $encrypted = $params[GoogleSheetsConfig::FIELD] ?? '';

        if (!is_string($encrypted) || trim($encrypted) === '') {
            ExceptionResponse::throwWarning(
                'Aucune configuration Google Sheets n’est enregistrée pour cet espace.',
            );
        }

        try {
            $json = $this->getSharedExtensionsClass(CryptHelper::class)->decrypt(
                $encrypted,
                GoogleSheetsConfig::PURPOSE,
            );
            $config = GoogleSheetsConfig::validateJson($json);
        } catch (\Throwable $exception) {
            $this->logWarning(
                'La configuration Google Sheets est illisible.',
                ['space_id' => $spaceId],
                'GOOGLE_SHEETS_CONFIG_INVALID',
                'automation',
            );
            $this->logException($exception);

            ExceptionResponse::throwWarning('La configuration Google Sheets est invalide.');
        }

        return $this->configs[$spaceId] = $config;
    }

    public function sheetTitle(int $spaceId, string $spreadsheetId, int|string $sheetId): string
    {
        $this->assertSpreadsheetId($spreadsheetId);

        if (is_string($sheetId) && !ctype_digit($sheetId)) {
            $title = trim($sheetId);
        } else {
            $numericId = (int) $sheetId;
            $title = '';
            $spreadsheet = $this->service($spaceId)->spreadsheets->get(
                $spreadsheetId,
                ['fields' => 'sheets.properties'],
            );

            foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
                $properties = $sheet->getProperties();

                if ($properties !== null && (int) $properties->getSheetId() === $numericId) {
                    $title = (string) $properties->getTitle();
                    break;
                }
            }
        }

        if ($title === '') {
            ExceptionResponse::throwWarning('Feuille Google introuvable.');
        }

        return $title;
    }

    public function sheetNumericId(int $spaceId, string $spreadsheetId, int|string $sheetId): int
    {
        $this->assertSpreadsheetId($spreadsheetId);

        if (is_int($sheetId) || (is_string($sheetId) && ctype_digit($sheetId))) {
            return (int) $sheetId;
        }

        $title = trim((string) $sheetId);

        if ($title === '') {
            ExceptionResponse::throwWarning('Feuille Google introuvable.');
        }

        $spreadsheet = $this->service($spaceId)->spreadsheets->get(
            $spreadsheetId,
            ['fields' => 'sheets.properties'],
        );

        foreach ($spreadsheet->getSheets() ?? [] as $sheet) {
            $properties = $sheet->getProperties();

            if ($properties !== null && (string) $properties->getTitle() === $title) {
                return (int) $properties->getSheetId();
            }
        }

        ExceptionResponse::throwWarning('Feuille Google introuvable.');
    }

    public function rangeFor(
        int $spaceId,
        string $spreadsheetId,
        int|string|null $sheetId,
        ?string $range,
        bool $requireRange,
    ): string {
        $this->assertSpreadsheetId($spreadsheetId);
        $range = $range !== null ? trim($range) : '';

        if ($range !== '') {
            if (str_contains($range, '!') || str_starts_with($range, "'")) {
                return $range;
            }

            if ($sheetId !== null && (string) $sheetId !== '') {
                return $this->quoteSheet($this->sheetTitle($spaceId, $spreadsheetId, $sheetId))
                    . '!' . $range;
            }

            return $range;
        }

        if ($requireRange) {
            ExceptionResponse::throwWarning('La plage Google Sheets est obligatoire.');
        }

        if ($sheetId === null || (string) $sheetId === '') {
            ExceptionResponse::throwWarning('Indiquez une feuille ou une plage Google Sheets.');
        }

        return $this->quoteSheet($this->sheetTitle($spaceId, $spreadsheetId, $sheetId));
    }

    public function valueInputOption(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'RAW';
        }

        if (!is_string($value) || !in_array($value, ['RAW', 'USER_ENTERED'], true)) {
            ExceptionResponse::throwWarning('Option de saisie Google Sheets invalide.');
        }

        return $value;
    }

    public function valueRenderOption(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'FORMATTED_VALUE';
        }

        if (!is_string($value) || !in_array($value, ['FORMATTED_VALUE', 'UNFORMATTED_VALUE', 'FORMULA'], true)) {
            ExceptionResponse::throwWarning('Option de rendu Google Sheets invalide.');
        }

        return $value;
    }

    public function assertSpreadsheetId(string $spreadsheetId): void
    {
        if (preg_match('/\A[A-Za-z0-9_-]{10,200}\z/', $spreadsheetId) !== 1) {
            ExceptionResponse::throwWarning('Identifiant de classeur Google invalide.');
        }
    }

    public function columnIndex(string $column): int
    {
        $column = strtoupper(trim($column));

        if ($column === '' || preg_match('/\A[A-Z]+\z/', $column) !== 1) {
            ExceptionResponse::throwWarning('Colonne Google invalide ; utilisez une lettre, par exemple A.');
        }

        $index = 0;

        foreach (str_split($column) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    public function cellString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return (string) json_encode($value, JSON_UNESCAPED_UNICODE);
    }

    private function quoteSheet(string $title): string
    {
        return "'" . str_replace("'", "''", $title) . "'";
    }
}
