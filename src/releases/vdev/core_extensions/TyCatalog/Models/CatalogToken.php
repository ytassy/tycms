<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/** Jeton de publication distante, limité à un catalogue. */
#[Table('ty_catalog_tokens', 'id')]
final class CatalogToken extends BaseOrmObject
{
    public const ABILITY_DEPOSIT = 'deposit';
    public const ABILITY_PUBLISH = 'publish';
    public const ABILITIES = [self::ABILITY_DEPOSIT, self::ABILITY_PUBLISH];

    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('catalog_id', FieldType::INT)]
    public int $catalog_id;

    #[Field('label')]
    public string $label;

    #[Field('token_hash')]
    public string $token_hash;

    #[Field('abilities')]
    public string $abilities;

    #[Field('enabled', FieldType::BOOL)]
    public bool $enabled = true;

    #[Field('expires_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $expires_at = null;

    #[Field('last_used_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $last_used_at = null;

    #[Field('created_by', FieldType::INT, nullable: true)]
    public ?int $created_by = null;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('revoked_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $revoked_at = null;

    /** @return list<string> */
    public function abilityList(): array
    {
        return array_values(array_filter(explode(',', $this->abilities), static fn (string $ability): bool => $ability !== ''));
    }
}
