<?php


namespace YTassy\Tycms\DB\ORM\Annotation;


use Attribute;

#[Attribute()]
class Table
{
	public function __construct(string $tableName,
		string $primaryKey)
	{
		$this->tableName = $tableName;
		$this->primaryKey = $primaryKey;

	}

	public string $tableName;
	public ?string $primaryKey;

}
