<?php

namespace YTassy\Tycms\DB\ORM;

use YTassy\Tycms\Tools\DateTools;

use Configuration;
use YTassy\Tycms\Application;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\Exceptions\TechException;

class DatabaseOrm
{
    private Application $app;
    private Database $db;
    private static array $fieldDefinitions = [];
    private static array $tableDefinitions = [];

	public function __construct(Application $app, Database $db)
	{
        $this->app = $app;
        $this->db = $db;
    }

    /**
	 * @template T of object
	 * @param class-string<T> $className
	 * @param string $query
	 * @param array $valuesToQuote
	 * @return T|null
	 */
    public function selectOneOrNull(string $className, string $query, ?array $valuesToQuote = null): ?BaseOrmObject
	{
		$res = $this->select($className, $query, $valuesToQuote);
		if (count($res) != 1) return null;
		return $res[0];
	}

    /**
	 * @template T of object
	 * @param class-string<T> $className
	 * @param string $query
	 * @param array $valuesToQuote
	 * @return T
	 */
    public function selectOneOrFail(string $className, string $query, ?array $valuesToQuote = null): BaseOrmObject
	{
		$res = $this->select($className, $query, $valuesToQuote);
		if (count($res) != 1) throw new TechException("Object not found: $className");
		return $res[0];
	}

    /**
	 * @template T of object
	 * @param class-string<T> $className
	 * @param string $query
	 * @param array $valuesToQuote
	 * @return array<T>
	 */
    public function select(string $className, string $query, ?array $valuesToQuote = null) : array {
        $items = $this->db->select($query, $valuesToQuote);

        if (!isset(self::$fieldDefinitions[$className])) {
            $fields = [];
            $reflection = new \ReflectionClass($className);

            foreach ($reflection->getProperties() as $property) {
                $attributes = $property->getAttributes(Field::class);
                if ($attributes === []) {
                    continue;
                }

                $field = $attributes[0]->newInstance();
                $fields[$field->column] = [$property, $field->type];
            }

            self::$fieldDefinitions[$className] = $fields;
        }

        $fields = self::$fieldDefinitions[$className];
        $objects = [];

        foreach ($items as $item) {
            $object = new $className($this->app);

            foreach ($fields as $column => [$property, $type]) {
                if (!isset($item->$column) && !property_exists($item, $column)) {
                    continue;
                }

                $value = $item->$column;
                if ($value !== null) {
                    $value = match ($type) {
                        FieldType::BOOL => (bool) $value,
                        FieldType::INT => (int) $value,
                        FieldType::FLOAT => (float) $value,
                        FieldType::JSON, FieldType::JSON_TEXT => json_decode($value),
                        FieldType::JSON_ARRAY, FieldType::JSON_ARRAY_TEXT => json_decode($value, true),
                        FieldType::DATETIME => new \DateTimeImmutable($value, new \DateTimeZone('UTC')),
                        FieldType::DATE => \DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')),
                        default => $value,
                    };
                }

                $property->setValue($object, $value);
            }

            // Hook
            $object->afterOrmFill();

            $objects[] = $object;
        }

        return $objects;
    }

    public function insert(BaseOrmObject $object): int
    {
        [$table, , $fields] = $this->getModelDefinition($object::class);
        $columns = [];
        $values = [];

        foreach ($fields as $column => [$property, $type]) {
            if (!$property->isInitialized($object)) {
                continue;
            }

            $columns[] = "`$column`";
            $values[] = $this->formatValue($property->getValue($object), $type);
        }

        if ($columns === []) {
            throw new \InvalidArgumentException('The ORM object has no initialized fields to insert.');
        }

        return $this->db->execute(
            'INSERT INTO `' . $table . '` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', array_fill(0, count($columns), '%')) . ')',
            $values
        );
    }

    public function update(BaseOrmObject $object): int
    {
        [$table, $primaryKey, $fields] = $this->getModelDefinition($object::class);
        if ($primaryKey === null || !isset($fields[$primaryKey])) {
            throw new \InvalidArgumentException('The ORM model must define a primary key to update.');
        }

        [$primaryProperty, $primaryType] = $fields[$primaryKey];
        if (!$primaryProperty->isInitialized($object)) {
            throw new \InvalidArgumentException('The ORM object primary key is not initialized.');
        }

        $assignments = [];
        $values = [];
        foreach ($fields as $column => [$property, $type]) {
            if ($column === $primaryKey || !$property->isInitialized($object)) {
                continue;
            }

            $assignments[] = "`$column` = %";
            $values[] = $this->formatValue($property->getValue($object), $type);
        }

        if ($assignments === []) {
            return 0;
        }

        $values[] = $this->formatValue($primaryProperty->getValue($object), $primaryType);
        return $this->db->execute(
            'UPDATE `' . $table . '` SET ' . implode(', ', $assignments) . " WHERE `$primaryKey` = %",
            $values
        );
    }

    public function delete(BaseOrmObject $object): int
    {
        [$table, $primaryKey, $fields] = $this->getModelDefinition($object::class);
        if ($primaryKey === null || !isset($fields[$primaryKey])) {
            throw new \InvalidArgumentException('The ORM model must define a primary key to delete.');
        }

        [$property, $type] = $fields[$primaryKey];
        if (!$property->isInitialized($object)) {
            throw new \InvalidArgumentException('The ORM object primary key is not initialized.');
        }

        return $this->db->execute(
            "DELETE FROM `$table` WHERE `$primaryKey` = %",
            [$this->formatValue($property->getValue($object), $type)]
        );
    }

    private function getModelDefinition(string $className): array
    {
        if (!isset(self::$tableDefinitions[$className])) {
            $reflection = new \ReflectionClass($className);
            $attributes = $reflection->getAttributes(Table::class);
            if ($attributes === []) {
                throw new \InvalidArgumentException("The ORM model $className must declare a Table attribute.");
            }

            $table = $attributes[0]->newInstance();
            self::$tableDefinitions[$className] = [$table->tableName, $table->primaryKey, self::$fieldDefinitions[$className] ?? $this->getFields($reflection)];
        }

        return self::$tableDefinitions[$className];
    }

    private function getFields(\ReflectionClass $reflection): array
    {
        $fields = [];
        foreach ($reflection->getProperties() as $property) {
            $attributes = $property->getAttributes(Field::class);
            if ($attributes === []) {
                continue;
            }

            $field = $attributes[0]->newInstance();
            $fields[$field->column] = [$property, $field->type];
        }

        return self::$fieldDefinitions[$reflection->getName()] = $fields;
    }

    private function formatValue(mixed $value, FieldType $type): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return match ($type) {
                FieldType::DATE => DateTools::toSqlDate($value),
                default => DateTools::toSql($value),
            };
        }

        return $value;
    }

    
}


