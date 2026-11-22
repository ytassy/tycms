<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;


#[Table('user_groups_map', 'id')]
class UserGroupMap extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;
    
    #[Field('user_id', FieldType::INT)]
    public int $user_id;

    #[Field('group_id', FieldType::INT)]
    public int $group_id;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;
}
