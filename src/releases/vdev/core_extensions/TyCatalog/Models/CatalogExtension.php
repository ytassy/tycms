<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/** Fiche d'une extension dans un catalogue ; les versions en dépendent. */
#[Table('ty_catalog_extensions', 'id')]
final class CatalogExtension extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('catalog_id', FieldType::INT)]
    public int $catalog_id;

    #[Field('extension_identifier')]
    public string $extension_identifier;

    #[Field('type')]
    public string $type;

    #[Field('folder')]
    public string $folder;

    #[Field('display_name')]
    public string $display_name;

    #[Field('description')]
    public string $description;

    #[Field('image_url', nullable: true)]
    public ?string $image_url = null;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated = null;
}
