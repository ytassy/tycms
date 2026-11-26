<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/**
 * Version publiée ou en brouillon d'une extension.
 *
 * L'enveloppe et les métadonnées restent des textes JSON bruts : la signature
 * porte sur les octets d'origine et le serveur ne réencode jamais le payload.
 */
#[Table('ty_catalog_versions', 'id')]
final class CatalogVersion extends BaseOrmObject
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_WITHDRAWN = 'withdrawn';

    /**
     * Canal de diffusion d'une version publiée.
     *
     * Une version bêta est servie seulement aux clients qui la demandent
     * explicitement ; une version release est servie par défaut. Le retour
     * release → bêta est refusé : l'identité servie à un client ne doit jamais
     * changer en arrière.
     */
    public const CHANNEL_RELEASE = 'release';
    public const CHANNEL_BETA = 'beta';
    public const CHANNELS = [self::CHANNEL_RELEASE, self::CHANNEL_BETA];

    public const STORAGE_LOCAL = 'local';
    public const STORAGE_EXTERNAL = 'external';

    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('extension_id', FieldType::INT)]
    public int $extension_id;

    #[Field('version')]
    public string $version;

    #[Field('status')]
    public string $status = self::STATUS_DRAFT;

    /** Canal de diffusion ; sans objet tant que la version est en brouillon ou retirée. */
    #[Field('channel')]
    public string $channel = self::CHANNEL_RELEASE;

    #[Field('signature_envelope')]
    public string $signature_envelope;

    #[Field('metadata')]
    public string $metadata;

    #[Field('release_notes')]
    public string $release_notes;

    #[Field('archive_bytes', FieldType::INT)]
    public int $archive_bytes;

    #[Field('archive_sha256')]
    public string $archive_sha256;

    #[Field('storage_type')]
    public string $storage_type = self::STORAGE_LOCAL;

    #[Field('storage_reference')]
    public string $storage_reference;

    #[Field('created_by', FieldType::INT, nullable: true)]
    public ?int $created_by = null;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('published_by', FieldType::INT, nullable: true)]
    public ?int $published_by = null;

    #[Field('published_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $published_at = null;

    #[Field('withdrawn_by', FieldType::INT, nullable: true)]
    public ?int $withdrawn_by = null;

    #[Field('withdrawn_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $withdrawn_at = null;

    #[Field('archive_removed_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $archive_removed_at = null;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated = null;
}
