<?php

declare(strict_types=1);

namespace YTassy\Tycms\Http;

use RuntimeException;
use YTassy\Tycms\BaseApplicationItem;

/**
 * Téléchargement HTTPS borné, partagé par TyCatalog et le client Core.
 *
 * Aucune redirection n'est suivie. L'adresse résolue est épinglée dans cURL
 * pour empêcher une requête vers une adresse interne après résolution DNS.
 * En DEV uniquement, localhost/127.0.0.1/::1 peuvent utiliser HTTP.
 */
class SafeDownload extends BaseApplicationItem
{
    /**
     * @param list<string> $headers En-têtes complets, sans retour à la ligne.
     */
    public function get(string $url, int $limit, int $timeout = 30, array $headers = []): string
    {
        $body = '';
        $this->request($url, $timeout, static function ($handle, string $chunk) use (&$body, $limit): int {
            if (strlen($body) + strlen($chunk) > $limit) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        }, $headers);

        return $body;
    }

    /** @param list<string> $headers */
    public function downloadToFile(
        string $url,
        string $target,
        int $limit,
        int $timeout = 120,
        array $headers = [],
    ): void {
        $stream = fopen($target, 'wb');
        if ($stream === false) {
            throw new RuntimeException('Impossible de préparer le fichier temporaire du téléchargement.');
        }

        $written = 0;
        try {
            $this->request($url, $timeout, static function ($handle, string $chunk) use ($stream, &$written, $limit): int {
                $length = strlen($chunk);
                $written += $length;
                if ($written > $limit) {
                    return 0;
                }

                return fwrite($stream, $chunk) === $length ? $length : 0;
            }, $headers);
        } catch (\Throwable $exception) {
            @unlink($target);
            throw $exception;
        } finally {
            fclose($stream);
        }

        if ($written < 1 || $written > $limit) {
            @unlink($target);
            throw new RuntimeException('Téléchargement vide ou trop volumineux.');
        }
    }

    /** @param list<string> $headers */
    private function request(string $url, int $timeout, callable $write, array $headers): void
    {
        foreach ($headers as $header) {
            if (!is_string($header) || str_contains($header, "\r") || str_contains($header, "\n")) {
                throw new RuntimeException('En-tête de téléchargement invalide.');
            }
        }

        $parts = $this->parts($url);
        $host = trim((string) ($parts['host'] ?? ''), '[]');
        $local = $this->isLocalHost($host);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $ip = $this->resolve($host, $local);

        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Impossible d’initialiser le téléchargement.');
        }

        $options = [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => $local && $this->in_dev
                ? CURLPROTO_HTTPS | CURLPROTO_HTTP
                : CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'TyCMS',
            CURLOPT_WRITEFUNCTION => $write,
        ];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $address = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . $address];
        }
        if ($headers !== []) {
            $options[CURLOPT_HTTPHEADER] = $headers;
        }

        curl_setopt_array($curl, $options);

        try {
            $ok = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            if ($ok === false || $status !== 200) {
                throw new RuntimeException('Le téléchargement distant a échoué dans les limites autorisées.');
            }
        } finally {
            // Pas de curl_close() : sans effet depuis PHP 8.0 et déprécié en 8.5.
            unset($curl);
        }
    }

    /** @return array<string, mixed> */
    private function parts(string $url): array
    {
        $parts = parse_url($url);
        $host = is_array($parts) ? trim((string) ($parts['host'] ?? ''), '[]') : '';
        $local = $this->isLocalHost($host);
        $schemes = $local && $this->in_dev ? ['https', 'http'] : ['https'];

        if (!is_array($parts) || strlen($url) > 2000 || $host === ''
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['fragment'])
            || !in_array($parts['scheme'] ?? '', $schemes, true)
            || (isset($parts['port']) && !$local && (int) $parts['port'] !== 443)) {
            throw new RuntimeException('URL externe invalide.');
        }

        return $parts;
    }

    private function resolve(string $host, bool $local): string
    {
        if ($host === '') {
            throw new RuntimeException('Hôte distant invalide.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if ($local && $this->in_dev && $this->isLoopback($host)) {
                return $host;
            }
            if (!self::isPublicAddress($host)) {
                throw new RuntimeException('Adresse réseau interne refusée.');
            }
            return $host;
        }

        $addresses = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $record) {
            if (isset($record['ip']) && is_string($record['ip'])) $addresses[] = $record['ip'];
            if (isset($record['ipv6']) && is_string($record['ipv6'])) $addresses[] = $record['ipv6'];
        }
        // Ajouter les IPv4 même si la résolution DNS n'a fourni que de l'IPv6.
        foreach (@gethostbynamel($host) ?: [] as $ip) $addresses[] = $ip;

        // Préférer IPv4 : un hôte dual-stack ne doit pas échouer si IPv6 n'est
        // pas joignable depuis cette installation.
        $addresses = array_values(array_unique($addresses));
        usort($addresses, static function (string $first, string $second): int {
            $firstIsV4 = filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            $secondIsV4 = filter_var($second, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;

            return $secondIsV4 <=> $firstIsV4;
        });

        foreach ($addresses as $address) {
            if ($local && $this->in_dev && $this->isLoopback($address)) {
                return $address;
            }
            if (self::isPublicAddress($address)) {
                return $address;
            }
        }

        throw new RuntimeException('Le nom distant ne résout vers aucune adresse publique.');
    }

    private function isLocalHost(string $host): bool
    {
        return in_array($host, ['localhost', '127.0.0.1', '::1'], true);
    }

    private function isLoopback(string $address): bool
    {
        return $address === '::1' || str_starts_with($address, '127.');
    }

    private static function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
