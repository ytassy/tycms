<?php

namespace YTassy\Tycms\CoreExts\Security\Waf;

use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Response\RespLevel;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Tools\HtmlTools;

/** Identifier le WAF sans exposer ni motif, ni valeur reçue, ni détails du moteur. */
final class BlockedResponse
{
    public static function create(Route $route, Request $request, string $message): Response
    {
        $message = '[WAF] ' . $message;
        if ($route->isApi || $request->isJsonExpected()) {
            $response = new ApiResponse(['error' => true, 'is_message' => true,
                'message_level' => RespLevel::DANGER, 'message' => $message,
                'source' => 'waf', 'error_code' => 'WAF_BLOCKED'], 403);
        } else {
            $response = new Response(['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store'],
                '<!doctype html><html lang="fr"><head><meta charset="utf-8"><title>403 — WAF</title></head>'
                . '<body><main><h1>403 — Requête bloquée par le WAF</h1><p>' . HtmlTools::escape($message)
                . '</p></main></body></html>', 403);
        }
        $response->setHeaders(['X-Tycms-Blocked-By' => 'WAF']);
        return $response;
    }
}
