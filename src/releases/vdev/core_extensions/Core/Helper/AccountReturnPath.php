<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

/** Les retours SSO/OAuth ne quittent jamais l'origine du composant. */
final class AccountReturnPath
{
    public static function validate(string $path): string
    {
        $decoded = rawurldecode($path);
        foreach ([$path, $decoded] as $candidate) {
            if (strlen($candidate) > 2048 || !str_starts_with($candidate, '/')
                || str_starts_with($candidate, '//') || preg_match('/[\\\\\x00-\x20\x7f]/', $candidate)) {
                throw new \InvalidArgumentException('Destination de retour invalide.');
            }
        }

        return $path;
    }
}
