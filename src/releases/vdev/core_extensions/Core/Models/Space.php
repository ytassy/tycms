<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\DB\ORM\FieldDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;
use YTassy\Tycms\Tools\ArrayTools;

#[Table('spaces', 'id')]
class Space extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('parent_id', FieldType::INT, nullable: true)]
    public ?int $parent_id;

    #[Field('template', FieldType::STRING)]
    public string $template;

    #[Field('medias_dir', FieldType::STRING)]
    public string $medias_dir;

    #[Field('hosts', FieldType::JSON)]
    public mixed $hosts;

    #[Field('title')]
    public string $title;

    #[Field('params', FieldType::JSON)]
    public mixed $params;

    #[Field('menu_item_home_id', FieldType::INT, nullable: true)]
    public ?int $menu_item_home_id;

    #[Field('menu_item_user_space_id', FieldType::INT, nullable: true)]
    public ?int $menu_item_user_space_id;


    #[Field('menu_item_login_id', FieldType::INT, nullable: true)]
    public ?int $menu_item_login_id = null;

    #[Field('menu_item_register_id', FieldType::INT, nullable: true)]
    public ?int $menu_item_register_id = null;

    protected function onSerializeField(FieldDef $field, mixed $value): mixed
    {
        if ($field->name == 'params') {
            return ArrayTools::filterFields($value , [
                'main_host', 'title', 'short_description' // TODO ajouter
            ]);
        }
        return $value;
    }

    public function getMediasDir(): string
    {
        $root = $this->_app->getMediasRoot();
        $separator = str_ends_with($root, '/') || str_ends_with($root, '\\') ? '' : DIRECTORY_SEPARATOR;
        return $root . $separator . $this->medias_dir;
    }
}
