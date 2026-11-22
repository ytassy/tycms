<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\Automation\Support\GoogleSheetsClient;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class GoogleSheetsClientTest extends FrameworkTestCase
{
    #[Test]
    public function columnLettersAreConvertedToIndexes(): void
    {
        $client = new GoogleSheetsClient($this->createApplication());

        self::assertSame(0, $client->columnIndex('A'));
        self::assertSame(25, $client->columnIndex('Z'));
        self::assertSame(26, $client->columnIndex('AA'));
    }

    #[Test]
    public function invalidColumnIsRejected(): void
    {
        $client = new GoogleSheetsClient($this->createApplication());

        $this->expectException(ExceptionResponse::class);
        $client->columnIndex('1');
    }

    #[Test]
    public function spreadsheetIdIsValidated(): void
    {
        $client = new GoogleSheetsClient($this->createApplication());

        $client->assertSpreadsheetId('1AbCdEfGhIjKlMnOpQrStUvWxYz123456789');
        self::assertTrue(true);

        $this->expectException(ExceptionResponse::class);
        $client->assertSpreadsheetId('../../etc/passwd');
    }

    #[Test]
    public function inputAndRenderOptionsHaveSafeDefaults(): void
    {
        $client = new GoogleSheetsClient($this->createApplication());

        self::assertSame('RAW', $client->valueInputOption(null));
        self::assertSame('USER_ENTERED', $client->valueInputOption('USER_ENTERED'));
        self::assertSame('FORMATTED_VALUE', $client->valueRenderOption(null));
        self::assertSame('UNFORMATTED_VALUE', $client->valueRenderOption('UNFORMATTED_VALUE'));
        self::assertSame('1', $client->cellString(true));
        self::assertSame('', $client->cellString(null));
    }
}
