<?php

declare(strict_types=1);

namespace YTassy\Tycms\Logging;

use InvalidArgumentException;
use RuntimeException;

/** Pagination physique bornée des trois journaux, sans chargement global des fichiers. */
final class LogReader
{
    private const SCAN_BYTES = 4_000_000;
    private int $budget = self::SCAN_BYTES;
    private int $corrupt = 0;

    public function __construct(private readonly LogStore $store, private readonly string $key) {}

    public static function filters(array $input): array
    {
        $result = [];
        $levels = $input['levels'] ?? LogRecord::LEVELS;
        if (!is_array($levels) || !array_is_list($levels) || $levels === [] || count($levels) > 3
            || !array_all($levels, static fn (mixed $level): bool => is_string($level))
            || array_diff($levels, LogRecord::LEVELS)) throw new InvalidArgumentException('Niveaux de journal invalides.');
        $result['levels'] = array_values(array_unique($levels));
        sort($result['levels']);
        foreach (['channel' => '/\A[a-z][a-z0-9_.]{0,60}\z/', 'code' => '/\A[A-Z][A-Z0-9_]{0,80}\z/',
            'request_id' => '/\A[a-f0-9]{32}\z/'] as $key => $pattern) {
            $value = $input[$key] ?? '';
            if (!is_string($value) || ($value !== '' && !preg_match($pattern, $value))) throw new InvalidArgumentException('Filtre de journal invalide.');
            $result[$key] = $value;
        }
        $space = $input['space_id'] ?? null;
        if ($space === '') $space = null;
        if ($space !== null && (!is_int($space) || $space < 1)) throw new InvalidArgumentException('Space de journal invalide.');
        $result['space_id'] = $space;
        foreach (['from', 'to'] as $key) {
            $value = $input[$key] ?? '';
            $date = is_string($value) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) : false;
            if ($value !== '' && ($date === false || $date->format('Y-m-d') !== $value)) throw new InvalidArgumentException('Période de journal invalide.');
            $result[$key] = $value;
        }
        if ($result['from'] !== '' && $result['to'] !== '' && $result['from'] > $result['to']) throw new InvalidArgumentException('Période de journal inversée.');
        return $result;
    }

    public function page(array $input, ?string $cursor = null): array
    {
        $filters = self::filters($input);
        $this->budget = self::SCAN_BYTES;
        $this->corrupt = 0;
        $state = $cursor === null ? $this->snapshot($filters) : $this->decode($cursor, $filters);
        $entries = [];
        $responseBytes = 0;
        while (count($entries) < 100 && $this->budget > 0) {
            $heads = [];
            foreach ($state['positions'] as $level => &$position) {
                if (!isset($position['head'])) $position['head'] = $this->next($level, $position, $filters);
                if ($position['head'] !== null) $heads[$level] = $position['head'];
            }
            unset($position);
            // Ne pas rendre un résultat tant que la tête d'un autre niveau reste inconnue.
            if ($this->budget <= 0 || $heads === []) break;
            uasort($heads, static fn (array $a, array $b): int => [$b['timestamp'], $b['id']] <=> [$a['timestamp'], $a['id']]);
            $level = array_key_first($heads);
            $entry = LogRecord::project($heads[$level]);
            $bytes = strlen(json_encode($entry, JSON_THROW_ON_ERROR));
            if ($responseBytes + $bytes > 1_000_000) break;
            $responseBytes += $bytes;
            $entries[] = $entry;
            unset($state['positions'][$level]['head']);
        }
        $more = false;
        foreach ($state['positions'] as $position) {
            if (($position['head'] ?? null) !== null || $position['files'] !== []) $more = true;
        }
        return ['entries' => $entries, 'cursor' => $more ? $this->encode($state) : null,
            'partial' => $more, 'corrupt_lines' => $this->corrupt];
    }

    private function snapshot(array $filters): array
    {
        return $this->store->locked(function () use ($filters): array {
            $positions = [];
            foreach ($filters['levels'] as $level) {
                $files = $this->store->purge($level);
                foreach ($files as $name => &$file) {
                    $handle = $this->store->open($this->store->path($level, $name), 'rb');
                    try { $file['fingerprint'] = hash('sha256', fread($handle, min(256, $file['size']))); }
                    finally { fclose($handle); }
                }
                unset($file);
                $positions[$level] = ['files' => $files, 'skip' => false];
            }
            return ['expires' => $this->store->now() + 900, 'filters' => $filters, 'positions' => $positions];
        });
    }

    private function next(string $level, array &$position, array $filters): ?array
    {
        while ($position['files'] !== [] && $this->budget > 0) {
            $name = array_key_last($position['files']);
            $file = &$position['files'][$name];
            $offset = $position['offset'] ?? $file['size'];
            if ($offset === 0) {
                unset($position['files'][$name], $position['offset']);
                $position['skip'] = false;
                continue;
            }
            $handle = $this->store->open($this->store->path($level, $name), 'rb');
            try {
                $stat = fstat($handle);
                $fingerprint = hash('sha256', fread($handle, min(256, $file['size'])));
                if ($stat['ino'] !== $file['inode'] || $stat['size'] < $file['size'] || !hash_equals($file['fingerprint'], $fingerprint)) {
                    throw new RuntimeException('Historique modifié ou expiré. Actualisez les journaux.');
                }
                $length = min($offset, LogRecord::MAX_BYTES + 1);
                if ($length > $this->budget) { $this->budget = 0; return null; }
                $start = $offset - $length;
                fseek($handle, $start);
                $chunk = fread($handle, $length);
            } finally { fclose($handle); }
            $this->budget -= $length;
            if (strlen($chunk) !== $length) throw new RuntimeException('Lecture du journal interrompue.');

            // Ignorer les lignes trop longues ou incomplètes sans lire tout leur contenu.
            if ($position['skip'] || !str_ends_with($chunk, "\n")) {
                $newline = strrpos($chunk, "\n");
                $position['offset'] = $newline === false ? $start : $start + $newline + 1;
                $position['skip'] = $newline === false && $start > 0;
                $this->corrupt++;
                continue;
            }
            $text = substr($chunk, 0, -1);
            $newline = strrpos($text, "\n");
            if ($newline === false && $start > 0) {
                $position['offset'] = $start;
                $position['skip'] = true;
                $this->corrupt++;
                continue;
            }
            $lineStart = $newline === false ? 0 : $newline + 1;
            $position['offset'] = $start + $lineStart;
            $record = json_decode(substr($text, $lineStart), true, 16);
            if (!LogRecord::valid($record) || $record['level'] !== $level) {
                $this->corrupt++;
                continue;
            }
            if ($this->matches($record, $filters)) return LogRecord::project($record);
        }
        return null;
    }

    private function matches(array $record, array $filters): bool
    {
        foreach (['channel', 'code', 'request_id'] as $key) if ($filters[$key] !== '' && $record[$key] !== $filters[$key]) return false;
        $day = substr($record['timestamp'], 0, 10);
        return ($filters['space_id'] === null || ($record['context']['space_id'] ?? null) === $filters['space_id'])
            && ($filters['from'] === '' || $day >= $filters['from']) && ($filters['to'] === '' || $day <= $filters['to']);
    }

    private function encode(array $state): string
    {
        $json = json_encode($state, JSON_THROW_ON_ERROR);
        return base64_encode($json) . '.' . hash_hmac('sha256', $json, $this->key);
    }

    private function decode(string $cursor, array $filters): array
    {
        if (strlen($cursor) > 500_000) throw new InvalidArgumentException('Curseur de journal invalide.');
        $parts = explode('.', $cursor);
        $json = count($parts) === 2 ? base64_decode($parts[0], true) : false;
        if ($json === false || !hash_equals(hash_hmac('sha256', $json, $this->key), $parts[1])) throw new InvalidArgumentException('Curseur de journal invalide.');
        $state = json_decode($json, true, 20, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['filters'] ?? null) !== $filters || !is_int($state['expires'] ?? null)
            || $state['expires'] < $this->store->now() || !is_array($state['positions'] ?? null)
            || array_keys($state['positions']) !== $filters['levels']) throw new InvalidArgumentException('Curseur expiré ou filtres modifiés. Actualisez les journaux.');
        // Même signé, aucun nom de fichier ne franchit la validation de LogStore::path().
        $this->store->root();
        foreach ($state['positions'] as $level => $position) {
            if (!is_array($position) || !is_array($position['files'] ?? null) || count($position['files']) > LogStore::MAX_FILES) throw new InvalidArgumentException('Curseur de journal invalide.');
            if (isset($position['head']) && (!LogRecord::valid($position['head'])
                || $position['head']['level'] !== $level || !$this->matches($position['head'], $filters))) throw new InvalidArgumentException('Curseur de journal invalide.');
            if (!is_bool($position['skip'] ?? null)) throw new InvalidArgumentException('Curseur de journal invalide.');
            foreach ($position['files'] as $name => $file) {
                $this->store->path($level, $name);
                if (!is_array($file) || !is_int($file['size'] ?? null) || $file['size'] < 0
                    || $file['size'] > $this->store->segmentBytes || !is_int($file['inode'] ?? null)
                    || !is_string($file['fingerprint'] ?? null)) throw new InvalidArgumentException('Curseur de journal invalide.');
            }
            if (isset($position['offset']) && (!is_int($position['offset']) || $position['offset'] < 0
                || $position['offset'] > $this->store->segmentBytes)) throw new InvalidArgumentException('Curseur de journal invalide.');
        }
        return $state;
    }
}
