<?php

namespace YTassy\Tycms\CoreExts\Security;

/** Construire les seuls en-têtes maîtrisés par le code, à partir des origines validées. */
final class ResponseHeaders
{
    public static function build(array $sources, bool $development, bool $administration, ?array $developmentOrigins = null, ?string $nonce = null, array $formActions = []): array
    {
        $script = ["'self'"];
        $worker = ["'self'", 'blob:'];
        $connect = ["'self'"];
        $style = ["'self'", "'unsafe-inline'", ...$sources['style']];
        $font = ["'self'", 'data:', ...$sources['font']];

        // Chromium applique form-action à chaque redirection d’un POST de formulaire :
        // le départ SSO vers l’IdP doit donc figurer parmi les destinations autorisées.
        $formAction = ["'self'"];
        foreach ($formActions as $origin) {
            $validOrigin = SecurityPolicy::httpsOrigin($origin);
            if ($validOrigin !== null) $formAction[] = $validOrigin;
        }

        // Exceptions internes limitées à l’administration et au serveur Vite de développement.
        if ($administration) {
            $style[] = 'https://fonts.googleapis.com';
            $font[] = 'https://fonts.gstatic.com';
        }
        if ($nonce !== null) {
            if (!preg_match('~^[A-Za-z0-9+/]{32}$~D', $nonce)) throw new \InvalidArgumentException('Nonce CSP invalide.');
            $script[] = "'nonce-" . $nonce . "'";
        }
        if ($development) {
            foreach ($developmentOrigins ?? ['http://localhost:3000'] as $origin) {
                if (!preg_match('~^http://localhost:[0-9]{4,5}$~D', $origin)) throw new \InvalidArgumentException('Origine Vite invalide.');
                $script[] = $worker[] = $style[] = $font[] = $origin;
                $sources['image'][] = $origin;
                $sources['media'][] = $origin;
                $connect = [...$connect, $origin, str_replace('http:', 'ws:', $origin)];
            }
        }
        $join = static fn (array $values): string => implode(' ', array_unique($values));

        return [
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'", "base-uri 'self'", 'form-action ' . $join($formAction), "frame-ancestors 'self'", "object-src 'none'",
                'script-src ' . $join($script), "script-src-attr 'none'",
                // Video.js construit son worker et ses sources média depuis un Blob, jamais ses scripts.
                'worker-src ' . $join($worker), 'style-src ' . $join($style),
                'img-src ' . $join(["'self'", 'data:', ...$sources['image']]),
                'font-src ' . $join($font), 'connect-src ' . $join($connect),
                'media-src ' . $join(["'self'", 'blob:', ...$sources['media']]),
                'frame-src ' . $join(["'self'", ...$sources['iframe']]),
            ]),
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
            'X-Permitted-Cross-Domain-Policies' => 'none',
            // L’ancien filtre XSS est désactivé : la CSP porte la protection des navigateurs modernes.
            'X-XSS-Protection' => '0',
        ];
    }
}
