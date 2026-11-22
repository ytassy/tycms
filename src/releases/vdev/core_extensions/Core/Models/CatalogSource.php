<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/** Catalogue externe enregistré par le client Core. */
#[Table('extension_catalog_sources', 'id')]
final class CatalogSource extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('name')]
    public string $name;

    #[Field('base_url')]
    public string $base_url;

    #[Field('key_id')]
    public string $key_id;

    #[Field('public_key')]
    public string $public_key;

    #[Field('license_encrypted', nullable: true)]
    public ?string $license_encrypted = null;

    #[Field('enabled', FieldType::BOOL)]
    public bool $enabled = true;

    #[Field('created_by', FieldType::INT, nullable: true)]
    public ?int $created_by = null;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated = null;
}
