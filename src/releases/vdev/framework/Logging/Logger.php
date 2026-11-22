<?php

declare(strict_types=1);

namespace YTassy\Tycms\Logging;

use YTassy\Tycms\Response\ExceptionResponse;

/** Service d'infrastructure : une panne de journalisation ne remonte jamais au métier. */
final class Logger
{
    private ?LogStore $store = null;
    private string $failure = '';
    private bool $fallbackSent = false;
    private array $context = [];
    private \WeakMap $seen;
    private string $requestId;
    private string $cursorKey;
    private static ?\WeakReference $active = null;
    private static ?string $reserve = null;

    public function __construct(object $configuration, private readonly string $codeRoot, ?\Closure $clock = null)
    {
        $this->seen = new \WeakMap();
        $this->requestId = bin2hex(random_bytes(16));
        $this->cursorKey = hash_hmac('sha256', 'tycms-log-cursor-v1', (string) ($configuration->secret ?? ''), true);
        try { $this->store = new LogStore($configuration, $clock ?? static fn (): int => time()); }
        catch (\Throwable) { $this->failure = 'Configuration des journaux invalide.'; }
    }

    public function setContext(array $context): void
    {
        $this->context = LogRecord::context(array_replace($this->context, $context));
    }

    /** Le libellé est une constante du code appelant, jamais un message externe brut. */
    public function log(string $level, string $channel, string $code, string $message, array $context = [], array $exceptions = [], bool $truncated = false): void
    {
        try {
            if ($this->store === null) throw new \RuntimeException();
            $record = ['schema' => 1, 'id' => bin2hex(random_bytes(16)), 'request_id' => $this->requestId,
                'timestamp' => gmdate('Y-m-d\TH:i:s', $this->store->now()) . '.' . sprintf('%06d', ((int) (microtime(true) * 1_000_000)) % 1_000_000) . 'Z',
                'level' => $level, 'channel' => $channel, 'code' => $code, 'message' => mb_strcut($message, 0, 2000, 'UTF-8'),
                'app_version' => defined('APP_VERSION') ? APP_VERSION : '',
                'context' => LogRecord::context(array_replace($this->context, $context)), 'exceptions' => $exceptions,
                'truncated' => $truncated || strlen($message) > 2000];

            // Le prénom est réservé au login réussi, jamais aux erreurs ou aux autres événements.
            if (!LogRecord::isSuccessfulLogin($record)) {
                unset($record['context']['first_name']);
            } elseif (isset($record['context']['first_name'])) {
                $record['message'] = 'Connexion utilisateur réussie : ' . $record['context']['first_name'] . '.';
            }

            if (!LogRecord::valid($record)) throw new \RuntimeException();
            $record = LogRecord::project($record);
            // Garder les causes même si le budget impose de réduire leurs piles.
            while (strlen(json_encode($record, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)) + 1 > LogRecord::MAX_BYTES) {
                $changed = false;
                foreach ($record['exceptions'] as &$exception) {
                    if (count($exception['frames']) > 1) { array_pop($exception['frames']); $changed = true; }
                }
                unset($exception);
                $record['truncated'] = true;
                if (!$changed) { $record['message'] = 'Événement tronqué.'; break; }
            }
            if ($this->store->append($record)) {
                $this->log('WARNING', 'application', 'LOG_TAIL_REPAIRED', 'La fin incomplète d’un journal a été réparée.');
            }
        } catch (\Throwable) { $this->fallback(); }
    }

    public function capture(\Throwable $error): void
    {
        // Une ExceptionResponse range sa cause dans une propriété propre au framework.
        if ($error instanceof ExceptionResponse && $error->exception === null && $error->getPrevious() === null) return;
        if (isset($this->seen[$error])) return;
        $known = null;
        $causes = LogRecord::causes($error);
        foreach ($causes as $current) {
            if (isset($this->seen[$current])) return;
        }
        $truncated = false;
        foreach ($causes as $current) {
            $this->seen[$current] = true;
            if ($known === null && $current instanceof LogEventException) $known = $current;
            $truncated = $truncated || count($current->getTrace()) > 5
                || ($current->getPrevious() !== null && !in_array($current->getPrevious(), $causes, true))
                || ($current instanceof ExceptionResponse && $current->exception !== null && !in_array($current->exception, $causes, true));
        }

        $this->log($known?->level ?? 'ERROR', $known?->channel ?? 'application', $known?->eventCode ?? 'UNEXPECTED_EXCEPTION',
            $known?->getMessage() ?? 'Une exception technique a interrompu le traitement.', $known?->context ?? [],
            LogRecord::exception($error, $this->codeRoot), $truncated);
    }

    private function fallback(): void
    {
        if ($this->fallbackSent) return;
        $this->fallbackSent = true;
        // Pas d'URL, de chemin, de message d'exception ou de contexte dans le secours.
        error_log('[Tycms] LOG_WRITE_FAILED request_id=' . $this->requestId);
    }

    public function status(): array
    {
        try {
            if ($this->store === null) throw new \RuntimeException($this->failure);
            return $this->store->status();
        } catch (\Throwable) {
            return ['available' => false, 'levels' => [], 'storage_error' => $this->failure ?: 'Stockage des journaux indisponible ou occupé.'];
        }
    }

    public function reader(): LogReader
    {
        if ($this->store === null) throw new \RuntimeException('Configuration des journaux invalide.');
        return new LogReader($this->store, $this->cursorKey);
    }

    /** Un seul handler PHP, sans garder toutes les applications de test en mémoire. */
    public function registerShutdown(): void
    {
        if (self::$reserve === null) {
            self::$reserve = str_repeat(' ', 65536);
            register_shutdown_function(static function (): void {
                $last = error_get_last();
                self::$reserve = null;
                if ($last !== null && in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
                    self::$active?->get()?->log('ERROR', 'application', 'PHP_FATAL', 'Une erreur fatale PHP a interrompu le traitement.');
                }
            });
        }
        self::$active = \WeakReference::create($this);
    }
}
