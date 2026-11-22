<?php

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

#[Table('sessions', 'id')]
class Session extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('key')]
    public string $key;

    #[Field('user_id', FieldType::INT, nullable: true)]
    public ?int $user_id;

    #[Field('start_date', FieldType::DATETIME)]
    public \DateTimeImmutable $start_date;

    #[Field('last_request_date', FieldType::DATETIME)]
    public \DateTimeImmutable $last_request_date;

    #[Field('requests', FieldType::INT)]
    public int $requests;

    #[Field('data')]
    public string $data;
}
