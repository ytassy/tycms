<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Ai;

use RuntimeException;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\CoreExts\Core\Models\AiProvider;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Technical HTTP adapters. No browser-supplied URL, key or model reaches this class. */
class AiGatewayHelper extends BaseHelper
{
    private const MAX_RESPONSE_BYTES = 20_000_000;
    private const MAX_IMAGE_BYTES = 10_000_000;

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function run(AiProvider $provider, string $operation, string $model, array $input): array
    {
        $base = rtrim($provider->base_url, '/');
        $headers = $this->headers($provider);
        if ($operation === 'decision') {
            if ($provider->type !== 'openrouter') throw $this->failure('AI_OPERATION_UNSUPPORTED', 'Ce fournisseur ne prend pas en charge les décisions.');
            $url = 'https://openrouter.ai/api/alpha/decisions';
            $body = ['model' => $model, 'state' => $input['state'], 'questions' => $input['questions']];
        } elseif ($operation === 'image') {
            if ($provider->type === 'anthropic') throw $this->failure('AI_OPERATION_UNSUPPORTED', 'Ce fournisseur ne génère pas d’images par cette API.');
            $url = match ($provider->type) {
                'openrouter' => $base . '/images',
                'infomaniak' => $this->infomaniakProductBase($provider, 1) . '/openai/images/generations',
                default => $base . '/images/generations',
            };
            $body = ['model' => $model, 'prompt' => $input['prompt']];
        } elseif ($operation === 'llm') {
            if ($provider->type === 'anthropic') {
                $url = $base . '/messages';
                $body = $this->anthropicRequest($model, $input);
            } else {
                $url = match ($provider->type) {
                    'infomaniak' => $this->infomaniakProductBase($provider, 2) . '/openai/v1/chat/completions',
                    'openrouter' => 'https://openrouter.ai/api/v1/chat/completions',
                    default => $base . '/chat/completions',
                };
                $body = ['model' => $model, 'messages' => $input['messages'], 'stream' => false];
                if (isset($input['tools'])) $body['tools'] = $input['tools'];
                if (isset($input['max_tokens'])) $body['max_tokens'] = $input['max_tokens'];
            }
        } else {
            throw $this->failure('AI_OPERATION_UNSUPPORTED', 'Opération IA inconnue.');
        }

        $result = $this->request('POST', $url, $headers, $body);
        return $this->normalise($provider->type, $operation, $result);
    }

    /** @return array<string,mixed> */
    public function poll(AiProvider $provider, string $providerBatchId, string $operation): array
    {
        if ($provider->type !== 'infomaniak') {
            throw $this->failure('AI_OPERATION_UNSUPPORTED', 'La consultation différée de ce fournisseur n’est pas disponible.');
        }
        $url = $this->infomaniakProductBase($provider, 1) . '/results/' . rawurlencode($providerBatchId);
        $result = $this->request('GET', $url, $this->headers($provider));
        $status = $result['status'] ?? null;
        if (in_array($status, ['pending', 'processing'], true)) return ['status' => 'pending'];
        if ($status === 'success') {
            return ['status' => 'completed', 'result' => $operation === 'image'
                ? $this->imageResult($result['data'] ?? null, $result['usage'] ?? null)
                : ($result['data'] ?? $result)];
        }
        if (in_array($status, ['cancelled', 'failed'], true)) return ['status' => 'failed'];
        throw $this->failure('AI_BATCH_STATUS_INVALID', 'Statut de batch inconnu.');
    }

    /** @param array<string,mixed> $response @return array<string,mixed> */
    private function normalise(string $type, string $operation, array $response): array
    {
        $batchId = $response['batch_id'] ?? $response['batchId'] ?? null;
        if (is_string($batchId) && $batchId !== '') return ['status' => 'pending', 'providerBatchId' => $batchId];
        if ($operation === 'decision') {
            if (!isset($response['answers']) || !is_array($response['answers'])) throw $this->failure('AI_RESPONSE_INVALID', 'Réponse de décision invalide.');
            return ['status' => 'completed', 'result' => ['answers' => $response['answers'], 'usage' => $response['usage'] ?? null]];
        }
        if ($operation === 'image') {
            return ['status' => 'completed', 'result' => $this->imageResult($response['data'] ?? null, $response['usage'] ?? null)];
        }
        if ($type === 'anthropic') {
            $blocks = $response['content'] ?? null;
            if (!is_array($blocks)) throw $this->failure('AI_RESPONSE_INVALID', 'Réponse LLM invalide.');
            $text = [];
            $tools = [];
            foreach ($blocks as $block) {
                if (($block['type'] ?? null) === 'text') $text[] = $block['text'] ?? '';
                if (($block['type'] ?? null) === 'tool_use') {
                    $tools[] = ['id' => $block['id'], 'type' => 'function',
                        'function' => ['name' => $block['name'], 'arguments' => json_encode($block['input'], JSON_THROW_ON_ERROR)]];
                }
            }
            $message = ['role' => 'assistant', 'content' => implode("\n", $text), 'tool_calls' => $tools];
        } else {
            $message = $response['choices'][0]['message'] ?? null;
            if (!is_array($message)) throw $this->failure('AI_RESPONSE_INVALID', 'Réponse LLM invalide.');
        }
        return ['status' => 'completed', 'result' => ['message' => $message, 'usage' => $response['usage'] ?? null]];
    }

