<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('space_languages', 'id')]
class SpaceLanguage extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('locale', FieldType::STRING)]
    public string $locale;

    #[Field('is_default', FieldType::BOOL)]
    public bool $is_default;

    #[Field('enabled', FieldType::BOOL)]
    public bool $enabled;

    #[Field('sort_order', FieldType::INT)]
    public int $sort_order;

    #[Field('menu_item_home_id', FieldType::INT, nullable: true)]
    public ?int $menu_item_home_id;

    #[Field('menu_item_user_space_id', FieldType::INT, nullable: true)]
    public ?int $menu_item_user_space_id;
}
