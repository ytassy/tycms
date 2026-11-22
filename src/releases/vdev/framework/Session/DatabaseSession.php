<?php

namespace YTassy\Tycms\Session;

use YTassy\Tycms\Application;

class DatabaseSession extends Session
{
    private const COOKIE_NAME = 'session_key';

    private string $sessionKey = '';
    private array $data = [];
    private bool $initialized = false;

	public function __construct(Application $app)
    {
        parent::__construct($app);
    }

    public function initialize() : void
    {
        if ($this->initialized) {
            return;
        }

        $lifetime = (int) $this->app->getConf()->session_lifetime;
        $cookieKey = $this->request?->cookie(self::COOKIE_NAME);

        if (is_string($cookieKey) && $cookieKey !== '' && strlen($cookieKey) <= 50) {
            $sessions = $this->db->select(
                'SELECT `data` FROM `sessions` WHERE `key` = % LIMIT 1',
                [$cookieKey]
            );

            if ($sessions !== []) {
                $data = unserialize($sessions[0]->data, ['allowed_classes' => true]);
                $this->data = is_array($data) ? $data : [];
                $this->sessionKey = $cookieKey;
                $this->db->execute(
                    'UPDATE `sessions` SET `last_request_date` = current_timestamp(), `requests` = `requests` + 1 WHERE `key` = %',
                    [$this->sessionKey]
                );
            }
        }

        if ($this->sessionKey === '') {
            $this->sessionKey = bin2hex(random_bytes(24));
            $this->db->execute(
                'INSERT INTO `sessions` (`key`, `data`) VALUES (%, %)',
                [$this->sessionKey, serialize($this->data)]
            );
        }

        setcookie(self::COOKIE_NAME, $this->sessionKey, [
            'expires' => time() + $lifetime,
            'path' => '/',
            'secure' => $this->request?->is_https ?? false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $this->db->execute(
            'DELETE FROM `sessions` WHERE 
                `last_request_date` < DATE_SUB(current_timestamp(), INTERVAL % SECOND)
                OR (`requests` = 1 AND `last_request_date` < DATE_SUB(current_timestamp(), INTERVAL 20 MINUTE))',
            [$lifetime]
        );

        $this->initialized = true;
    }

    public function destroy(): void
    {
        if ($this->sessionKey !== '') {
            $this->db->execute('DELETE FROM `sessions` WHERE `key` = %', [$this->sessionKey]);
        }

        setcookie(self::COOKIE_NAME, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->request?->is_https ?? false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $this->sessionKey = '';
        $this->data = [];
        $this->initialized = false;
    }

    public function rotateId(): void
    {
        if (!$this->initialized || $this->sessionKey === '') {
            throw new \RuntimeException('Session non initialisée.');
        }
        $oldKey = $this->sessionKey;
        $newKey = bin2hex(random_bytes(24));
        $this->db->execute(
            'INSERT INTO `sessions` (`key`, `user_id`, `data`) VALUES (%, %, %)',
            [$newKey, $this->getUserId(), serialize($this->data)]
        );
        $this->db->execute('DELETE FROM `sessions` WHERE `key` = %', [$oldKey]);
        $this->sessionKey = $newKey;
        setcookie(self::COOKIE_NAME, $newKey, [
            'expires' => time() + (int) $this->app->getConf()->session_lifetime,
            'path' => '/',
            'secure' => $this->request?->is_https ?? false,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public function get(string $key, string|array|object|int|float|bool|null $defaultValue = null) : string|array|object|int|float|bool|null
    {
        return $this->data[$key] ?? $defaultValue;
    }

    public function set(string $key, string|array|object|int|float|bool|null $value): void
    {
        $this->data[$key] = $value;
        $this->db->execute('UPDATE `sessions` SET `data` = % WHERE `key` = %', [serialize($this->data), $this->sessionKey]);
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
        $this->db->execute('UPDATE `sessions` SET `data` = % WHERE `key` = %', [serialize($this->data), $this->sessionKey]);
    }

    public function setUser(?int $userId = null): void {
        parent::setUser($userId);
        
        $this->db->execute('UPDATE `sessions` SET `user_id` = %
            WHERE `key` = %', [$userId, $this->sessionKey]);
	}
}
