<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('user_groups', 'id')]
class UserGroup extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('parent_id', FieldType::INT, nullable: true)]
    public ?int $parent_id;

    #[Field('type', FieldType::INT)]
    public int $type;

    #[Field('name')]
    public string $name;

    #[Field('description')]
    public string $description;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated;
}
