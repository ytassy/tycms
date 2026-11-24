<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Mcp;

use YTassy\Tycms\Response\Response;

/**
 * Réponses du protocole MCP.
 *
 * Le transport Streamable HTTP répond du JSON-RPC brut : l'habillage
 * `ApiResponse` du CMS (`error`, `is_message`, `message`…) rendrait le message
 * invalide pour un client MCP et n'est donc jamais utilisé ici.
 */
final class McpJsonRpc
{
    /**
     * Réponse JSON-RPC brute.
     *
     * Un identifiant absent produit une réponse sans corps (204), réservée aux
     * notifications et aux requêtes qui n'attendent aucune donnée.
     *
     * @param array<string, mixed> $payload
     */
    public static function respond(array $payload, int $code = 200, array $headers = []): Response
    {
        return new Response(
            array_merge([
                'Content-Type' => 'application/json; charset=utf-8',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ], $headers),
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            $code,
        );
    }

    /** Accusé de réception d'une notification : aucun corps. */
    public static function accepted(array $headers = []): Response
    {
        return new Response(
            array_merge(['Cache-Control' => 'no-store'], $headers),
            null,
            202,
        );
    }

    /** @return array<string, mixed> */
    public static function result(mixed $id, mixed $result): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /** @return array<string, mixed> */
    public static function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * Contenu d'outil MCP : un bloc texte contenant le JSON de la sortie.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function toolContent(array $payload, bool $isError = false): array
    {
        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ]],
            'isError' => $isError,
        ];
    }
}