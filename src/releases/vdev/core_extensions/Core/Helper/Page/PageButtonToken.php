<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/** Jeton de rendu des boutons d'automatisation publics. */
final class PageButtonToken extends BaseHelper
{
    public function sign(int $pageId, string $nodeId, string $csrfToken): string
    {
        return hash_hmac('sha256', $this->payload($pageId, $nodeId, $csrfToken), $this->secret());
    }

    public function verify(int $pageId, string $nodeId, string $csrfToken, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return hash_equals($this->sign($pageId, $nodeId, $csrfToken), $token);
    }

    private function payload(int $pageId, string $nodeId, string $csrfToken): string
    {
        return $pageId . '|' . $nodeId . '|' . $csrfToken;
    }

    private function secret(): string
    {
        $secret = (string) $this->app->getConf()->secret;

        if ($secret === '') {
            throw new \RuntimeException('Le secret de l’installation est absent.');
        }

        return $secret;
    }
}
