<?php

namespace YTassy\Tycms\Response;


class RedirectResponse extends Response
{

	/**
	 * Codes de statut HTTP de redirection (Série 3xx)
	 *
	 * 301 Moved Permanently   : Redirection permanente. Indexée par les moteurs (SEO).
	 *                           Enregistrée en cache par le navigateur.
	 * 
	 * 302 Found               : Redirection temporaire historique. La méthode HTTP initiale
	 *                           (ex: POST) peut être convertie en GET par le navigateur.
	 * 
	 * 303 See Other           : Redirection temporaire obligatoire en GET.
	 *                           Recommandé après la soumission d'un formulaire (POST)
	 *                           pour éviter le rechargement de données (Pattern PRG).
	 * 
	 * 307 Temporary Redirect  : Redirection temporaire stricte.
	 *                           Garantit que la méthode (POST, PUT, etc.) reste identique.
	 * 
	 * 308 Permanent Redirect  : Redirection permanente stricte.
	 *                           Équivalent moderne du 301 qui conserve la méthode HTTP initiale.
	 */
	function __construct(string $url, int $code = 303, bool $external = false)
    {
		// Une destination interne ne doit jamais être interprétable comme un autre hôte.
		$valid = $external ? self::isExternalUrl($url) : self::isInternalUrl($url);
		if (!$valid) {
			throw new \InvalidArgumentException('Destination de redirection invalide.');
		}

		parent::__construct(
			httpHeaders: ['Location' => $url],
			body: '',
			code: $code
		);
	}

    public static function isInternalUrl(string $url): bool
    {
        return str_starts_with($url, '/') && !str_starts_with($url, '//')
            && !self::hasUnsafeCharacters($url);
    }

    private static function hasUnsafeCharacters(string $url): bool
    {
        return str_contains($url, '\\') || preg_match('/[\x00-\x20\x7f]/', $url) === 1;
    }

    private static function isExternalUrl(string $url): bool
    {
        if (self::hasUnsafeCharacters($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        // Les flux externes choisissent leur fournisseur en amont ; HTTP reste limité au DEV local.
        return !isset($parts['user']) && !isset($parts['pass'])
            && ($scheme === 'https'
                || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1', '[::1]'], true)));
    }

    public static function external(string $url, int $code = 303): self
    {
        return new self($url, $code, external: true);
    }

	public static function default(string $url){
		
		return self::seeOther($url);
	}

	public static function seeOther(string $url){
		return new RedirectResponse($url, 303);
	}

	public static function movedPermanently(string $url){
		return new RedirectResponse($url, 301);
	}

	public static function found(string $url){
		return new RedirectResponse($url, 302);
	}
	
}
