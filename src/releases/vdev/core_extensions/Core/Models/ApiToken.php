<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Models;

use Override;
use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Hidden;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/** Jeton d'API associé à un compte de service. */
#[Table('api_tokens', 'id')]
class ApiToken extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('user_id', FieldType::INT)]
    public int $user_id;

    #[Field('name')]
    public string $name;

    // Le hachage SHA-256 du secret ; le secret n'est jamais conservé.
    #[Hidden]
    #[Field('token_hash')]
    public string $token_hash;

    #[Field('space_id', FieldType::INT, nullable: true)]
    public ?int $space_id;

    #[Field('expires_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $expires_at;

    #[Field('last_used_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $last_used_at;

    #[Field('revoked_at', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $revoked_at;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;
}