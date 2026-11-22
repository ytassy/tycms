<?php

namespace YTassy\Tycms\Session;

use YTassy\Tycms\BaseApplicationItem;

/** Révocation centralisée des sessions d'un compte. */
class SessionManager extends BaseApplicationItem
{
    public function deleteUserSessions(int $userId): bool
    {
        // Les sessions PHP n'offrent pas d'index partagé par utilisateur.
        $supported = $this->app->getConf()->session_handler === 'database';
        if ($supported) {
            $this->db->execute('DELETE FROM sessions WHERE user_id = %', [$userId]);
        }

        // Effacer aussi la session courante en mémoire pour éviter de la réutiliser.
        if ($this->session->getUserId() === $userId) {
            $this->session->destroy();
        }

        return $supported;
    }
}
