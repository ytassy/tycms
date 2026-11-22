<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Space;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class SpaceAdminHelper extends BaseHelper
{
    public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);
    }

    public function getSpace(int $spaceId): ?Space
    {
        return $this->db->selectOrmOneOrNull(Space::class, 'SELECT * FROM spaces WHERE id = %', [$spaceId]);
    }

    public function createSpace(object $data): Space
    {
        $this->db->execute(
            'INSERT INTO spaces (parent_id, template, medias_dir, hosts, title, params) VALUES (%, %, %, %, %, %)',
            [$data->parent_id, $data->template, $data->medias_dir, json_encode($data->hosts, JSON_THROW_ON_ERROR), $data->title, json_encode($data->params, JSON_THROW_ON_ERROR)],
        );

        $spaceId = $this->db->getLastInsertId();
        $this->db->execute(
            'INSERT INTO space_languages (space_id, locale, is_default, enabled, sort_order) VALUES (%, %, %, %, %)',
            [$spaceId, 'fr', true, true, 0],
        );

        return $this->getSpace($spaceId);
    }

    public function updateSpace(int $spaceId, object $data): Space
    {
        $this->db->execute(
            'UPDATE spaces SET parent_id = %, template = %, medias_dir = %, hosts = %, title = %, params = %, menu_item_home_id = %, menu_item_user_space_id = %, menu_item_login_id = %, menu_item_register_id = % WHERE id = %',
            [
                $data->parent_id,
                $data->template,
                $data->medias_dir,
                json_encode($data->hosts, JSON_THROW_ON_ERROR),
                $data->title,
                json_encode($data->params, JSON_THROW_ON_ERROR),
                $data->menu_item_home_id,
                $data->menu_item_user_space_id,
                $data->menu_item_login_id,
                $data->menu_item_register_id,
                $spaceId,
            ],
        );

        return $this->getSpace($spaceId);
    }

    /** @param list<array{locale:string,home_item_id:?int,user_space_item_id:?int}> $languages */
    public function saveNavigation(int $spaceId, ?int $homeItemId, ?int $userSpaceItemId, array $languages, ?int $loginId = null, ?int $registerId = null): void
    {
        // La route API ouvre une transaction. Le verrou sur le Space est pris
        // par le WF avant cette méthode, comme pour l'édition de ses langues.
        $this->db->execute(
            'UPDATE spaces SET menu_item_home_id = %, menu_item_user_space_id = %, menu_item_login_id = %, menu_item_register_id = % WHERE id = %',
            [$homeItemId, $userSpaceItemId, $loginId, $registerId, $spaceId],
        );

        foreach ($languages as $language) {
            $this->db->execute(
                'UPDATE space_languages SET menu_item_home_id = %, menu_item_user_space_id = % WHERE space_id = % AND locale = % AND enabled = 1',
                [$language['home_item_id'], $language['user_space_item_id'], $spaceId, $language['locale']],
            );
        }
    }

    /** @return list<string> */
    public function getDeletionBlockers(int $spaceId): array
    {
        $checks = [
            'des sous-espaces' => 'SELECT id FROM spaces WHERE parent_id = % LIMIT 1',
            'des menus' => 'SELECT id FROM menus WHERE space_id = % LIMIT 1',
            'des pages' => 'SELECT id FROM pages WHERE space_id = % LIMIT 1',
            'des utilisateurs' => 'SELECT id FROM users WHERE space_id = % LIMIT 1',
            'des groupes utilisateurs' => 'SELECT id FROM user_groups WHERE space_id = % LIMIT 1',
            'des rôles' => 'SELECT id FROM acl_roles WHERE space_id = % LIMIT 1',
            'des attributions ACL' => 'SELECT id FROM acl_map WHERE space_id = % LIMIT 1',
            'des configurations' => 'SELECT space_id FROM config WHERE space_id = % LIMIT 1',
        ];
        $blockers = [];
        foreach ($checks as $label => $sql) {
            if ($this->db->selectOneOrNull($sql, [$spaceId]) !== null) {
                $blockers[] = $label;
            }
        }

        // Une référence JSON ne bénéficie pas d'une clé étrangère : protéger aussi les inscriptions futures.
        if ($this->db->selectOneOrNull(
            "SELECT id FROM spaces WHERE id != % AND JSON_UNQUOTE(JSON_EXTRACT(params, '$.registration_target_space_id')) = CAST(% AS CHAR) LIMIT 1",
            [$spaceId, $spaceId]
        ) !== null) {
            $blockers[] = 'des espaces ciblant cet espace pour leurs inscriptions';
        }

        return $blockers;
    }

    public function deleteSpace(int $spaceId): bool
    {
        return $this->db->execute('DELETE FROM spaces WHERE id = %', [$spaceId]) === 1;
    }
}
