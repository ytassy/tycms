<?php

namespace YTassy\Tycms\CoreExts\Security\Waf;

/** PCRE peut échouer sans correspondance : conserver explicitement cet état distinct. */
final class BoundedRegex
{
    public static function match(string $pattern, string $subject): int|false
    {
        // Désactiver JIT ici permet aussi d’appliquer la limite de profondeur PCRE.
        // Restaurer les réglages pour ne pas changer les autres traitements de l’application.
        $limits = ['pcre.backtrack_limit' => '10000', 'pcre.recursion_limit' => '100', 'pcre.jit' => '0'];
        $previous = [];
        foreach ($limits as $name => $value) $previous[$name] = ini_set($name, $value);
        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            return preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
            foreach ($previous as $name => $value) {
                if ($value !== false) ini_set($name, $value);
            }
        }
    }
}
