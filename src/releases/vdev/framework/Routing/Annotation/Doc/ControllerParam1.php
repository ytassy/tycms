<?php

namespace YTassy\Tycms\Routing\Annotation\Doc;

use Attribute;
use YTassy\Tycms\Exceptions\TechException;

#[Attribute()]
class ControllerParam1
{
	public function __construct(?string $name = null, ?string $description = null,
		?string $errorUserMessage = null,
		?bool $required = null, ?string $example = null,
		?array $schema = null, ?string $schemaFile = null, ?VarType $varType = null)
	{
		if ($schemaFile != null && $schema != null)	
			throw new TechException('ControllerParam: $schemaFile != null && $schema != null');

		if ($schemaFile != null || $schema != null)
		{
			if ($varType == null) $varType = VarType::JSON;
			if ($varType != VarType::JSON) $varType = throw new TechException('ControllerParam: TYPE muste be JSON if a schema is defined');
		}	

		$this->name = $name;
		$this->description = $description ?? '';
		$this->errorUserMessage = $errorUserMessage;
		$this->required = $required ?? false;
		$this->example = $example;
		$this->_schema = $schema;
		$this->_schemaFile = $schemaFile;
		$this->varType = $varType;
	}

	public ?string $name;
	public ?string $description;
	public ?string $errorUserMessage;
	public ?bool $required;
	public ?string $example;
	
	public ?VarType $varType;

	private ?string $_schema;
	private ?string $_schemaFile;

	// Cache
	private ?array $schema = null;

	public function getSchema()
	{
		if ($this->_schemaFile == null && $this->_schema == null) return null;

		// Cache
		if ($this->schema != null) return $this->schema;

		if ($this->_schemaFile !== null)
		{
			if (pathinfo($this->_schemaFile, PATHINFO_EXTENSION) == 'php'){
				$this->schema = require($this->_schemaFile);
			}
			else $this->schema = json_decode(file_get_contents($this->_schemaFile));
		}
		else
		{
			$schema = $this->_schema;

			// Convert if String
			if (is_string($schema)) $schema = [ '$ref' => '/' . trim($schema, '/') ];

			if ($this->required == false && $schema != null)
			{
				$schema = [
					"anyOf" => [
						[ 'type' => 'null' ],
						[ 'type' => 'string', 'const' => ''],
						$schema
					]
				];
			}

			$this->schema = $schema;
		}

		return $this->schema;
	}
	
}

#[Attribute()]
class ControllerParam2 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam3 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam4 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam5 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam6 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam7 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam8 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam9 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam10 extends ControllerParam1 {}


#[Attribute()]
class ControllerParam11 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam12 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam13 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam14 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam15 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam16 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam17 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam18 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam19 extends ControllerParam1 {}
#[Attribute()]
class ControllerParam20 extends ControllerParam1 {}

