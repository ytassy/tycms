<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

/** Transport HTTP minimal, sans redirection ni confiance implicite. */
final class CurlNotificationTransport
{
    private const MAX_RESPONSE_BYTES = 65536;

    /** @param array<string, mixed> $payload */
    public static function postJson(string $url, array $payload): void
    {
        $body = json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        self::post($url, $body, 'application/json');
    }

    /** @param array<string, mixed> $payload */
    public static function postForm(string $url, array $payload): void
    {
        self::post($url, http_build_query($payload), 'application/x-www-form-urlencoded');
    }

    private static function post(string $url, string $body, string $contentType): void
    {
        $handle = curl_init($url);

        if ($handle === false) {
            throw new NotificationException();
        }

        $received = 0;

        curl_setopt_array($handle, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: ' . $contentType,
                'Content-Length: ' . strlen($body),
            ],
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_WRITEFUNCTION => static function ($curl, string $data) use (&$received): int {
                $received += strlen($data);

                return $received > self::MAX_RESPONSE_BYTES ? 0 : strlen($data);
            },
        ]);

        $result = curl_exec($handle);
        $errno = curl_errno($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        unset($handle);

        if ($errno !== 0 || $result === false) {
            throw new NotificationException($status, $errno);
        }

        if ($status < 200 || $status >= 300) {
            throw new NotificationException($status);
        }
    }
}
