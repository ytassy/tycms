<?php

namespace YTassy\Tycms\Session;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;

abstract class Session extends BaseApplicationItem
{
	public function __construct(Application $app)
    {
        parent::__construct($app);
    }

	public abstract function initialize(): void;
	public abstract function destroy(): void;
	public abstract function rotateId(): void;

	public abstract function get(string $key, string|array|object|int|float|bool|null $defaultValue = null) : string|array|object|int|float|bool|null;
	public abstract function set(string $key, string|array|object|int|float|bool|null $value) : void;
	public abstract function remove(string $key): void;

	public function setUser(?int $userId = null): void {
		if ($this->getUserId() !== $userId) {
			$this->remove('csrf_token');
		}
		$this->set('user_id', $userId);
	}

	public function getCsrfToken(): string {
		$token = $this->get('csrf_token');
		if (!is_string($token) || $token === '') {
			$token = bin2hex(random_bytes(32));
			$this->set('csrf_token', $token);
		}
		return $token;
	}

	public function verifyCsrfToken(mixed $token): bool {
		$storedToken = $this->get('csrf_token');
		return is_string($storedToken) && $storedToken !== ''
			&& is_string($token) && hash_equals($storedToken, $token);
	}

	public function getUserId(): ?int {
		return $this->get('user_id', null);
	}

	public function setSpaceId(?int $spaceId = null): void {
		$this->set('current_space_id', $spaceId);
	}

	public function getSpaceId(): ?int {
		$spaceId = $this->get('current_space_id', null);
		return is_int($spaceId) ? $spaceId : null;
	}

}
