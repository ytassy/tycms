<?php

namespace YTassy\Tycms\Session;

use YTassy\Tycms\Application;

class PhpSession extends Session
{
	public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function initialize() : void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {

			$conf = $this->app->getConf();

            session_start(array_merge([
				'cookie_lifetime' => $conf->session_lifetime, // Client : le cookie expire dans 1h
				'gc_maxlifetime'  => $conf->session_lifetime, // Serveur : les données sont gardées 1h après la dernière activité
				'cookie_httponly' => true,
				//'cookie_secure'   => true,
				'use_strict_mode' => true,
				'cookie_samesite' => 'Lax'
			], $conf->session_php_start_options ?? [ ]));
        }
    }

    public function destroy(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }
    }

    public function rotateId(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE || !session_regenerate_id(true)) {
            throw new \RuntimeException('Impossible de renouveler la session.');
        }
    }

    public function get(string $key, string|array|object|int|float|bool|null $defaultValue = null) : string|array|object|int|float|bool|null
    {
        return $_SESSION[$key] ?? $defaultValue;
    }

    public function set(string $key, string|array|object|int|float|bool|null $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }


}
