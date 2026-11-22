<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/** Catalogue hébergé par TyCatalog, rattaché à un Space réel. */
#[Table('ty_catalog_catalogs', 'id')]
final class Catalog extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('slug')]
    public string $slug;

    #[Field('name')]
    public string $name;

    #[Field('description')]
    public string $description;

    #[Field('enabled', FieldType::BOOL)]
    public bool $enabled = true;

    #[Field('key_id', nullable: true)]
    public ?string $key_id = null;

    #[Field('public_key', nullable: true)]
    public ?string $public_key = null;

    #[Field('keep_versions', FieldType::INT)]
    public int $keep_versions = 4;

    #[Field('keep_archives', FieldType::INT)]
    public int $keep_archives = 4;

    #[Field('created_by', FieldType::INT, nullable: true)]
    public ?int $created_by = null;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated = null;
}
