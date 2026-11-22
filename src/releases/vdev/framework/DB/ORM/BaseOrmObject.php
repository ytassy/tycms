<?php

namespace YTassy\Tycms\DB\ORM;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\DB\ORM\Annotation\Hidden;

class BaseOrmObject implements \JsonSerializable
{
    protected Application $_app;

    /** @var array<string,array<string,array{FieldDef,\ReflectionProperty}>> */
    private static array $fieldsByClass = [];

    public function __construct(BaseApplicationItem|Application $app)
    {
        if ($app instanceof BaseApplicationItem) $this->_app = $app->getApp();
        else if ($app instanceof Application) $this->_app = $app;
        else throw new \Exception("Invalid app instance");
    }

    public function getSharedExtensionsClass(string $className): BaseApplicationItem {
        return $this->_app->getOrAddSharedExtensionsClass($className);
    }

    public function afterOrmFill(){ }

    public function jsonSerialize(): array
    {
        $className = static::class;
        $fields = self::$fieldsByClass[$className] ??= array_reduce(
            (new \ReflectionClass($this))->getProperties(\ReflectionProperty::IS_PUBLIC),
            static function (array $fields, \ReflectionProperty $property): array {
                $attributes = [];
                $hidden = false;
                foreach ($property->getAttributes() as $attribute) {
                    $instance = $attribute->newInstance();
                    $attributes[] = $instance;
                    $hidden = $hidden || $instance instanceof Hidden;
                }

                $fields[$property->getName()] = [
                    new FieldDef(
                        name: $property->getName(),
                        hidden : $hidden,
                        attributes : $attributes,
                    ),
                    $property,
                ];
                return $fields;
            },
            [],
        );

        $data = [];
        foreach ($fields as [$field, $property]) {
            if ($field->hidden || !$property->isInitialized($this)) {
                continue;
            }
            if (!$this->serializeField($field)) {
                continue;
            }
            $data[$field->name] = $this->onSerializeField($field, $property->getValue($this));
        }

        return $data;
    }

    protected function onSerializeField(FieldDef $field, mixed $value): mixed
    {
        if ($value instanceof \DateTime || $value instanceof \DateTimeImmutable) {
            return $value->format("c");
        }

        return $value;
    }

    protected function serializeField(FieldDef $field): bool
    {
        return true;
    }

}
