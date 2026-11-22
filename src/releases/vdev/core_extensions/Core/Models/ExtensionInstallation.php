<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/** Provenance persistante d'une extension installée depuis un catalogue. */
#[Table('extension_installations', 'id')]
final class ExtensionInstallation extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('extension_id')]
    public string $extension_id;

    #[Field('type')]
    public string $type;

    #[Field('folder')]
    public string $folder;

    #[Field('source_id', FieldType::INT, nullable: true)]
    public ?int $source_id = null;

    #[Field('source_name')]
    public string $source_name;

    #[Field('source_url')]
    public string $source_url;

    #[Field('version')]
    public string $version;

    #[Field('key_id')]
    public string $key_id;

    #[Field('archive_sha256')]
    public string $archive_sha256;

    #[Field('status')]
    public string $status;

    #[Field('created_by', FieldType::INT, nullable: true)]
    public ?int $created_by = null;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated = null;
}
