<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('config', '')]
class Config extends BaseOrmObject
{
    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('extension')]
    public string $extension;

    #[Field('params', FieldType::JSON)]
    public object $params;

}
