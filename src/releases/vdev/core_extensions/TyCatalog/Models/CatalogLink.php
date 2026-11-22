<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/** Référence de découverte vers un autre catalogue, local ou externe. */
#[Table('ty_catalog_catalog_links', 'id')]
final class CatalogLink extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('catalog_id', FieldType::INT)]
    public int $catalog_id;

    #[Field('name')]
    public string $name;

    #[Field('target_url')]
    public string $target_url;

    #[Field('description')]
    public string $description;

    #[Field('position', FieldType::INT)]
    public int $position = 0;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated = null;
}
