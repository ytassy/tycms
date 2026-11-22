<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Cron;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\ICronProvider;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\ExceptionResponse;

/**
 * Dispatching des hooks CRON fournis par les extensions.
 *
 * La route est appelée chaque minute par la crontab. Le dispatcher sélectionne
 * les hooks dus à partir de l'heure du serveur, isole les erreurs par extension
 * et ne journalise jamais le jeton. Aucune transaction globale n'est ouverte :
 * chaque extension gère ses écritures et son idempotence.
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
        $this->assertToken($request);
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

    private function assertToken(Request $request): void
    {
        $expected = (string) ($this->app->getConf()->cron_token ?? '');

        if ($expected === '') {
            $this->logError(
                'La route CRON est appelée sans jeton configuré.',
                [],
                'CRON_TOKEN_MISSING',
                'system',
            );

            throw new ExceptionResponse(new ApiResponse([
                'error' => true,
                'message' => 'CRON non configuré.',
            ], 503));
        }

        $header = $request->header('Authorization', '');
        $header = is_string($header) ? trim($header) : '';
        $digest = str_starts_with(strtolower($header), 'digest ');
        $headerToken = '';

        if (preg_match('/\ABearer\s+(.+)\z/i', $header, $matches) === 1) {
            $headerToken = trim($matches[1]);
        }

        // Infomaniak appelle les CRON en GET : le jeton peut donc venir de la
        // query. Un POST peut aussi le porter dans son corps. Une seule source
        // de jeton non vide est acceptée pour éviter les ambiguïtés.
        $queryToken = $request->query(self::TOKEN_FIELD);
        $bodyToken = $request->body[self::TOKEN_FIELD] ?? null;
        $queryToken = is_string($queryToken) ? trim($queryToken) : '';
        $bodyToken = is_string($bodyToken) ? trim($bodyToken) : '';
        $sources = array_values(array_filter(
            [$headerToken, $queryToken, $bodyToken],
            static fn (string $value): bool => $value !== '',
        ));

        if (count($sources) > 1) {
            $this->logWarning(
                'Jeton CRON fourni par plusieurs sources.',
                ['method' => $request->method->value],
                'CRON_TOKEN_AMBIGUOUS',
                'system',
            );

            throw new ExceptionResponse(new ApiResponse([
                'error' => true,
                'message' => 'Jeton CRON ambigu.',
            ], 400));
        }

        // Le jeton applicatif est prioritaire : il permet de garder une
        // protection même si Infomaniak ajoute aussi Basic ou Digest.
        if ($sources !== []) {
            if (!hash_equals($expected, $sources[0])) {
                $this->refuseToken($request);
            }

            return;
        }

        // Sans jeton applicatif, le mot de passe HTTP Basic peut être le même
        // que cron_token. Cela couvre l'option login/mot de passe d'Infomaniak
        // sans ajouter un second secret.
        $basic = $this->basicCredentials($request, $header);

        if ($basic !== null) {
            if ($basic['user'] === '' || !hash_equals($expected, $basic['password'])) {
                $this->logWarning(
                    'Authentification HTTP Basic du CRON refusée.',
                    ['method' => $request->method->value],
                    'CRON_BASIC_INVALID',
                    'system',
                );

                throw new ExceptionResponse(new ApiResponse([
                    'error' => true,
                    'message' => 'Authentification CRON refusée.',
                ], 401));
            }

            return;
        }

        // Le Digest doit être validé par le serveur web : l'application ne
        // connaît pas le mot de passe en clair et ne gère pas les nonces.
        if ($digest) {
            $this->logWarning(
                'Authentification Digest reçue sans jeton applicatif.',
                ['method' => $request->method->value],
                'CRON_DIGEST_UNSUPPORTED',
                'system',
            );
        }

        $this->refuseToken($request);
    }

    private function refuseToken(Request $request): never
    {
        $this->logWarning(
            'Jeton CRON refusé.',
            ['method' => $request->method->value],
            'CRON_TOKEN_INVALID',
            'system',
        );

        throw new ExceptionResponse(new ApiResponse([
            'error' => true,
            'message' => 'Authentification CRON refusée.',
        ], 401));
    }

    /**
     * Lit les identifiants HTTP Basic, y compris les variables fournies par
     * Apache quand l'en-tête Authorization n'est pas transmis à PHP.
     *
     * @return array{user: string, password: string}|null
     */
    private function basicCredentials(Request $request, string $header): ?array
    {
        $serverUser = $request->server['PHP_AUTH_USER'] ?? null;
        $serverPassword = $request->server['PHP_AUTH_PW'] ?? null;

        if (is_string($serverUser) || is_string($serverPassword)) {
            return [
                'user' => is_string($serverUser) ? $serverUser : '',
                'password' => is_string($serverPassword) ? $serverPassword : '',
            ];
        }

        if (preg_match('/\ABasic\s+(.+)\z/i', $header, $matches) !== 1) {
            return null;
        }

        $decoded = base64_decode(trim($matches[1]), true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return ['user' => '', 'password' => ''];
        }

        [$user, $password] = explode(':', $decoded, 2);

        return ['user' => $user, 'password' => $password];
    }
}
