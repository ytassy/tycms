<?php

declare(strict_types=1);

namespace YTassy\Tycms\Logging;

use RuntimeException;

/** Stockage privé local : aucune dépendance SQL, session ou extension. */
final class LogStore
{
    public const MAX_FILES = 512;
    public readonly array $levels;
    public readonly int $segmentBytes;
    private string $root;
    private string $configuredPath;
    private array $publicRoots;

    public function __construct(object $configuration, private readonly \Closure $clock)
    {
        $defaults = array_fill_keys(LogRecord::LEVELS, ['retention_days' => 90, 'max_bytes' => 50_000_000]);
        $this->levels = $configuration->log_levels ?? $defaults;
        $this->segmentBytes = $configuration->log_max_file_bytes ?? 10_000_000;
        $this->configuredPath = $configuration->log_path ?? APP_ROOT . '/logs';
        $this->publicRoots = [APP_ROOT . '/public', $configuration->medias_root ?? APP_ROOT . '/public/medias',
            $configuration->fonts_root ?? APP_ROOT . '/public/assets/fonts'];

        // Une surcharge doit expliciter exactement les trois niveaux connus.
        $keys = array_keys($this->levels);
        sort($keys);
        if ($keys !== ['ERROR', 'INFO', 'WARNING'] || $this->segmentBytes < LogRecord::MAX_BYTES
            || $this->segmentBytes > 50_000_000) throw new RuntimeException('Configuration des journaux invalide.');
        foreach ($this->levels as $settings) {
            if (!is_array($settings) || count($settings) !== 2
                || !is_int($settings['retention_days'] ?? null) || $settings['retention_days'] < 1 || $settings['retention_days'] > 3650
                || !is_int($settings['max_bytes'] ?? null) || $settings['max_bytes'] < $this->segmentBytes
                || $settings['max_bytes'] > 1_000_000_000) throw new RuntimeException('Configuration des journaux invalide.');
        }
    }

    public function now(): int { return ($this->clock)(); }

    public function root(): string
    {
        if ($this->configuredPath === '' || !str_starts_with($this->configuredPath, '/')
            || str_contains($this->configuredPath, "\0")
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $this->configuredPath)) throw new RuntimeException('Chemin des journaux invalide.');

