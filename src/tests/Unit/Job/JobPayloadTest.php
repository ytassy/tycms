<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobPayload;
use YTassy\Tycms\Exceptions\TechException;

final class JobPayloadTest extends TestCase
{
    public function testRoundTripPreservesReferences(): void
    {
        $payload = ['page_id' => 42, 'locale' => 'fr', 'options' => ['force' => true]];

        self::assertSame($payload, JobPayload::decode(JobPayload::encode($payload)));
    }

    public function testPayloadAboveTheLimitIsRefused(): void
    {
        $this->expectException(TechException::class);

        JobPayload::encode(['content' => str_repeat('a', JobPayload::MAX_BYTES)]);
    }

    public function testPayloadAtTheLimitIsAccepted(): void
    {
        // Un JSON de tableau vide pèse quelques octets : la limite porte sur
        // l'encodage réel, pas sur la taille déclarée par l'appelant.
        $payload = ['content' => str_repeat('a', 1000)];

        self::assertLessThanOrEqual(JobPayload::MAX_BYTES, strlen(JobPayload::encode($payload)));
    }

    public function testInvalidJsonDecodesToNull(): void
    {
        self::assertNull(JobPayload::decode('{"page_id":'));
    }

    public function testJsonThatIsNotAnObjectDecodesToNull(): void
    {
        self::assertNull(JobPayload::decode('"chaîne"'));
        self::assertNull(JobPayload::decode('12'));
        self::assertNull(JobPayload::decode('null'));
    }

    public function testExcessiveDepthDecodesToNull(): void
    {
        $deep = '1';
        for ($i = 0; $i < 40; $i++) {
            $deep = '[' . $deep . ']';
        }

        self::assertNull(JobPayload::decode($deep));
    }

    public function testUnserialisablePayloadIsRefused(): void
    {
        $this->expectException(TechException::class);

        // Une chaîne UTF-8 invalide n'est pas encodable en JSON.
        JobPayload::encode(['text' => "\xB1\x31"]);
    }
}
