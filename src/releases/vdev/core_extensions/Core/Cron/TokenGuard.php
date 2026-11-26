<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Cron;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\ExceptionResponse;

/**
 * Authentification des routes techniques déclenchées par un CRON externe.
 *
 * Le jeton vient d'une seule source non vide : en-tête `Authorization: Bearer`,
 * query ou corps POST. Fournir plusieurs sources est refusé pour éviter les
 * ambiguïtés. La comparaison est en temps constant et le jeton n'est jamais
 * journalisé.
 *
 * Ce garde est partagé par la route CRON du système et par le worker de la file
 * de jobs : chaque route a son propre secret et ses propres codes de journal,
 * mais la même mécanique de lecture et de refus.
 */
final class TokenGuard extends BaseHelper
{
    /**
     * @param string $expectedToken Valeur attendue, lue dans la configuration par l'appelant.
     * @param string $tokenField     Nom du champ de query ou de corps accepté.
     * @param string $label          Libellé affiché dans les réponses d'erreur.
     * @param string $logPrefix      Préfixe des codes de journal, par exemple `CRON`.
     * @param string $channel        Canal des journaux.
     */
    public function assert(
        Request $request,
        string $expectedToken,
        string $tokenField,
        string $label,
        string $logPrefix,
        string $channel = 'system',
    ): void {
        if ($expectedToken === '') {
            $this->logError(
                'La route ' . $label . ' est appelée sans jeton configuré.',
                [],
                $logPrefix . '_TOKEN_MISSING',
                $channel,
            );

            throw new ExceptionResponse(new ApiResponse([
                'error' => true,
                'message' => 'Route non configurée (' . $label . ').',
            ], 503));
        }

        $header = $request->header('Authorization', '');
        $header = is_string($header) ? trim($header) : '';
        $digest = str_starts_with(strtolower($header), 'digest ');
        $headerToken = '';

        if (preg_match('/\ABearer\s+(.+)\z/i', $header, $matches) === 1) {
            $headerToken = trim($matches[1]);
        }

        // Certains hébergeurs appellent les CRON en GET : le jeton peut donc
        // venir de la query. Un POST peut aussi le porter dans son corps.
        // Une seule source non vide est acceptée.
        $queryToken = $request->query($tokenField);
        $bodyToken = $request->body[$tokenField] ?? null;
        $queryToken = is_string($queryToken) ? trim($queryToken) : '';
        $bodyToken = is_string($bodyToken) ? trim($bodyToken) : '';
        $sources = array_values(array_filter(
            [$headerToken, $queryToken, $bodyToken],
            static fn (string $value): bool => $value !== '',
        ));

        if (count($sources) > 1) {
            $this->logWarning(
                'Jeton ' . $label . ' fourni par plusieurs sources.',
                ['method' => $request->method->value],
                $logPrefix . '_TOKEN_AMBIGUOUS',
                $channel,
            );

            throw new ExceptionResponse(new ApiResponse([
                'error' => true,
                'message' => 'Jeton ambigu (' . $label . ').',
            ], 400));
        }

        // Le jeton applicatif est prioritaire : il garde une protection même si
        // l'hébergeur ajoute aussi Basic ou Digest.
        if ($sources !== []) {
            if (!hash_equals($expectedToken, $sources[0])) {
                $this->refuse($request, $label, $logPrefix, $channel);
            }

            return;
        }

        // Sans jeton applicatif, le mot de passe HTTP Basic peut être le même
        // secret. Cela couvre l'option login/mot de passe d'un hébergeur.
        $basic = $this->basicCredentials($request, $header);

        if ($basic !== null) {
            if ($basic['user'] === '' || !hash_equals($expectedToken, $basic['password'])) {
                $this->logWarning(
                    'Authentification HTTP Basic refusée (' . $label . ').',
                    ['method' => $request->method->value],
                    $logPrefix . '_BASIC_INVALID',
                    $channel,
                );

                throw new ExceptionResponse(new ApiResponse([
                    'error' => true,
                    'message' => 'Authentification refusée (' . $label . ').',
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
                $logPrefix . '_DIGEST_UNSUPPORTED',
                $channel,
            );
        }

        $this->refuse($request, $label, $logPrefix, $channel);
    }

    private function refuse(Request $request, string $label, string $logPrefix, string $channel): never
    {
        $this->logWarning(
            'Jeton ' . $label . ' refusé.',
            ['method' => $request->method->value],
            $logPrefix . '_TOKEN_INVALID',
            $channel,
        );

        throw new ExceptionResponse(new ApiResponse([
            'error' => true,
            'message' => 'Authentification refusée (' . $label . ').',
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
