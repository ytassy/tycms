<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('pages', 'id')]
class Page extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('user_defined', FieldType::BOOL)]
    public bool $user_defined;

    #[Field('name')]
    public string $name;

    #[Field('description')]
    public string $description;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated;

    #[Field('status', FieldType::INT)]
    public int $status = 1;

    #[Field('type', FieldType::INT)]
    public int $type;

    #[Field('raw_html', nullable: true)]
    public ?string $raw_html;

    #[Field('raw_scss', nullable: true)]
    public ?string $raw_scss;

    #[Field('raw_css', nullable: true)]
    public ?string $raw_css;

    #[Field('data', FieldType::JSON, nullable: true)]
    public mixed $data;

    #[Field('access_edit_role_id', FieldType::INT, nullable: true)]
    public ?int $access_edit_role_id;
}
