<?php

namespace YTassy\Tycms\Routing\Annotation\Doc;

use Attribute;

#[Attribute()]
class ControllerReturn
{
	public function __construct(string $name = '', ?string $description = null,
		$schema = null, ?string $schema_arrayOf = null, ?string $schema_objectByName = null)
	{
		$this->name = $name;
		$this->description = $description ?? '';
		$this->schema = $schema;

		if ($schema_arrayOf != null)
		{
			$this->schema = [
				'type' => 'array', 
				'items' => [ '$ref' => '/' . trim($schema_arrayOf, '/') ]
			];
		}

		if ($schema_objectByName != null)
		{
			$this->schema = [
				'type' => 'object',
				'propertyNames' => [ 'type' => 'string' ],
				'patternProperties' => [
					'.*' => [ '$ref' => '/' . trim($schema_objectByName, '/') ]
				]
			];
		}

	}

	public ?string $name;
	public ?string $description;
	public ?array $schema;

}
