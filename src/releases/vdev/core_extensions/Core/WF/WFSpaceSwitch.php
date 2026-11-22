<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/** Autorisation du choix public d'un Space ; aucune inscription implicite. */
class WFSpaceSwitch extends BaseWF
{
    use TraitAllHelpers;

    public function getOptions(string $token): array
    {
        $settings = $this->settings($token);
        $spaces = [];
        foreach ($this->coreSpaceSwitchHelper->candidates($settings) as $space) {
            $member = $this->coreAclHelper->hasGlobalPermission(Permissions::MEMBER, $space->id);
            if ($settings->hide_closed && !$member && !$this->coreSpaceSwitchHelper->registrationEnabled($space)) continue;
            $spaces[] = $this->coreSpaceSwitchHelper->present($space, $settings);
        }
        return ['spaces' => $spaces];
    }

    public function select(string $token, int $spaceId): array
    {
        $settings = $this->settings($token);
        $space = $this->coreSpaceSwitchHelper->candidates($settings)[$spaceId] ?? null;
        if ($space === null) ExceptionResponse::throwWarning('Cet espace n’est plus proposé. Actualisez la liste.');

        // Relire le compte avant le changement et ne jamais déduire le droit du navigateur.
        $userId = $this->session->getUserId();
        if ($userId !== null) {
            $user = $this->coreUserRegistrationHelper->getUserForUpdate($userId);
            if ($user === null || (int) $user->blocked !== 0) {
                ExceptionResponse::throwDanger('Ce compte ne peut pas changer d’espace.');
            }
        }
        $member = $this->coreAclHelper->hasGlobalPermission(Permissions::MEMBER, $spaceId);
        $url = $member ? $this->coreSpaceSwitchHelper->destination($space, $settings->destination)
            : $this->coreSpaceSwitchHelper->registrationUrl($space);
        if ($url === null) {
            ExceptionResponse::throwWarning('L’inscription via ce sélecteur est indisponible dans cet espace.');
        }

        // La réponse ne déclenche qu'une navigation finale ; le POST ne rejoint aucun groupe.
        $this->coreSpaceHelper->changeCurrentSpace($spaceId);
        return ['url' => $url, 'registration_required' => !$member];
    }

    private function settings(string $token): object
    {
        $settings = $this->coreSpaceSwitchHelper->readConfiguration($token);
        if ($settings === null) {
            ExceptionResponse::throwWarning('Ce sélecteur a expiré ou est invalide. Rechargez la page.');
        }
        return $settings;
    }
}
