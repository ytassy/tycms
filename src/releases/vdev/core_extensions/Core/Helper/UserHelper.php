<?php

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\CoreExts\Core\Models\User;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class UserHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;


    public function getByEmail(?string $email = null, bool $returnNull = true, bool $lock = false): ?User
    {
        $user = $this->db->selectOrmOneOrNull(User::class,
            'SELECT * FROM users WHERE email = %' . ($lock ? ' FOR UPDATE' : ''), [$email]);

        if ($returnNull == false && $user == null)
        {
            throw new TechException('User not found');
        }

        return $user;
    }

    public function updatePassword(int $id, string $clearPassword): void
    {
        // Un changement par un autre parcours invalide les liens encore en circulation.
        $this->db->execute("UPDATE users SET password = %, reset_password_token = '', params = JSON_SET(COALESCE(params, '{}'), '$.local_password_enabled', 1) WHERE id = %", [
            password_hash($clearPassword, PASSWORD_DEFAULT), $id
        ]);
    }

    public function getLoginUrl(): string
    {
        return $this->coreAuthenticationHelper->pageUrl($this->coreSpaceHelper->getCurrentSpace(), 'login')
            ?? '/login-fb';
    }

    public function getUser(?int $id = null, bool $returnNull = true) : ?User{
        if ($id === null) $id = $this->session->getUserId();

        $user = null;
        if ($id !== null) $user = $this->db->selectOrmOneOrNull(User::class,
            "SELECT * FROM users WHERE id = %", [ $id ]);

        if ($returnNull == false && $user == null) throw new TechException('User not found');
        
        return $user;
    }

    

}