    /** @return array{images:list<array{b64_json:string,media_type:string}>,usage:mixed} */
    private function imageResult(mixed $data, mixed $usage): array
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            $data = is_array($decoded) ? $decoded : [['b64_json' => $data]];
        }
        if (is_array($data) && isset($data['data'])) $data = $data['data'];
        if (is_array($data) && isset($data['b64_json'])) $data = [$data];
        if (!is_array($data) || !array_is_list($data) || count($data) !== 1 || !is_array($data[0])) {
            throw $this->failure('AI_IMAGE_INVALID', 'La réponse image doit contenir une seule image base64.');
        }
        $image = $data[0];
        if (!is_string($image['b64_json'] ?? null) || $image['b64_json'] === '') {
            throw $this->failure('AI_IMAGE_INVALID', isset($image['url'])
                ? 'Le fournisseur a renvoyé une URL ; seule une image base64 est prise en charge.'
                : 'Le fournisseur n’a pas renvoyé d’image base64.');
        }
        $base64 = $image['b64_json'];
        if (strlen($base64) > (int) ceil(self::MAX_IMAGE_BYTES * 4 / 3) + 4) {
            throw $this->failure('AI_IMAGE_TOO_LARGE', 'Image générée trop volumineuse.');
        }
        $bytes = base64_decode($base64, true);
        if ($bytes === false || $bytes === '' || strlen($bytes) > self::MAX_IMAGE_BYTES) {
            throw $this->failure('AI_IMAGE_INVALID', 'Image générée invalide ou trop volumineuse.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw $this->failure('AI_IMAGE_INVALID', 'Format d’image générée non pris en charge.');
        }
        $dimensions = @getimagesizefromstring($bytes);
        if ($dimensions === false || $dimensions[0] > 8192 || $dimensions[1] > 8192) {
            throw $this->failure('AI_IMAGE_INVALID', 'Dimensions de l’image générée invalides.');
        }
        return ['images' => [['b64_json' => $base64, 'media_type' => $mime]], 'usage' => $usage];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function anthropicRequest(string $model, array $input): array
    {
        $system = [];
        $messages = [];
        foreach ($input['messages'] as $message) {
            if ($message['role'] === 'system') {
                $system[] = $message['content'];
            } elseif ($message['role'] === 'tool') {
                $messages[] = ['role' => 'user', 'content' => [[
                    'type' => 'tool_result', 'tool_use_id' => $message['tool_call_id'], 'content' => $message['content'],
                ]]];
            } elseif ($message['role'] === 'assistant' && !empty($message['tool_calls'])) {
                $blocks = [];
                if (($message['content'] ?? '') !== '') $blocks[] = ['type' => 'text', 'text' => $message['content']];
                foreach ($message['tool_calls'] as $call) {
                    $blocks[] = ['type' => 'tool_use', 'id' => $call['id'], 'name' => $call['function']['name'],
                        'input' => json_decode($call['function']['arguments'], true, 32, JSON_THROW_ON_ERROR)];
                }
                $messages[] = ['role' => 'assistant', 'content' => $blocks];
            } else {
                $messages[] = ['role' => $message['role'], 'content' => $message['content']];
            }
        }
        $body = ['model' => $model, 'max_tokens' => $input['max_tokens'] ?? 2048, 'messages' => $messages];
        if ($system !== []) $body['system'] = implode("\n\n", $system);
        if (!empty($input['tools'])) {
            $body['tools'] = array_map(static fn (array $tool): array => [
                'name' => $tool['function']['name'], 'description' => $tool['function']['description'] ?? '',
                'input_schema' => $tool['function']['parameters'],
            ], $input['tools']);
        }
        return $body;
    }

    /** @return list<string> */
    private function headers(AiProvider $provider): array
    {
        $secret = $this->coreCryptHelper->decrypt($provider->secret_data, 'ai-provider-key:v1');
        $headers = ['Content-Type: application/json'];
        if ($provider->type === 'anthropic') {
            $headers[] = 'x-api-key: ' . $secret;
            $headers[] = 'anthropic-version: 2023-06-01';
        } else {
            $headers[] = 'Authorization: Bearer ' . $secret;
        }
        return $headers;
    }

    private function infomaniakProductBase(AiProvider $provider, int $version): string
    {
        if (!in_array(rtrim($provider->base_url, '/'), ['https://api.infomaniak.com', 'https://api.infomaniak.com/1/ai'], true)) {
            throw $this->failure('AI_CONFIGURATION_INVALID', 'URL Infomaniak invalide.');
        }
        $config = json_decode($provider->config, true);
        $productId = is_array($config) ? ($config['product_id'] ?? null) : null;
        if (!is_int($productId) || $productId < 1) throw $this->failure('AI_CONFIGURATION_INVALID', 'Product ID Infomaniak invalide.');
        return 'https://api.infomaniak.com/' . $version . '/ai/' . $productId;
    }

    /** Codes et libellés contrôlés ; les données du fournisseur restent hors des journaux. */
    private function failure(string $code, string $message, array $context = [], ?\Throwable $previous = null): LogEventException
    {
        return new LogEventException($code, $message, 'ai', $context, 'ERROR', $previous);
    }

    /** @param list<string> $headers @param ?array<string,mixed> $body @return array<string,mixed> */
    protected function request(string $method, string $url, array $headers, ?array $body = null): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') throw $this->failure('AI_CONFIGURATION_INVALID', 'Hôte IA invalide.');
        $dns = dns_get_record($host, DNS_A | DNS_AAAA);
        if (!is_array($dns) || $dns === []) throw $this->failure('AI_DNS_FAILED', 'Hôte IA introuvable.');
        $addresses = [];
        foreach ($dns as $entry) {
            $ip = $entry['ip'] ?? $entry['ipv6'] ?? null;
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                throw $this->failure('AI_ADDRESS_REFUSED', 'Adresse du fournisseur IA non publique.');
            }
            $addresses[] = str_contains($ip, ':') ? '[' . $ip . ']' : $ip;
        }
        $handle = curl_init($url);
        if ($handle === false) throw $this->failure('AI_REQUEST_INIT_FAILED', 'Impossible de préparer la requête IA.');
        try {
            curl_setopt_array($handle, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => $method === 'GET' ? 30 : 120,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_RESOLVE => [$host . ':443:' . $addresses[0]],
            ]);
            if ($body !== null) curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
            $raw = '';
            $tooLarge = false;
            curl_setopt($handle, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$raw, &$tooLarge): int {
                if (strlen($raw) + strlen($chunk) > self::MAX_RESPONSE_BYTES) {
                    $tooLarge = true;
                    return 0;
                }
                $raw .= $chunk;
                return strlen($chunk);
            });
            $received = curl_exec($handle);
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if ($tooLarge) throw $this->failure('AI_RESPONSE_TOO_LARGE', 'Réponse IA trop volumineuse.');
            if ($received === false) {
                // Le code cURL distingue notamment le délai dépassé du TLS, sans curl_error().
                $errno = curl_errno($handle);
                throw $this->failure($errno === CURLE_OPERATION_TIMEDOUT ? 'AI_TIMEOUT' : 'AI_CONNECTION_FAILED',
                    'Connexion au fournisseur IA impossible.', ['curl_errno' => $errno]);
            }
            if ($status < 200 || $status >= 300) {
                $message = 'Le fournisseur IA a renvoyé HTTP ' . $status;
                $reason = '';
                if (strlen($raw) <= 8192) {
                    $error = json_decode($raw, true);
                    $detail = is_array($error) ? ($error['error']['message'] ?? $error['message'] ?? null) : null;

                    // Traduire uniquement des codes connus en libellés fixes, jamais le texte distant.
                    $reason = match (is_array($error) ? ($error['error']['code'] ?? $error['error']['type'] ?? null) : null) {
                        'invalid_api_key', 'authentication_error' => 'La clé API est refusée.',
                        'insufficient_quota' => 'Le quota du fournisseur est épuisé.',
                        'rate_limit_exceeded', 'rate_limit_error' => 'La limite de requêtes du fournisseur est atteinte.',
                        'model_not_found' => 'Le modèle demandé est introuvable ou inaccessible.',
                        'context_length_exceeded' => 'La limite de contexte du modèle est dépassée.',
                        'content_policy_violation' => 'Le fournisseur refuse le contenu selon sa politique.',
                        default => '',
                    };
                    if (is_string($detail)) {
                        $detail = mb_substr(trim(preg_replace('/[\r\n\t]+/', ' ', $detail)), 0, 400);
                        if ($detail !== '') $message .= ' : ' . $detail;
                    }
                }
                // Garder le message public existant, mais seule cette cause contrôlée ira au journal.
                throw new RuntimeException($message . '.', 0, $this->failure(
                    'AI_HTTP_ERROR', trim('Le fournisseur IA a refusé la requête (HTTP ' . $status . '). ' . $reason), ['http_status' => $status],
                ));
            }
            try {
                $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                // Conserver le type d'exception et le traitement public existants ; le runner déduplique la cause.
                $this->logException($this->failure('AI_JSON_INVALID', 'Le fournisseur IA a renvoyé un JSON invalide.', previous: $exception));
                throw $exception;
            }
            if (!is_array($decoded)) throw $this->failure('AI_RESPONSE_INVALID', 'Réponse IA invalide.');
            return $decoded;
        } finally {
            unset($handle);
        }
    }
}
