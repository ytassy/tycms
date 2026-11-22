<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('menu_items', 'id')]
class MenuItem extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('menu_id', FieldType::INT)]
    public int $menu_id;

    #[Field('ordering', FieldType::INT)]
    public int $ordering;

    #[Field('parent_id', FieldType::INT, nullable: true)]
    public ?int $parent_id;

    #[Field('status', FieldType::INT)]
    public int $status;

    #[Field('extension')]
    public string $extension;

    #[Field('type')]
    public string $type;

    #[Field('params', FieldType::JSON)]
    public mixed $params;

    #[Field('title')]
    public string $title;

    #[Field('alias')]
    public string $alias;

    #[Field('css')]
    public string $css;

    #[Field('icon')]
    public string $icon;

    #[Field('display_in_menu', FieldType::BOOL)]
    public bool $display_in_menu;

    #[Field('browser_title')]
    public string $browser_title;

    #[Field('meta_description')]
    public string $meta_description;

    #[Field('meta_robots')]
    public string $meta_robots;

    #[Field('page_title')]
    public string $page_title;

    #[Field('access_role_id', FieldType::INT, nullable: true)]
    public ?int $access_role_id;

    /** @var list<array{locale:string,enabled:bool,visible:bool}> */
    public array $languages = [];
}
