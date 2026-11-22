<?php

namespace YTassy\Tycms\Routing\Annotation\Doc;

use Attribute;
use YTassy\Tycms\Request\RequestMethod;

#[Attribute()]
class ControllerMethod
{
	/**
     * @param array<RequestMethod> $methods
     */
	public function __construct(?string $name,
		string $path,
		?string $description = null,
		?array $where = null,
		?array $methods = ['GET'])
	{
		$this->name = $name;
		$this->path = $path;
		$this->description = $description ?? '';
		$this->where = $where ?? [];
		$this->methods = $methods ?? [];
	}

	public ?string $name;
	public string $path;
	public ?string $description;
	public ?array $where;

	/** @var array<RequestMethod> */
	public ?array $methods;
}
