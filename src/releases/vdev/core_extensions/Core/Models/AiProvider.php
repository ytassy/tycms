<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Hidden;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('ai_providers', 'id')]
final class AiProvider extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('name')]
    public string $name;

    #[Field('type')]
    public string $type;

    #[Field('base_url')]
    public string $base_url;

    #[Field('enabled', FieldType::BOOL)]
    public bool $enabled;

    #[Hidden]
    #[Field('config')]
    public string $config;

    #[Hidden]
    #[Field('secret_data')]
    public string $secret_data;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated;
}
