<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Space;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class SpaceLanguageAdminHelper extends BaseHelper
{
    public function lockSpace(int $spaceId): void
    {
        $this->db->select('SELECT id FROM spaces WHERE id = % FOR UPDATE', [$spaceId]);
    }

    /** @param list<array{id:?int,locale:string,enabled:bool,is_default:bool}> $languages */
    public function save(int $spaceId, array $languages): void
    {
        // The unique default index requires clearing the old default before
        // assigning the new one. The route transaction makes this atomic.
        $this->db->execute('UPDATE space_languages SET is_default = 0 WHERE space_id = %', [$spaceId]);

        foreach ($languages as $order => $language) {
            if ($language['id'] === null) {
                $this->db->execute(
                    'INSERT INTO space_languages (space_id, locale, enabled, is_default, sort_order) VALUES (%, %, %, %, %)',
                    [$spaceId, $language['locale'], $language['enabled'], $language['is_default'], $order * 10],
                );
            } else {
                $this->db->execute(
                    'UPDATE space_languages SET enabled = %, is_default = %, sort_order = % WHERE id = % AND space_id = %',
                    [$language['enabled'], $language['is_default'], $order * 10, $language['id'], $spaceId],
                );
            }
        }
    }
}
