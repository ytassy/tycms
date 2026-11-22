<?php

namespace YTassy\Tycms\Request;

/** Builds a Request from PHP's web-server superglobals. */
class WebRequest extends Request
{
    public function __construct()
    {
        $server = $_SERVER ?? [];
        $method = RequestMethod::tryFrom(strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')))
            ?? RequestMethod::GET;
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $headers = self::headersFromServer($server);
        $accept = self::headerValue($headers, 'Accept');
        $behindProxy = self::isBehindProxy($headers);
        $isHttps = self::isHttps($server, $headers, $behindProxy);
        $requestAuthority = self::authorityFromServer($server, $headers, $behindProxy);
        $host = self::hostFromAuthority($requestAuthority);
        $port = self::portFromServer($server, $headers, $requestAuthority, $isHttps, $behindProxy);
        $authority = self::authority($host, $port, $isHttps);
        $url = ($isHttps ? 'https' : 'http') . '://' . $authority . ($uri === '' ? '/' : $uri);
        $rawBody = (string) file_get_contents('php://input');

        parent::__construct(
            method: $method,
            uri: $uri,
            query: $_GET ?? [],
            headers: $headers,
            cookies: $_COOKIE ?? [],
            files: $_FILES ?? [],
            body: self::bodyFromRequest($headers, $rawBody),
            server: $server,
            host: $host,
            is_https: $isHttps,
            url: $url,
            port: $port,
            behind_proxy: $behindProxy,
            accept: is_string($accept) ? $accept : '',
            raw_body: $rawBody,
        );
    }

    /**
     * X-Forwarded-Proto is honored for applications placed behind a reverse
     * proxy. Configure the web server so this header is supplied only by one.
     *
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     */
    private static function isHttps(array $server, array $headers, bool $behindProxy): bool
    {
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        if (in_array($https, ['on', '1', 'true'], true)) {
            return true;
        }

        $forwardedProto = strtolower((string) self::headerValue($headers, 'X-Forwarded-Proto'));
        if ($behindProxy && trim(explode(',', $forwardedProto, 2)[0]) === 'https') {
            return true;
        }

        return (string) ($server['SERVER_PORT'] ?? '') === '443';
    }

    /** @param array<string, mixed> $headers */
    private static function isBehindProxy(array $headers): bool
    {
        foreach (['Forwarded', 'X-Forwarded-For', 'X-Forwarded-Host', 'X-Forwarded-Port', 'X-Forwarded-Proto'] as $name) {
            if (self::headerValue($headers, $name) !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     */
    private static function authorityFromServer(array $server, array $headers, bool $behindProxy): string
    {
        $forwardedHost = $behindProxy ? self::headerValue($headers, 'X-Forwarded-Host') : null;
        $host = $forwardedHost ?? $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost';
        $host = trim(explode(',', (string) $host, 2)[0]);

        if ($host === '' || str_contains($host, "\r") || str_contains($host, "\n")) {
            return 'localhost';
        }

        return $host;
    }

    /**
     * @param array<string, mixed> $server
     * @param array<string, mixed> $headers
     */
    private static function portFromServer(
        array $server,
        array $headers,
        string $authority,
        bool $isHttps,
        bool $behindProxy,
    ): int {
        $forwardedPort = $behindProxy ? self::validPort(self::headerValue($headers, 'X-Forwarded-Port')) : null;
        if ($forwardedPort !== null) {
            return $forwardedPort;
        }

        $hostPort = parse_url('//' . $authority, PHP_URL_PORT);
        if (is_int($hostPort)) {
            return $hostPort;
        }

        return self::validPort($server['SERVER_PORT'] ?? null) ?? ($isHttps ? 443 : 80);
    }

    private static function hostFromAuthority(string $authority): string
    {
        $host = parse_url('//' . $authority, PHP_URL_HOST);

        if (!is_string($host) || $host === '') {
            return 'localhost';
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return strtolower(rtrim($host, '.'));
    }

    private static function authority(string $host, int $port, bool $isHttps): string
    {
        $host = str_contains($host, ':') && !str_starts_with($host, '[') ? "[{$host}]" : $host;
        $defaultPort = $isHttps ? 443 : 80;

        return $port === $defaultPort ? $host : $host . ':' . $port;
    }

    private static function validPort(mixed $value): ?int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);

        return $port === false ? null : $port;
    }

    /** @param array<string, mixed> $server */
    private static function headersFromServer(array $server): array
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];

        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = $key;
            } else {
                continue;
            }

            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))));
            $headers[$name] = $value;
        }

        return $headers;
    }

    /** @param array<string, mixed> $headers */
    private static function bodyFromRequest(array $headers, string $rawBody): array
    {
        $contentType = strtolower((string) self::headerValue($headers, 'Content-Type'));
        $contentType = trim(explode(';', $contentType, 2)[0]);

        if ($contentType === 'application/json' || str_ends_with($contentType, '+json')) {
            $decoded = json_decode($rawBody ?: '', true);

            return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
        }

        $post = $_POST ?? [];
        if ($post !== []) {
            return $post;
        }

        if ($contentType === 'application/x-www-form-urlencoded') {
            parse_str($rawBody ?: '', $body);

            return $body;
        }

        return [];
    }

    /** @param array<string, mixed> $headers */
    private static function headerValue(array $headers, string $name): mixed
    {
        foreach ($headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
