<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Models;

use YTassy\Tycms\DB\ORM\Annotation\Field;
use YTassy\Tycms\DB\ORM\Annotation\FieldType;
use YTassy\Tycms\DB\ORM\Annotation\Table;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseOrmObject;

/**
 * Version d'une page conservée avant son remplacement.
 *
 * La ligne ne décrit pas l'état courant : elle conserve le dernier état qui
 * existait avant une sauvegarde. Les colonnes reprennent celles de `pages`
 * afin qu'une version puisse être rechargée puis réenregistrée telle quelle.
 */
#[Table('page_history', 'id')]
class PageHistory extends BaseOrmObject
{
    #[Field('id', FieldType::INT, autoIncrement: true)]
    public int $id;

    #[Field('page_id', FieldType::INT)]
    public int $page_id;

    #[Field('user_id', FieldType::INT, nullable: true)]
    public ?int $user_id;

    #[Field('name')]
    public string $name;

    #[Field('description')]
    public string $description;

    #[Field('type', FieldType::INT)]
    public int $type;

    #[Field('raw_html', nullable: true)]
    public ?string $raw_html;

    #[Field('raw_scss', nullable: true)]
    public ?string $raw_scss;

    #[Field('data', FieldType::JSON, nullable: true)]
    public mixed $data;

    #[Field('access_edit_role_id', FieldType::INT, nullable: true)]
    public ?int $access_edit_role_id;

    #[Field('created', FieldType::DATETIME)]
    public \DateTimeImmutable $created;
}
