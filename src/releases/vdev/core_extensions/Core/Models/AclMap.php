<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('acl_map', 'id')]
class AclMap extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('space_id', FieldType::INT, nullable: true)]
    public ?int $space_id;

    #[Field('role_id', FieldType::INT)]
    public int $role_id;

    #[Field('group_id', FieldType::INT)]
    public int $group_id;

    #[Field('acl_object_id', FieldType::INT, nullable: true)]
    public ?int $acl_object_id;

    #[Field('object_id', FieldType::INT, nullable: true)]
    public ?int $object_id;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;
}
