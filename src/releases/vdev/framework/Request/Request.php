<?php

namespace YTassy\Tycms\Request;

/**
 * Transport-independent representation of an HTTP request.
 *
 * It can be instantiated directly in tests or extended by a transport-specific
 * request, such as WebRequest. Route parameters are deliberately kept separate
 * from query-string and body values.
 */
class Request
{
    public RequestMethod $method;
    public string $uri;
    public string $path;
    /** The complete request host, without its port (for example, www.toto.fr). */
    public string $host;
    public bool $is_https;
    public int $port;
    public bool $behind_proxy;
    public string $url;
    public array $params;
    public array $query;
    public array $headers;
    public string $accept;
    public array $cookies;
    public array $files;
    public array $body;
    public array $server;
    public string $rawBody;

    private ?string $parsedAccept = null;
    /** @var array<array{type:string, subtype:string, quality:float}> */
    private array $acceptRanges = [];

    public function __construct(
        RequestMethod $method = RequestMethod::GET,
        string $uri = '/',
        array $query = [],
        array $headers = [],
        array $cookies = [],
        array $files = [],
        array $body = [],
        array $server = [],
        string $host = '',
        bool $is_https = false,
        ?string $url = null,
        int $port = 80,
        bool $behind_proxy = false,
        ?string $accept = null,
        string $raw_body = '',
    ) {
        $this->method = $method;
        $this->uri = $uri === '' ? '/' : $uri;
        $this->path = self::pathFromUri($this->uri);
        $this->host = $host;
        $this->is_https = $is_https;
        $this->port = $port;
        $this->behind_proxy = $behind_proxy;
        $this->url = $url ?? $this->uri;
        $this->params = [];
        $this->query = $query;
        $this->headers = $headers;
        $headerAccept = $this->header('Accept', '');
        $this->accept = $accept ?? (is_string($headerAccept) ? $headerAccept : '');
        $this->cookies = $cookies;
        $this->files = $files;
        $this->body = $body;
        $this->server = $server;
        $this->rawBody = $raw_body;
    }

    /** Set a parameter extracted by the router. */
    public function setParam(string $name, mixed $value): static
    {
        $this->params[$name] = $value;

        return $this;
    }

    /** Return a route parameter. */
    public function param(string $name, mixed $default = null): mixed
    {
        return $this->params[$name] ?? $default;
    }

    /** Return a query-string value. */
    public function query(string $name, mixed $default = null): mixed
    {
        return $this->query[$name] ?? $default;
    }

    /**
     * Return an input value. Body values take priority over query-string values;
     * route parameters are checked first because they identify the matched route.
     */
    public function input(string $name, mixed $default = null): mixed
    {
        if (array_key_exists($name, $this->params)) {
            return $this->params[$name];
        }

        if (array_key_exists($name, $this->body)) {
            return $this->body[$name];
        }

        return $this->query[$name] ?? $default;
    }

    /** Whether an input value exists, including a value explicitly set to null. */
    public function hasInput(string $name): bool
    {
        return array_key_exists($name, $this->params)
            || array_key_exists($name, $this->body)
            || array_key_exists($name, $this->query);
    }

    /** Return a header value, ignoring the header-name casing. */
    public function header(string $name, mixed $default = null): mixed
    {
        foreach ($this->headers as $headerName => $value) {
            if (strcasecmp($headerName, $name) === 0) {
                return $value;
            }
        }

        return $default;
    }

    public function cookie(string $name, mixed $default = null): mixed
    {
        return $this->cookies[$name] ?? $default;
    }

    public function file(string $name, mixed $default = null): mixed
    {
        return $this->files[$name] ?? $default;
    }

    public function isMethod(RequestMethod $method): bool
    {
        return $this->method === $method;
    }

    /** Whether HTML is the preferred explicitly accepted response type. */
    public function isHtmlExpected(): bool
    {
        return $this->isExpected(['text/html', 'application/xhtml+xml']);
    }

    /** Whether CSS is the preferred explicitly accepted response type. */
    public function isCssExpected(): bool
    {
        return $this->isExpected(['text/css']);
    }

    /** Whether JavaScript is the preferred explicitly accepted response type. */
    public function isJavascriptExpected(): bool
    {
        return $this->isExpected([
            'text/javascript',
            'application/javascript',
            'text/ecmascript',
            'application/ecmascript',
        ]);
    }

    /** Whether JSON is the preferred explicitly accepted response type. */
    public function isJsonExpected(): bool
    {
        return $this->isExpected(['application/json'], '+json');
    }

    /**
     * @param array<string> $mediaTypes
     */
    private function isExpected(array $mediaTypes, ?string $subtypeSuffix = null): bool
    {
        $highestQuality = -1.0;
        $expectedQuality = -1.0;
        $mediaTypes = array_flip($mediaTypes);

        foreach ($this->acceptRanges() as $range) {
            if ($range['quality'] <= 0 || $range['type'] === '*' || $range['subtype'] === '*') {
                continue;
            }

            $highestQuality = max($highestQuality, $range['quality']);
            $mediaType = $range['type'] . '/' . $range['subtype'];
            $isExpected = isset($mediaTypes[$mediaType])
                || ($subtypeSuffix !== null
                    && $range['type'] === 'application'
                    && ($range['subtype'] === '*' . $subtypeSuffix
                        || str_ends_with($range['subtype'], $subtypeSuffix)));

            if ($isExpected) {
                $expectedQuality = max($expectedQuality, $range['quality']);
            }
        }

        return $expectedQuality >= $highestQuality && $expectedQuality > 0;
    }

    /** @return array<array{type:string, subtype:string, quality:float}> */
    private function acceptRanges(): array
    {
        if ($this->parsedAccept === $this->accept) {
            return $this->acceptRanges;
        }

        $this->parsedAccept = $this->accept;
        $this->acceptRanges = [];

        foreach (explode(',', strtolower($this->accept)) as $range) {
            $parts = explode(';', $range);
            $mediaType = trim(array_shift($parts));
            [$type, $subtype] = array_pad(explode('/', $mediaType, 2), 2, '');

            if ($type === '' || $subtype === '') {
                continue;
            }

            $quality = 1.0;
            foreach ($parts as $parameter) {
                [$name, $value] = array_pad(explode('=', $parameter, 2), 2, '');
                if (trim($name) !== 'q') {
                    continue;
                }

                $value = trim($value);
                $quality = preg_match('/^(?:0(?:\.\d{0,3})?|1(?:\.0{0,3})?)$/', $value)
                    ? (float) $value
                    : 0.0;
                break;
            }

            $this->acceptRanges[] = [
                'type' => $type,
                'subtype' => $subtype,
                'quality' => $quality,
            ];
        }

        return $this->acceptRanges;
    }

    private static function pathFromUri(string $uri): string
    {
        $path = parse_url($uri, PHP_URL_PATH);

        if ($path === false) {
            if (str_contains($uri, '?')) $path = substr($uri, 0, strpos($uri, "?"));
            else $path = $uri;
        }

        return is_string($path) && $path !== '' ? $path : '/';
    }
}
