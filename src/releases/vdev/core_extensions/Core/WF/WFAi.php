<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use RuntimeException;
use Throwable;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\AiProvider;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\WF\BaseWF;

/** Authorisation, Space configuration and stateless AI batch continuation. */
class WFAi extends BaseWF
{
    use TraitAllHelpers;

    private const USE_CASES = ['decision', 'proofreading', 'design', 'image_generation'];
    private const PROVIDER_TYPES = ['openai', 'openai_compatible', 'openrouter', 'infomaniak', 'anthropic'];
    private const BATCH_PURPOSE = 'ai-batch:v1';

    /** @return array<string,mixed> */
    public function getConfiguration(int $spaceId): array
    {
        $space = $this->adminSpace($spaceId);
        return [
            'spaceId' => $spaceId,
            'providers' => array_map($this->providerView(...), $this->coreAiProviderHelper->listForSpace($spaceId)),
            'models' => $this->models($space),
        ];
    }

    /** @return array{imageGeneration:bool} */
    public function getCapabilities(int $spaceId): array
    {
        $space = $this->userSpace($spaceId);
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::AI_GENERATE_IMAGE, $spaceId)) {
            return ['imageGeneration' => false];
        }
        $selection = $this->models($space)['image_generation'] ?? null;
        if ($selection === null || trim($selection['model']) === '') return ['imageGeneration' => false];
        $provider = $this->coreAiProviderHelper->get($selection['provider_id']);
        return ['imageGeneration' => $provider !== null && $provider->enabled
            && $provider->space_id === $spaceId && $this->supports($provider, 'image_generation')];
    }

    /** @param object $data */
    public function createProvider(int $spaceId, object $data): int
    {
        $this->adminSpace($spaceId);
        $type = (string) ($data->type ?? '');
        if (!in_array($type, self::PROVIDER_TYPES, true)) ExceptionResponse::throwWarning('Type de fournisseur IA invalide.');
        $values = $this->providerValues($spaceId, $type, $data, null);
        return $this->coreAiProviderHelper->create($values);
    }

    public function updateProvider(int $providerId, object $data): void
    {
        $provider = $this->adminProvider($providerId);
        if (isset($data->type) && $data->type !== $provider->type) {
            ExceptionResponse::throwWarning('Le type de fournisseur ne peut pas être modifié.');
        }
        $values = $this->providerValues($provider->space_id, $provider->type, $data, $provider);
        $this->coreAiProviderHelper->update($providerId, $values);
    }

    public function deleteProvider(int $providerId): void
    {
        $provider = $this->adminProvider($providerId);
        $space = $this->adminSpace($provider->space_id);
        foreach ($this->models($space) as $selection) {
            if (($selection['provider_id'] ?? null) === $providerId) {
                ExceptionResponse::throwWarning('Ce fournisseur est encore sélectionné dans l’espace.');
            }
        }
        $this->coreAiProviderHelper->delete($providerId);
    }

    public function saveModels(int $spaceId, object $submitted): void
    {
        $space = $this->adminSpace($spaceId);
        $models = [];
        foreach (self::USE_CASES as $useCase) {
            $selection = $submitted->$useCase ?? null;
            if ($selection === null) continue;
            if (!is_object($selection)) ExceptionResponse::throwWarning('Configuration IA invalide.');
            $providerId = $selection->provider_id ?? null;
            $model = trim((string) ($selection->model ?? ''));
            if (!is_int($providerId) || $providerId < 1 || $model === '' || strlen($model) > 200) {
                ExceptionResponse::throwWarning('Fournisseur ou modèle IA invalide.');
            }
            $provider = $this->coreAiProviderHelper->get($providerId);
            if ($provider === null || $provider->space_id !== $spaceId || !$this->supports($provider, $useCase)) {
                ExceptionResponse::throwWarning('Ce fournisseur ne convient pas au cas d’usage.');
            }
            $models[$useCase] = ['provider_id' => $providerId, 'model' => $model];
        }
        $params = $this->params($space);
        $params['ai_models'] = $models;
        $this->coreAiProviderHelper->saveSpaceModels($spaceId, $params);
        $this->coreSpaceHelper->load();
    }

    /** @param object $input @return array<string,mixed> */
    public function run(int $spaceId, string $useCase, object $input): array
    {
        $space = $this->userSpace($spaceId);
        if (!in_array($useCase, self::USE_CASES, true)) ExceptionResponse::throwWarning('Cas d’usage IA invalide.');
        if ($useCase === 'image_generation') $this->requireImagePermission($spaceId);
        $selection = $this->models($space)[$useCase] ?? null;
        if ($selection === null) ExceptionResponse::throwWarning('Aucun modèle IA configuré pour cet usage.');
        $provider = $this->coreAiProviderHelper->get($selection['provider_id']);
        if ($provider === null || !$provider->enabled || $provider->space_id !== $spaceId || !$this->supports($provider, $useCase)) {
            ExceptionResponse::throwWarning('Fournisseur IA indisponible.');
        }
        $operation = match ($useCase) {
            'decision' => 'decision', 'image_generation' => 'image', default => 'llm',
        };
        $payload = $this->validateInput($operation, $input);
        if ($operation === 'image' && $provider->type === 'infomaniak' && mb_strlen($payload['prompt']) > 400) {
            ExceptionResponse::throwWarning('Le prompt image Infomaniak ne doit pas dépasser 400 caractères.');
        }

        // Situer aussi les causes internes, sans journaliser le prompt, le modèle ou la clé.
        $this->logger->setContext(['space_id' => $spaceId, 'provider_id' => $provider->id,
            'source' => $provider->type, 'kind' => $operation, 'mode' => 'run']);

        try {
            $response = $this->coreAiGatewayHelper->run($provider, $operation, $selection['model'], $payload);
        } catch (RuntimeException $error) {
            ExceptionResponse::throwWarning($operation === 'image'
                ? $error->getMessage() : 'Appel au fournisseur IA impossible.', $error);
        }
        if (($response['status'] ?? null) === 'pending') {
            $providerBatchId = $response['providerBatchId'] ?? null;
            if (!is_string($providerBatchId) || $providerBatchId === '' || strlen($providerBatchId) > 500) {
                ExceptionResponse::throwWarning('Identifiant de batch invalide.', new LogEventException(
                    'AI_BATCH_ID_INVALID', 'Le fournisseur IA a renvoyé un identifiant de batch invalide.', 'ai', level: 'ERROR',
                ));
            }
            return ['status' => 'pending', 'batchId' => $this->encodeBatch($provider, $operation, $providerBatchId)];
        }
        return $response;
    }

    /** @return array<string,mixed> */
    public function poll(string $batchId): array
    {
        try {
            $decoded = json_decode($this->coreCryptHelper->decrypt($batchId, self::BATCH_PURPOSE), true, 16, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            ExceptionResponse::throwWarning('Identifiant de batch IA invalide.');
        }
        if (!is_array($decoded) || ($decoded['v'] ?? null) !== 1
            || !is_int($decoded['space_id'] ?? null) || !is_int($decoded['provider_id'] ?? null)
            || !is_int($decoded['user_id'] ?? null) || !is_int($decoded['expires_at'] ?? null)
            || !is_string($decoded['provider_batch_id'] ?? null) || !is_string($decoded['operation'] ?? null)
            || $decoded['expires_at'] < time() || $decoded['user_id'] !== $this->session->getUserId()) {
            ExceptionResponse::throwWarning('Identifiant de batch IA expiré ou invalide.');
        }
        $this->userSpace($decoded['space_id']);
        if ($decoded['operation'] === 'image') $this->requireImagePermission($decoded['space_id']);
        $provider = $this->coreAiProviderHelper->get($decoded['provider_id']);
        if ($provider === null || !$provider->enabled || $provider->space_id !== $decoded['space_id']) {
            ExceptionResponse::throwWarning('Fournisseur IA indisponible.');
        }

        // Ne jamais conserver l'identifiant opaque du batch, même pour un échec distant.
        $this->logger->setContext(['space_id' => $provider->space_id, 'provider_id' => $provider->id,
            'source' => $provider->type, 'kind' => $decoded['operation'], 'mode' => 'poll']);

        try {
            $result = $this->coreAiGatewayHelper->poll($provider, $decoded['provider_batch_id'], $decoded['operation']);
        } catch (RuntimeException $error) {
            ExceptionResponse::throwWarning($decoded['operation'] === 'image'
                ? $error->getMessage() : 'Consultation du batch IA impossible.', $error);
        }

        if (($result['status'] ?? null) === 'failed') {
            $this->logWarning('Le traitement différé IA a échoué ou a été annulé chez le fournisseur.', [], 'AI_BATCH_FAILED', 'ai');
        }

        return $result;
    }

    private function encodeBatch(AiProvider $provider, string $operation, string $providerBatchId): string
    {
        return $this->coreCryptHelper->encrypt(json_encode([
            'v' => 1, 'space_id' => $provider->space_id, 'provider_id' => $provider->id,
            'user_id' => $this->session->getUserId(), 'operation' => $operation,
            'provider_batch_id' => $providerBatchId, 'expires_at' => time() + 7 * 86400,
        ], JSON_THROW_ON_ERROR), self::BATCH_PURPOSE);
    }

    private function userSpace(int $spaceId): Space
    {
        $space = $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null;
        if ($space === null) ExceptionResponse::throwWarning('Espace introuvable.');
        if ($this->session->getUserId() === null || !$this->coreAclHelper->hasGlobalPermission(Permissions::AI_USE, $spaceId)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas utiliser l’IA dans cet espace.');
        }
        return $space;
    }

    private function requireImagePermission(int $spaceId): void
    {
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::AI_GENERATE_IMAGE, $spaceId)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas générer d’images avec l’IA dans cet espace.');
        }
    }

    private function adminSpace(int $spaceId): Space
    {
        $space = $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null;
        if ($space === null) ExceptionResponse::throwWarning('Espace introuvable.');
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_ADMIN, $spaceId)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas configurer l’IA dans cet espace.');
        }
        return $space;
    }

    private function adminProvider(int $providerId): AiProvider
    {
        $provider = $this->coreAiProviderHelper->get($providerId);
        if ($provider === null) ExceptionResponse::throwWarning('Fournisseur IA introuvable.');
        $this->adminSpace($provider->space_id);
        return $provider;
    }

    /** @return array<string,mixed> */
    private function providerView(AiProvider $provider): array
    {
        return ['id' => $provider->id, 'space_id' => $provider->space_id, 'name' => $provider->name,
            'type' => $provider->type, 'base_url' => $provider->base_url, 'enabled' => $provider->enabled,
            'config' => json_decode($provider->config, true) ?: [], 'has_key' => $provider->secret_data !== ''];
    }

    /** @return array<string,mixed> */
    private function providerValues(int $spaceId, string $type, object $data, ?AiProvider $existing): array
    {
        $name = trim((string) ($data->name ?? ''));
        if ($name === '' || strlen($name) > 150) ExceptionResponse::throwWarning('Nom de fournisseur IA invalide.');
        $baseUrl = trim((string) ($data->base_url ?? $existing?->base_url ?? ''));
        $baseUrl = $baseUrl === '' ? match ($type) {
            'openai' => 'https://api.openai.com/v1',
            'openrouter' => 'https://openrouter.ai/api/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'infomaniak' => 'https://api.infomaniak.com',
            default => '',
        } : rtrim($baseUrl, '/');
        if (!$this->validBaseUrl($type, $baseUrl)) ExceptionResponse::throwWarning('URL du fournisseur IA invalide.');
        $config = $data->config ?? ($existing === null ? new \stdClass() : json_decode($existing->config));
        if (!is_object($config) || strlen(json_encode($config, JSON_THROW_ON_ERROR)) > 8192) {
            ExceptionResponse::throwWarning('Configuration du fournisseur IA invalide.');
        }
        if ($type === 'infomaniak' && (!is_int($config->product_id ?? null) || $config->product_id < 1)) {
            ExceptionResponse::throwWarning('Product ID Infomaniak obligatoire.');
        }
        $key = (string) ($data->api_key ?? '');
        if ($key !== '' && (strlen($key) > 8192 || preg_match('/[\r\n]/', $key))) {
            ExceptionResponse::throwWarning('Clé API invalide.');
        }
        if ($key === '' && $existing === null) ExceptionResponse::throwWarning('La clé API est obligatoire.');
        $secret = $key === '' ? $existing->secret_data : $this->coreCryptHelper->encrypt($key, 'ai-provider-key:v1');
        return ['space_id' => $spaceId, 'name' => $name, 'type' => $type, 'base_url' => $baseUrl,
            'enabled' => (int) ($data->enabled ?? $existing?->enabled ?? false),
            'config' => json_encode($config, JSON_THROW_ON_ERROR), 'secret_data' => $secret];
    }

    private function validBaseUrl(string $type, string $url): bool
    {
        if (strlen($url) > 500 || !filter_var($url, FILTER_VALIDATE_URL)) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])
            || isset($parts['query']) || isset($parts['fragment'])) return false;
        $host = strtolower($parts['host']);
        return match ($type) {
            'openai' => $url === 'https://api.openai.com/v1',
            'openrouter' => $url === 'https://openrouter.ai/api/v1',
            'anthropic' => $url === 'https://api.anthropic.com/v1',
            'infomaniak' => in_array($url, ['https://api.infomaniak.com', 'https://api.infomaniak.com/1/ai'], true),
            'openai_compatible' => $host !== 'localhost' && !str_ends_with($host, '.localhost')
                && filter_var($host, FILTER_VALIDATE_IP) === false && str_contains($host, '.'),
            default => false,
        };
    }

    private function supports(AiProvider $provider, string $useCase): bool
    {
        return match ($useCase) {
            'decision' => $provider->type === 'openrouter',
            'image_generation' => $provider->type !== 'anthropic',
            default => true,
        };
    }

    /** @return array<string,mixed> */
    private function params(Space $space): array
    {
        return is_object($space->params) ? get_object_vars($space->params) : (is_array($space->params) ? $space->params : []);
    }

    /** @return array<string,array{provider_id:int,model:string}> */
    private function models(Space $space): array
    {
        $models = $this->params($space)['ai_models'] ?? [];
        $models = is_object($models) ? get_object_vars($models) : $models;
        if (!is_array($models)) return [];
        $result = [];
        foreach (self::USE_CASES as $key) {
            $entry = $models[$key] ?? null;
            $entry = is_object($entry) ? get_object_vars($entry) : $entry;
            if (is_array($entry) && is_int($entry['provider_id'] ?? null) && is_string($entry['model'] ?? null)) {
                $result[$key] = ['provider_id' => $entry['provider_id'], 'model' => $entry['model']];
            }
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function validateInput(string $operation, object $input): array
    {
        if ($operation === 'image') {
            $prompt = trim((string) ($input->prompt ?? ''));
            if ($prompt === '' || strlen($prompt) > 4000) ExceptionResponse::throwWarning('Prompt image invalide.');
            return ['prompt' => $prompt];
        }
        if ($operation === 'decision') {
            if (!isset($input->state, $input->questions) || !is_object($input->state) || !is_object($input->questions)
                || get_object_vars($input->questions) === []) ExceptionResponse::throwWarning('Questions de décision invalides.');
            if (count(get_object_vars($input->questions)) > 20) ExceptionResponse::throwWarning('Trop de questions de décision.');
            foreach (get_object_vars($input->questions) as $name => $question) {
                if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', $name) || !is_object($question)
                    || !in_array($question->type ?? null, ['choice', 'noul', 'score'], true)
                    || !is_string($question->instructions ?? null)
                    || trim($question->instructions) === '' || !isset($question->criteria)
                    || ($question->type === 'score' ? !is_array($question->criteria) : !is_object($question->criteria))) {
                    ExceptionResponse::throwWarning('Question de décision invalide.');
                }
            }
            $payload = ['state' => $input->state, 'questions' => $input->questions];
            if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 100_000) ExceptionResponse::throwWarning('Requête de décision trop volumineuse.');
            return $payload;
        }
        $messages = $input->messages ?? null;
        if (!is_array($messages) || $messages === [] || count($messages) > 100) ExceptionResponse::throwWarning('Messages IA invalides.');
        foreach ($messages as $message) {
            if (!is_object($message) || !in_array($message->role ?? null, ['system', 'user', 'assistant', 'tool'], true)
                || (!is_string($message->content ?? null) && !($message->role === 'assistant'
                    && ($message->content ?? null) === null && is_array($message->tool_calls ?? null)))) {
                ExceptionResponse::throwWarning('Message IA invalide.');
            }
            if ($message->role === 'tool' && (!is_string($message->tool_call_id ?? null) || $message->tool_call_id === '')) {
                ExceptionResponse::throwWarning('Résultat de Tool invalide.');
            }
            if (isset($message->tool_calls)) {
                if ($message->role !== 'assistant' || !is_array($message->tool_calls) || count($message->tool_calls) > 30) {
                    ExceptionResponse::throwWarning('Appels de Tools invalides.');
                }
                foreach ($message->tool_calls as $call) {
                    if (!is_object($call) || !is_string($call->id ?? null)
                        || !is_object($call->function ?? null)
                        || !is_string($call->function->name ?? null)
                        || !is_string($call->function->arguments ?? null)) {
                        ExceptionResponse::throwWarning('Appel de Tool invalide.');
                    }
                }
            }
        }
        $payload = ['messages' => json_decode(json_encode($messages, JSON_THROW_ON_ERROR), true, 32, JSON_THROW_ON_ERROR)];
        if (isset($input->tools)) {
            if (!is_array($input->tools) || count($input->tools) > 30) ExceptionResponse::throwWarning('Tools IA invalides.');
            foreach ($input->tools as $tool) {
                if (!is_object($tool) || ($tool->type ?? null) !== 'function' || !is_object($tool->function ?? null)
                    || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', (string) ($tool->function->name ?? ''))
                    || !is_object($tool->function->parameters ?? null)) ExceptionResponse::throwWarning('Tool IA invalide.');
            }
            // Keep JSON Schema objects intact: decoding as associative arrays
            // turns an empty "properties": {} into "properties": [].
            $payload['tools'] = [];
            foreach ($input->tools as $tool) {
                $function = [
                    'name' => $tool->function->name,
                    'parameters' => $tool->function->parameters,
                ];
                if (isset($tool->function->description)) {
                    if (!is_string($tool->function->description)) ExceptionResponse::throwWarning('Description de Tool IA invalide.');
                    $function['description'] = $tool->function->description;
                }
                $payload['tools'][] = ['type' => 'function', 'function' => $function];
            }
        }
        if (isset($input->max_tokens)) {
            if (!is_int($input->max_tokens) || $input->max_tokens < 1 || $input->max_tokens > 8192) {
                ExceptionResponse::throwWarning('Limite de tokens invalide.');
            }
            $payload['max_tokens'] = $input->max_tokens;
        }
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 300_000) ExceptionResponse::throwWarning('Requête IA trop volumineuse.');
        return $payload;
    }
}
