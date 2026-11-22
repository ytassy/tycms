<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Response\ExceptionResponse;

/** Règle commune aux WF, après autorisation du parcours et dans sa transaction. */
trait TraitUserEnrollment
{
    private function enrollUserInSpace(int $userId, Space $space): void
    {
        $this->coreUserRegistrationHelper->enroll($userId, $space);

        // L’adhésion et le SSO peuvent avoir déjà chargé les droits du compte.
        // Relire après les écritures, sans réimplémenter les règles d’héritage ACL.
        $acl = $this->coreAclHelper;
        $acl->invalidateUser($userId);
        try {
            if (!$acl->userHasGlobalPermission($userId, Permissions::MEMBER, $space)) {
                ExceptionResponse::throwException(
                    'L’inscription ou la création du compte est impossible : les rôles et groupes configurés '
                    . 'n’accordent pas la permission MEMBER sur cet espace. Contactez son administrateur.',
                    // Le compte cible peut encore être annulé : conserver seulement le Space réel.
                    new LogEventException('ENROLLMENT_MEMBER_REQUIRED',
                        'Les attributions configurées n’accordent pas MEMBER après inscription.', 'registration',
                        ['space_id' => $space->id]),
                );
            }
        } finally {
            // Ne pas conserver un instantané des droits d’une transaction encore susceptible d’être annulée.
            $acl->invalidateUser($userId);
        }
    }
}
