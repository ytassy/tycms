<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use Override;
use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Hidden;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\DB\ORM\FieldDef;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsOrm\Acl;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;

#[Table('users', 'id')]
class User extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('space_id', FieldType::INT)]
    public int $space_id;

    #[Field('first_name')]
    public string $first_name;

    #[Field('last_name')]
    public string $last_name;

    #[Field('email')]
    public string $email;

    #[Hidden]
    #[Field('password')]
    public string $password;

    #[Field('timezone')]
    public string $timezone;

    #[Field('blocked', FieldType::BOOL)]
    public bool $blocked;

    #[Field('send_email', FieldType::BOOL, nullable: true)]
    public ?bool $send_email;

    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;

    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    #[Field('updated', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $updated;

    #[Field('register_date', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $register_date;

    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    #[Field('last_visit_date', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $last_visit_date;

    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    #[Field('params', FieldType::JSON)]
    public mixed $params;

    #[Hidden]
    #[Field('reset_password_token')]
    public string $reset_password_token;

    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    #[Field('reset_password_request_date', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $reset_password_request_date;

    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    #[Field('reset_password_last_reset_date', FieldType::DATETIME, nullable: true)]
    public ?\DateTimeImmutable $reset_password_last_reset_date;

    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    #[Field('reset_count', FieldType::INT)]
    public int $reset_count;


}
