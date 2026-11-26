<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Cron;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\ICronProvider;
use YTassy\Tycms\Request\Request;

/**
 * Dispatching des hooks CRON fournis par les extensions.
 *
 * La route est appelée chaque minute par la crontab. Le dispatcher sélectionne
 * les hooks dus à partir de l'heure du serveur, isole les erreurs par extension
 * et ne journalise jamais le jeton. Aucune transaction globale n'est ouverte :
 * chaque extension gère ses écritures et son idempotence.
 *
 * L'authentification par jeton est partagée avec le worker de la file de jobs
 * (`TokenGuard`), qui a son propre secret et sa propre route.
 */
final class CronDispatcher extends BaseHelper
{
    /** Nom réservé du jeton en query ou dans le corps POST. */
    public const TOKEN_FIELD = 'cron_token';

    /** @var array<string, string> */
    private const HOOKS = [
        'minute' => 'onCronMinute',
        'quarter' => 'onCron15Min',
        'hour' => 'onCronHour',
    ];

    /**
     * @return array{
     *     ok: bool,
     *     ran_at: string,
     *     hooks: list<string>,
     *     results: array<string, array<string, mixed>>,
     *     errors: list<array{hook: string, extension: string, message: string}>
     * }
     */
    public function run(Request $request, ?CronTick $tick = null): array
    {
        /** @var TokenGuard $guard */
        $guard = $this->getSharedExtensionsClass(TokenGuard::class);
        $guard->assert(
            $request,
            (string) ($this->app->getConf()->cron_token ?? ''),
            self::TOKEN_FIELD,
            'cron',
            'CRON',
            'system',
        );

        $tick ??= CronTick::now();
        $hooks = ['minute'];

        if ($tick->isQuarterHour()) {
            $hooks[] = 'quarter';
        }

        if ($tick->isHour()) {
            $hooks[] = 'hour';
        }

        $results = [];
        $errors = [];

        foreach ($hooks as $hook) {
            $method = self::HOOKS[$hook];
            $results[$hook] = [];

            foreach ($this->app->getExtensions() as $name => $extension) {
                if (!$extension instanceof ICronProvider) {
                    continue;
                }

                try {
                    $results[$hook][(string) $name] = $extension->{$method}($tick);
                } catch (\Throwable $exception) {
                    // Un fournisseur en échec ne doit pas empêcher les autres
                    // hooks ni les autres extensions de tourner.
                    $errors[] = [
                        'hook' => $hook,
                        'extension' => (string) $name,
                        'message' => 'Hook CRON en échec.',
                    ];
                    $this->logError(
                        'Un hook CRON a échoué.',
                        ['extension' => (string) $name, 'hook' => $hook],
                        'CRON_PROVIDER_FAILED',
                        'system',
                    );
                    $this->logException($exception);
                }
            }
        }

        return [
            'ok' => $errors === [],
            'ran_at' => $tick->iso(),
            'hooks' => $hooks,
            'results' => $results,
            'errors' => $errors,
        ];
    }
}
