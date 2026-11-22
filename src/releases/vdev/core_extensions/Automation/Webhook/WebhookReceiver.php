<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Webhook;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalType;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseHelper;

/**
 * Réception d'un webhook de scénario.
 *
 * Le scénario et le trigger sont toujours relus côté serveur. Aucun jeton,
 * secret ou corps de requête n'est journalisé. Le trigger s'exécute en mode
 * auteur : un webhook n'a pas de session utilisateur.
 */
final class WebhookReceiver extends BaseHelper
{
    use TraitAutomationHelpers;

    public const TRIGGER_ID = 'automation.webhook.received';
    public const MAX_BODY_BYTES = 262144;
    public const MAX_PER_MINUTE = 120;
    public const PATH_PREFIX = '/automation/webhook/';

    /** @return array{accepted: true} */
    public function receive(int $scenarioId, Request $request): array
    {
        $scenario = $this->automationRepository->findScenarioById($scenarioId);

        if ($scenario === null) {
            $this->fail(
                'Webhook introuvable.',
                404,
                'AUTOMATION_WEBHOOK_NOT_FOUND',
                null,
                'Webhook inconnu (scénario #' . $scenarioId . ').',
            );
        }

        $spaceId = (int) $scenario->space_id;
        $trigger = $this->automationRepository->findTrigger($scenarioId);

        if ($trigger === null || (string) $trigger->trigger_id !== self::TRIGGER_ID) {
            $this->fail(
                'Webhook introuvable.',
                404,
                'AUTOMATION_WEBHOOK_NOT_FOUND',
                $spaceId,
                'Webhook sans déclencheur webhook (scénario #' . $scenarioId . ').',
            );
        }

        if ((string) $scenario->status !== 'active'
            || $scenario->published_version_id === null
            || (int) $trigger->enabled !== 1) {
            $this->fail(
                'Webhook indisponible.',
                409,
                'AUTOMATION_WEBHOOK_UNAVAILABLE',
                $spaceId,
                'Webhook désactivé ou scénario non publié (scénario #' . $scenarioId . ').',
            );
        }

        $config = WebhookConfig::decode((string) $trigger->config);
        $mode = WebhookConfig::authMode($config);

        $this->assertMethod($mode, $request, $spaceId, $scenarioId);
        $this->assertPayloadSize($request, $mode, $spaceId, $scenarioId);
        $this->authenticate($trigger, $config, $mode, $request, $spaceId, $scenarioId);
        $payload = $this->payload($request, $mode, $spaceId, $scenarioId);

        $recent = $this->automationRepository->countRecentEventsForScenario(
            $spaceId,
            self::TRIGGER_ID,
            (string) $scenario->key,
            60,
        );

        if ($recent >= self::MAX_PER_MINUTE) {
            $this->fail(
                'Trop de demandes.',
                429,
                'AUTOMATION_WEBHOOK_RATE_LIMIT',
                $spaceId,
                'Quota webhook atteint (scénario #' . $scenarioId . ').',
            );
        }

        $eventId = $this->automationTrigger->emit(
            $spaceId,
            self::TRIGGER_ID,
            $payload,
            AutomationPrincipalType::SERVICE,
            null,
            (string) $scenario->key,
        );

        if ($eventId === null) {
            $this->fail(
                'Webhook temporairement indisponible.',
                503,
                'AUTOMATION_WEBHOOK_EMIT_FAILED',
                $spaceId,
                'Événement webhook refusé (scénario #' . $scenarioId . ').',
            );
        }

        $responseMode = WebhookConfig::responseMode($config);
        $response = ['accepted' => true];

        if ($responseMode === WebhookConfig::RESPONSE_RESULT) {
            // Le run est exécuté dans la transaction de la route ; la réponse
            // n'est envoyée qu'après le commit par le runner.
            $results = $this->automationEventBuffer->drainEvent($eventId);
            $outcome = null;

            foreach ($results as $result) {
                if ((int) ($result['scenario_id'] ?? 0) === $scenarioId) {
                    $outcome = $result;
                    break;
                }
            }

            if ($outcome === null) {
                $this->fail(
                    'Le scénario n’a pas pu démarrer.',
                    409,
                    'AUTOMATION_WEBHOOK_NOT_RUN',
                    $spaceId,
                    'Webhook sans run exécutable (scénario #' . $scenarioId . ').',
                );
            }

            $response = [
                'accepted' => true,
                'run_id' => $outcome['run_id'] ?? null,
                'status' => (string) ($outcome['status'] ?? 'failed'),
                'output' => $outcome['output'] ?? null,
                'message' => $outcome['message'] ?? null,
            ];
        }

        $this->logInfo(
            'Webhook accepté pour le scénario #' . $scenarioId . ' « ' . (string) $scenario->key . ' » ('
                . $mode . ', réponse ' . $responseMode . ').',
            [
                'space_id' => $spaceId,
                'method' => $request->method->value,
                'response_mode' => $responseMode,
            ],
            'AUTOMATION_WEBHOOK_ACCEPTED',
            'automation',
        );

        return $response;
    }

