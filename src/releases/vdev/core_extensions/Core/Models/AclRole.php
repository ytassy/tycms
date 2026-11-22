<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('acl_roles', 'id')]
class AclRole extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('extension')]
    public string $extension;

    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('space_title')]
    public string $space_title;

    #[Field('user_defined', FieldType::BOOL)]
    public bool $user_defined;

    #[Field('name')]
    public string $name;

    #[Field('description')]
    public string $description;

    #[Field('type', FieldType::INT)]
    public int $type;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated;
}
