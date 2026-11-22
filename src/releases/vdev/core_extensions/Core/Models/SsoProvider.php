<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Hidden;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('sso_providers', 'id')]
class SsoProvider extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('name')]
    public string $name;

    #[Field('enabled', FieldType::BOOL)]
    public bool $enabled;

    #[Field('protocol')]
    public string $protocol;

    #[Field('login_enabled', FieldType::BOOL)]
    public bool $login_enabled;

    #[Field('integration_enabled', FieldType::BOOL)]
    public bool $integration_enabled;

    #[Field('auto_link_account', FieldType::BOOL)]
    public bool $auto_link_account;

    #[Hidden]
    #[Field('config')]
    public string $config;

    #[Hidden]
    #[Field('secret_data', FieldType::BINARY, nullable: true)]
    public ?string $secret_data;

    #[Field('secret_key_version', FieldType::INT)]
    public int $secret_key_version;

    #[Hidden]
    #[Field('mapping')]
    public string $mapping;

    #[Field('login_button_text')]
    public string $login_button_text;

    #[Field('register_button_text')]
    public string $register_button_text;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated;
}
