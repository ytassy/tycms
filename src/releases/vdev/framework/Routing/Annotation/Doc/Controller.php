<?php

namespace YTassy\Tycms\Routing\Annotation\Doc;

use Attribute;

#[Attribute()]
class Controller
{
	public function __construct(?string $name,
		string $path,
		?string $description = null,
		bool $isApi = false,
		bool $withoutCsrf = false)
	{
		$this->name = $name;
		$this->isApi = $isApi;
		$this->withoutCsrf = $withoutCsrf;
		$this->path = $path;
		$this->description = $description ?? '';
	}

	public ?string $name;
	public bool $isApi;
	public bool $withoutCsrf;
	public string $path;
	public ?string $description;
}
