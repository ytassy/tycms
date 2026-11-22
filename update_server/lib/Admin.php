<?php

declare(strict_types=1);

namespace Tycms\Distribution;

use RuntimeException;

/**
 * Session d'administration du mini-UI, indépendante des sessions Tycms.
 *
 * Un seul compte est configuré dans configuration.php : le nom et l'empreinte
 * Argon2id du mot de passe. Le jeton de session est aléatoire, conservé en
 * mémoire du serveur (dossier privé) et transmis par le cookie HttpOnly ; le
 * cookie seul ne prouve donc pas qu'une session a été accordée par ce
 * serveur. La connexion est limitée en tentatives pour freiner une recherche
 * de mot de passe.
 */
final class Admin
{
    public const COOKIE = 'tycms_distribution_admin';

    private const MAX_ATTEMPTS = 5;
    private const ATTEMPT_WINDOW_SECONDS = 900;
    private const SESSION_SECONDS = 28800;

    private string $storage;

    public function __construct(private array $config, string $root)
    {
        $this->storage = $root . '/sessions';
        if (is_link($this->storage) || (!is_dir($this->storage) && !mkdir($this->storage, 0700))) {
            throw new RuntimeException('Dossier de sessions inaccessible.');
        }
        $this->storage = realpath($this->storage) ?: throw new RuntimeException('Dossier de sessions inaccessible.');
        // Le dossier doit rester dans le stockage privé, hors de la racine HTTP.
        if (!str_starts_with($this->storage, $root . '/') && $this->storage !== $root) {
            throw new RuntimeException('Dossier de sessions hors du stockage.');
        }
    }

    /** Nom de compte et empreinte de mot de passe déclarés dans la configuration. */
    public static function configured(array $config): bool
    {
        return is_string($config['admin_user'] ?? null)
            && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $config['admin_user']) === 1
            && is_string($config['admin_password_hash'] ?? null)
            && str_starts_with($config['admin_password_hash'], '$argon2id$');
    }

    /**
     * Jeton CSRF lié à la session courante : dérivé du jeton de session, il
     * n'est pas lisible par un site tiers et évite de stocker un second secret.
     */
    public static function csrf(string $session): string
    {
        return hash('sha256', 'tycms-distribution-csrf:' . $session);
    }

    public static function checkCsrf(string $session, mixed $token): void
    {
        if (!is_string($token) || !hash_equals(self::csrf($session), $token)) {
            throw new HttpError(403, 'Jeton de formulaire invalide.');
        }
    }

    /** Vérifier le couple saisi ; une réussite accorde une nouvelle session. */
    public function login(string $user, string $password): bool
    {
        if (!self::configured($this->config)) {
            throw new RuntimeException('Compte d’administration non configuré.');
        }
        if ($this->attempts() >= self::MAX_ATTEMPTS) {
            throw new HttpError(429, 'Trop de tentatives de connexion ; réessayer plus tard.');
        }

        // Comparer le nom à temps constant et l'empreinte par password_verify.
        $valid = hash_equals($this->config['admin_user'], $user)
            && password_verify($password, $this->config['admin_password_hash']);
        if (!$valid) {
            $this->recordAttempt();
            return false;
        }

        $this->clearAttempts();
        $this->start();

        return true;
    }

    /** Session courante, ou null si le cookie est absent, inconnu ou expiré. */
    public function session(): ?string
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (!is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }
        if (realpath($this->storage . '/' . $token) !== $this->storage . '/' . $token) {
            return null;
        }
        $expires = @file_get_contents($this->storage . '/' . $token);
        if (!is_string($expires) || !ctype_digit($expires) || (int) $expires < time()) {
            return null;
        }

        return $token;
    }

    public function require(): string
    {
        return $this->session() ?? throw new HttpError(401, 'Connexion requise.');
    }

    public function logout(): void
    {
        $token = $_COOKIE[self::COOKIE] ?? null;
        if (is_string($token) && preg_match('/\A[a-f0-9]{64}\z/', $token) === 1) {
            $this->removeSession($token);
        }
        $this->clearCookie();
    }

    public function start(): void
    {
        $token = bin2hex(random_bytes(32));
        // Un fichier par session ; les sessions expirées sont retirées à chaque connexion.
        $this->expireSessions();
        $expires = (string) (time() + self::SESSION_SECONDS);
        if (file_put_contents($this->storage . '/' . $token, $expires) !== strlen($expires)) {
            throw new RuntimeException('Création de session impossible.');
        }
        setcookie(self::COOKIE, $token, [
            'expires' => time() + self::SESSION_SECONDS,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        $_COOKIE[self::COOKIE] = $token;
    }

    private function clearCookie(): void
    {
        setcookie(self::COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true,
            'httponly' => true, 'samesite' => 'Strict']);
        unset($_COOKIE[self::COOKIE]);
    }

    private function expireSessions(): void
    {
        foreach (glob($this->storage . '/[a-f0-9]*') ?: [] as $path) {
            $expires = @file_get_contents($path);
            if (!is_string($expires) || !ctype_digit($expires) || (int) $expires < time()) {
                @unlink($path);
            }
        }
    }

    /** Supprimer un jeton de session ; le nom des fichiers est hexadécimal sans séparateur. */
    private function removeSession(string $token): void
    {
        $path = $this->storage . '/' . $token;
        if (is_file($path) && !is_link($path)) {
            unlink($path);
        }
    }

    /** Compteur de tentatives échouées, partagé par le serveur et limité à une fenêtre. */
    private function attempts(): int
    {
        $path = $this->storage . '/attempts';
        $data = @file_get_contents($path);
        if (!is_string($data)) {
            return 0;
        }
        $window = json_decode($data, true);
        if (!is_array($window) || ($window['expires'] ?? 0) < time()) {
            return 0;
        }

        return (int) ($window['count'] ?? 0);
    }

    private function recordAttempt(): void
    {
        $count = $this->attempts() + 1;
        file_put_contents($this->storage . '/attempts', json_encode(
            ['count' => $count, 'expires' => time() + self::ATTEMPT_WINDOW_SECONDS]), LOCK_EX);
    }

    private function clearAttempts(): void
    {
        $path = $this->storage . '/attempts';
        if (is_file($path)) {
            unlink($path);
        }
    }
}