    /**
     * Les modes à jeton d'en-tête ou signés restent strictement en POST.
     * Le mode query accepte GET et POST pour les intégrations qui ne peuvent
     * pas poser d'en-tête.
     */
    private function assertMethod(
        string $mode,
        Request $request,
        int $spaceId,
        int $scenarioId,
    ): void {
        if ($mode === WebhookAuthMode::QUERY) {
            return;
        }

        if ($request->method !== RequestMethod::POST) {
            $this->fail(
                'Méthode non autorisée.',
                405,
                'AUTOMATION_WEBHOOK_METHOD',
                $spaceId,
                'Webhook refusé hors POST (scénario #' . $scenarioId . ', mode ' . $mode . ').',
            );
        }
    }

    private function assertPayloadSize(
        Request $request,
        string $mode,
        int $spaceId,
        int $scenarioId,
    ): void {
        if (strlen($request->rawBody) > self::MAX_BODY_BYTES
            || (($request->method === RequestMethod::GET || $mode === WebhookAuthMode::QUERY)
                && strlen($request->uri) > self::MAX_BODY_BYTES)) {
            $this->fail(
                'Charge utile trop volumineuse.',
                413,
                'AUTOMATION_WEBHOOK_PAYLOAD_INVALID',
                $spaceId,
                'Charge utile webhook trop volumineuse (scénario #' . $scenarioId . ').',
            );
        }
    }

    /** @param array<string, mixed> $config */
    private function authenticate(
        object $trigger,
        array $config,
        string $mode,
        Request $request,
        int $spaceId,
        int $scenarioId,
    ): void {
        if ($mode === WebhookAuthMode::HMAC) {
            $this->authenticateHmac($trigger, $request, $spaceId, $scenarioId);

            return;
        }

        $token = '';

        if ($mode === WebhookAuthMode::QUERY) {
            $queryToken = $request->query(WebhookConfig::TOKEN_FIELD);
            $bodyToken = $request->body[WebhookConfig::TOKEN_FIELD] ?? null;
            $queryToken = is_string($queryToken) ? $queryToken : '';
            $bodyToken = is_string($bodyToken) ? $bodyToken : '';

            if ($queryToken !== '' && $bodyToken !== '') {
                $this->fail(
                    'Charge utile du webhook invalide.',
                    422,
                    'AUTOMATION_WEBHOOK_PAYLOAD_INVALID',
                    $spaceId,
                    'Jeton webhook présent dans la query et le corps (scénario #' . $scenarioId . ').',
                );
            }

            $token = $queryToken !== '' ? $queryToken : $bodyToken;
        } else {
            $header = $request->header('Authorization', '');
            $header = is_string($header) ? trim($header) : '';

            if (preg_match('/\ABearer\s+(.+)\z/i', $header, $matches) === 1) {
                $token = trim($matches[1]);
            }
        }

        $stored = (string) ($trigger->secret_hash ?? '');

        if ($stored === '' || $token === ''
            || !hash_equals($stored, hash('sha256', $token))) {
            $this->fail(
                'Authentification du webhook refusée.',
                401,
                'AUTOMATION_WEBHOOK_AUTH_FAILED',
                $spaceId,
                'Jeton webhook invalide (scénario #' . $scenarioId . ', mode ' . $mode . ').',
            );
        }
    }

