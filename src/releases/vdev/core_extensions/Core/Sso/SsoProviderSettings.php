<?php

namespace YTassy\Tycms\CoreExts\Core\Sso;

use InvalidArgumentException;
use JsonException;
use stdClass;

/** Validation of the editable, non-secret provider documents. */
final class SsoProviderSettings
{
    public const MAX_JSON_BYTES = 32768;

    public static function parseObject(string $json, string $label): stdClass
    {
        if (strlen($json) > self::MAX_JSON_BYTES) {
            throw new InvalidArgumentException("Le JSON « $label » dépasse 32 Ko.");
        }
        try {
            $value = json_decode($json, false, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("Le JSON « $label » est invalide.");
        }
        if (!$value instanceof stdClass) {
            throw new InvalidArgumentException("Le JSON « $label » doit être un objet.");
        }
        self::assertNoSecrets($value);
        return $value;
    }

    private static function assertNoSecrets(stdClass|array $value): void
    {
        foreach ((array) $value as $key => $item) {
            $normalisedKey = is_string($key) ? preg_replace('/[^a-z0-9]/', '', strtolower($key)) : '';
            if (in_array($normalisedKey, [
                'clientsecret', 'accesstoken', 'refreshtoken', 'idtoken',
                'privatekey', 'password', 'apikey',
            ], true)) {
                throw new InvalidArgumentException('Les secrets et jetons doivent être saisis dans le champ secret dédié, jamais dans la configuration JSON.');
            }
            if ($item instanceof stdClass || is_array($item)) self::assertNoSecrets($item);
        }
    }

    /** @param array<string,mixed> $config @param array<string,mixed> $mapping */
    public static function assertActiveGoogle(array $config, array $mapping): void
    {
        if (!is_string($config['client_id'] ?? null) || trim($config['client_id']) === '') {
            throw new InvalidArgumentException('Le Client ID Google est obligatoire pour activer la connexion.');
        }
        $uris = $config['redirect_uris'] ?? (isset($config['redirect_uri']) ? [$config['redirect_uri']] : []);
        if (!is_array($uris) || !array_is_list($uris) || $uris === []) {
            throw new InvalidArgumentException('Indiquez au moins une URI de retour Google.');
        }
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::validRedirectUri($uri)) {
                throw new InvalidArgumentException('Chaque URI de retour doit viser /sso/callback sur un hôte HTTPS, ou sur localhost en HTTP.');
            }
        }
        if (isset($config['hosted_domain']) && (!is_string($config['hosted_domain']) || trim($config['hosted_domain']) === '')) {
            throw new InvalidArgumentException('Le domaine Workspace doit être une chaîne non vide.');
        }
        foreach (['subject', 'email', 'email_verified', 'first_name', 'last_name'] as $key) {
            if ($key !== 'subject' && !array_key_exists($key, $mapping)) continue;
            if (!is_string($mapping[$key] ?? null)
                || preg_match('/^claims(?:\.[a-zA-Z_][a-zA-Z_0-9]*)+$/D', $mapping[$key]) !== 1) {
                throw new InvalidArgumentException("Le mapping Google « $key » doit désigner un chemin claims.* valide.");
            }
        }
    }

    /** @param array<string,mixed> $config */
    public static function assertActiveGeneric(array $config, string $protocol): void
    {
        if (($config['driver'] ?? null) !== 'generic' && ($config['driver'] ?? null) !== 'custom') {
            throw new InvalidArgumentException('Le pilote générique est obligatoire.');
        }
        if (!in_array($protocol, ['oidc', 'oauth2'], true)
            || !is_string($config['client_id'] ?? null) || trim($config['client_id']) === '') {
            throw new InvalidArgumentException('Client ID OAuth invalide.');
        }
        foreach (['authorization_endpoint', 'token_endpoint'] as $key) {
            if (!self::validHttpsEndpoint($config[$key] ?? null)) {
                throw new InvalidArgumentException("Endpoint OAuth invalide : $key.");
            }
        }
        if ($protocol === 'oidc') {
            foreach (['issuer', 'jwks_uri'] as $key) {
                if (!self::validHttpsEndpoint($config[$key] ?? null)) {
                    throw new InvalidArgumentException("Endpoint OIDC invalide : $key.");
                }
            }
        }
        $uris = $config['redirect_uris'] ?? (isset($config['redirect_uri']) ? [$config['redirect_uri']] : []);
        if (!is_array($uris) || !array_is_list($uris) || $uris === []) {
            throw new InvalidArgumentException('Une URI de retour OAuth est obligatoire.');
        }
        $path = $protocol === 'oidc' ? '/sso/callback' : '/integrations/oauth/callback';
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::validRedirectUri($uri, $path)) {
                throw new InvalidArgumentException("URI de retour OAuth invalide : $path attendu.");
            }
        }
        if (isset($config['scopes']) && (!is_array($config['scopes']) || !array_is_list($config['scopes'])
            || count($config['scopes']) > 30 || array_filter($config['scopes'], static fn ($scope) =>
                !is_string($scope) || preg_match('/^[\x21\x23-\x5B\x5D-\x7E]{1,150}$/D', $scope) !== 1))) {
            throw new InvalidArgumentException('Liste de scopes OAuth invalide.');
        }
    }

    /** @param array<string,mixed> $mapping */
    public static function assertOidcMapping(array $mapping): void
    {
        foreach (['subject', 'email', 'email_verified', 'first_name', 'last_name'] as $key) {
            if ($key !== 'subject' && !array_key_exists($key, $mapping)) continue;
            if (!is_string($mapping[$key] ?? null)
                || preg_match('/^claims(?:\.[a-zA-Z_][a-zA-Z_0-9]*)+$/D', $mapping[$key]) !== 1) {
                throw new InvalidArgumentException("Mapping OIDC invalide : $key.");
            }
        }
    }

    private static function validHttpsEndpoint(mixed $uri): bool
    {
        if (!is_string($uri) || preg_match('/[\x00-\x20\x7F]/', $uri)) return false;
        $parts = parse_url($uri);
        return is_array($parts) && ($parts['scheme'] ?? null) === 'https'
            && is_string($parts['host'] ?? null) && $parts['host'] !== ''
            && preg_match('/^[a-z0-9.-]+$/iD', $parts['host']) === 1
            && filter_var($parts['host'], FILTER_VALIDATE_IP) === false
            && strtolower($parts['host']) !== 'localhost'
            && !str_ends_with(strtolower($parts['host']), '.local')
            && !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['fragment']);
    }

    private static function validRedirectUri(string $uri, string $path = '/sso/callback'): bool
    {
        if (preg_match('/[\x00-\x20\x7F]/', $uri)) return false;
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || ($parts['path'] ?? null) !== $path
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) return false;
        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true));
    }
}
