<?php

namespace YTassy\Tycms\DB\ORM\Annotation;

use Attribute;

#[Attribute()]
class Field
{
	public function __construct(
		string $column,
		FieldType $type = FieldType::STRING,
		bool $nullable = false,
		bool $autoIncrement = false,
	)
	{
		
		$this->column = $column;
		$this->type = $type;
		$this->nullable = $nullable;
		$this->autoIncrement = $autoIncrement;
	}

	public string $column;
	public FieldType $type;
	public bool $nullable;
	public bool $autoIncrement;
}