    private function authenticateHmac(
        object $trigger,
        Request $request,
        int $spaceId,
        int $scenarioId,
    ): void {
        $timestamp = $request->header('X-Tycms-Timestamp', '');
        $timestamp = is_string($timestamp) ? trim($timestamp) : '';

        if ($timestamp === '' || !ctype_digit($timestamp)
            || abs(time() - (int) $timestamp) > 300) {
            $this->fail(
                'Authentification du webhook refusée.',
                401,
                'AUTOMATION_WEBHOOK_AUTH_FAILED',
                $spaceId,
                'Horodatage HMAC absent ou hors fenêtre (scénario #' . $scenarioId . ').',
            );
        }

        $signature = $request->header('X-Tycms-Signature', '');
        $signature = is_string($signature) ? strtolower(trim($signature)) : '';
        $signature = str_starts_with($signature, 'sha256=')
            ? substr($signature, 7)
            : $signature;

        $secret = $this->getSharedExtensionsClass(WebhookSecrets::class)->hmacSecret($trigger);

        if ($secret === null || $signature === '') {
            $this->fail(
                'Authentification du webhook refusée.',
                401,
                'AUTOMATION_WEBHOOK_AUTH_FAILED',
                $spaceId,
                'Secret ou signature HMAC indisponible (scénario #' . $scenarioId . ').',
            );
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $request->rawBody, $secret);

        if (!hash_equals($expected, $signature)) {
            $this->fail(
                'Authentification du webhook refusée.',
                401,
                'AUTOMATION_WEBHOOK_AUTH_FAILED',
                $spaceId,
                'Signature HMAC invalide (scénario #' . $scenarioId . ').',
            );
        }
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, string $mode, int $spaceId, int $scenarioId): array
    {
        $rawBody = $request->rawBody;

        if ($request->method === RequestMethod::GET) {
            $payload = $request->query;
        } else {
            $payload = $request->body;

            // Le mode query accepte aussi une charge utile uniquement en query
            // sur un POST sans corps, pour les intégrations les plus simples.
            if ($mode === WebhookAuthMode::QUERY && $payload === [] && $rawBody === '') {
                $payload = $request->query;
            }

            // Un JSON invalide ne doit pas devenir une map vide silencieuse.
            if ($payload === [] && $rawBody !== '') {
                $decoded = json_decode($rawBody, true);
                $contentType = strtolower((string) $request->header('Content-Type', ''));
                $contentType = trim(explode(';', $contentType, 2)[0]);
                $isJson = $contentType === 'application/json'
                    || str_ends_with($contentType, '+json');

                if (is_array($decoded)) {
                    $payload = $decoded;
                } elseif ($isJson) {
                    $this->fail(
                        'Charge utile du webhook invalide.',
                        422,
                        'AUTOMATION_WEBHOOK_PAYLOAD_INVALID',
                        $spaceId,
                        'Corps JSON webhook illisible (scénario #' . $scenarioId . ').',
                    );
                } else {
                    parse_str($rawBody, $parsed);

                    if ($parsed === []) {
                        $this->fail(
                            'Charge utile du webhook invalide.',
                            422,
                            'AUTOMATION_WEBHOOK_PAYLOAD_INVALID',
                            $spaceId,
                            'Corps webhook illisible (scénario #' . $scenarioId . ').',
                        );
                    }

                    $payload = $parsed;
                }
            }
        }

        unset($payload[WebhookConfig::TOKEN_FIELD]);

        if ($payload !== [] && array_is_list($payload)) {
            $this->fail(
                'Charge utile du webhook invalide.',
                422,
                'AUTOMATION_WEBHOOK_PAYLOAD_INVALID',
                $spaceId,
                'La charge utile webhook doit être une map (scénario #' . $scenarioId . ').',
            );
        }

        return $payload;
    }

    private function fail(
        string $message,
        int $status,
        string $code,
        ?int $spaceId,
        string $logMessage,
    ): never {
        $this->logWarning(
            $logMessage,
            $spaceId !== null && $spaceId > 0 ? ['space_id' => $spaceId] : [],
            $code,
            'automation',
        );

        throw new ExceptionResponse(new ApiResponse([
            'error' => true,
            'message' => $message,
        ], $status));
    }
}