        // Vérifier le parent réel AVANT de créer le moindre dossier sous une racine publique.
        $ancestor = $this->configuredPath;
        while (!file_exists($ancestor) && !is_link($ancestor)) {
            $parent = dirname($ancestor);
            if ($parent === $ancestor) throw new RuntimeException('Chemin des journaux invalide.');
            $ancestor = $parent;
        }
        $real = realpath($ancestor);
        if ($real === false) throw new RuntimeException('Stockage des journaux indisponible.');
        foreach ($this->publicRoots as $public) {
            $public = realpath($public) ?: rtrim($public, '/');
            if ($real === $public || str_starts_with($real, $public . '/')
                || $this->configuredPath === $public || str_starts_with($this->configuredPath, $public . '/')) {
                throw new RuntimeException('Les journaux doivent rester hors des dossiers publics.');
            }
        }
        if (!is_dir($this->configuredPath) && !@mkdir($this->configuredPath, 0700, true) && !is_dir($this->configuredPath)) {
            throw new RuntimeException('Stockage des journaux indisponible.');
        }
        $base = realpath($this->configuredPath);
        if ($base === false) throw new RuntimeException('Stockage des journaux indisponible.');
        $this->root = $base . '/tycms';
        $this->directory($this->root);
        foreach (LogRecord::LEVELS as $level) $this->directory($this->root . '/' . strtolower($level));
        return $this->root;
    }

    private function directory(string $path): void
    {
        clearstatcache(true, $path);
        if (is_link($path) || (!is_dir($path) && !@mkdir($path, 0700) && !is_dir($path))
            || realpath($path) !== $path || !@chmod($path, 0700)) throw new RuntimeException('Dossier privé des journaux indisponible.');
    }

    /** Toutes les opérations de rotation partagent un verrou stable, avec attente bornée. */
    public function locked(callable $callback, bool $exclusive = true): mixed
    {
        $path = $this->root() . '/.lock';
        $handle = $this->open($path, 'c+b');
        $deadline = hrtime(true) + 50_000_000;
        try {
            do {
                if (flock($handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
                    try { return $callback(); } finally { flock($handle, LOCK_UN); }
                }
                usleep(1000);
            } while (hrtime(true) < $deadline);
            throw new RuntimeException('Journaux occupés. Réessayez.');
        } finally { fclose($handle); }
    }

    /** @return resource */
    public function open(string $path, string $mode)
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (is_link($path) || ($before !== false && ($before['mode'] & 0170000) !== 0100000)) {
            throw new RuntimeException('Fichier de journal non régulier.');
        }
        $handle = @fopen($path, $mode);
        if ($handle === false) throw new RuntimeException('Fichier de journal inaccessible.');
        $opened = fstat($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if ($opened === false || $after === false || ($opened['mode'] & 0170000) !== 0100000
            || $after['ino'] !== $opened['ino'] || $after['dev'] !== $opened['dev']
            || $opened['nlink'] !== 1 || is_link($path)
            || ($before !== false && ($before['ino'] !== $opened['ino'] || $before['dev'] !== $opened['dev']))) {
            fclose($handle);
            throw new RuntimeException('Identité du journal modifiée.');
        }
        if ($mode !== 'rb' && !@chmod($path, 0600)) {
            fclose($handle);
            throw new RuntimeException('Protection du journal impossible.');
        }
        return $handle;
    }

    public function path(string $level, string $name): string
    {
        if (!in_array($level, LogRecord::LEVELS, true) || !preg_match('/\Atycms-\d{8}-\d{6}\.jsonl\z/', $name)) {
            throw new RuntimeException('Référence de journal invalide.');
        }
        return $this->root . '/' . strtolower($level) . '/' . $name;
    }

    /** Listing borné, sans suivre les liens ni toucher aux fichiers étrangers. Sous verrou. */
    public function files(string $level): array
    {
        $result = [];
        $count = 0;
        foreach (new \DirectoryIterator($this->root . '/' . strtolower($level)) as $entry) {
            if (++$count > self::MAX_FILES + 10) throw new RuntimeException('Trop de fichiers dans les journaux.');
            $name = $entry->getFilename();
            if (!preg_match('/\Atycms-\d{8}-\d{6}\.jsonl\z/', $name)) continue;
            if ($entry->isLink() || !$entry->isFile()) throw new RuntimeException('Fichier de journal non régulier.');
            $result[$name] = ['size' => $entry->getSize(), 'inode' => $entry->getInode()];
        }
        ksort($result);
        return $result;
    }

    public function purge(string $level, int $reserve = 0): array
    {
        $files = $this->files($level);
        $cutoff = gmdate('Ymd', $this->now() - $this->levels[$level]['retention_days'] * 86400);
        $total = array_sum(array_column($files, 'size'));
        foreach ($files as $name => $file) {
            if (substr($name, 6, 8) > $cutoff && $total + $reserve <= $this->levels[$level]['max_bytes']
                && count($files) < self::MAX_FILES) continue;
            if (!@unlink($this->path($level, $name))) throw new RuntimeException('Nettoyage des journaux impossible.');
            $total -= $file['size'];
            unset($files[$name]);
        }
        return $files;
    }

    public function append(array $record): bool
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE) . "\n";
        if (strlen($line) > LogRecord::MAX_BYTES) throw new RuntimeException('Entrée de journal trop volumineuse.');
        return $this->locked(function () use ($record, $line): bool {
            $repaired = false;
            foreach (LogRecord::LEVELS as $other) $this->purge($other);
            $level = $record['level'];
            $files = $this->purge($level, strlen($line));
            $prefix = 'tycms-' . gmdate('Ymd', $this->now()) . '-';
            // Un recul d'horloge ne doit pas rouvrir sans contrôle un ancien segment plein.
            $today = array_filter($files, static fn (string $name): bool => str_starts_with($name, $prefix), ARRAY_FILTER_USE_KEY);
            $last = array_key_last($today);
            if ($last === null || $files[$last]['size'] + strlen($line) > $this->segmentBytes) {
                if (count($files) >= self::MAX_FILES) throw new RuntimeException('Trop de segments de journal.');
                $sequence = $last !== null ? (int) substr($last, 15, 6) + 1 : 1;
                if ($sequence > 999999) throw new RuntimeException('Trop de segments de journal.');
                $last = $prefix . sprintf('%06d', $sequence) . '.jsonl';
            }
            $handle = $this->open($this->path($level, $last), 'c+b');
            try {
                // Réparer uniquement la fin incomplète d'une écriture interrompue.
                fseek($handle, 0, SEEK_END);
                $size = ftell($handle);
                if ($size > 0) {
                    $start = max(0, $size - LogRecord::MAX_BYTES);
                    fseek($handle, $start);
                    $tail = stream_get_contents($handle);
                    if (!str_ends_with($tail, "\n")) {
                        $newline = strrpos($tail, "\n");
                        if ($newline === false && $start > 0) throw new RuntimeException('Fin de journal corrompue.');
                        if (!ftruncate($handle, $newline === false ? 0 : $start + $newline + 1)) throw new RuntimeException('Réparation du journal impossible.');
                        $repaired = true;
                    }
                }
                fseek($handle, 0, SEEK_END);
                $offset = 0;
                while ($offset < strlen($line)) {
                    $written = @fwrite($handle, substr($line, $offset));
                    if ($written === false || $written === 0) throw new RuntimeException('Écriture du journal interrompue.');
                    $offset += $written;
                }
                if (!fflush($handle)) throw new RuntimeException('Écriture du journal interrompue.');
            } finally { fclose($handle); }
            return $repaired;
        });
    }

    public function status(): array
    {
        return $this->locked(function (): array {
            $levels = [];
            foreach (LogRecord::LEVELS as $level) {
                $files = $this->purge($level);
                $levels[$level] = $this->levels[$level] + ['bytes' => array_sum(array_column($files, 'size')),
                    'files' => count($files), 'first_day' => $files === [] ? null : substr(array_key_first($files), 6, 8),
                    'last_day' => $files === [] ? null : substr(array_key_last($files), 6, 8)];
            }
            return ['available' => true, 'levels' => $levels];
        });
    }
}
