<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\WF\WFAdminUser;

/**
 * Création d'un compte utilisateur.
 *
 * Le mot de passe n'est jamais fourni par l'appelant : l'action en génère un,
 * que le compte ne peut pas connaître par la sortie. Il ne circule que par le
 * message de bienvenue, envoyé par défaut ; sans cet envoi, le compte reste
 * inutilisable jusqu'à une réinitialisation par un administrateur.
 */
final class CreateUserAction extends BaseAutomationAction
{
    /** Longueur du secret généré, en octets hexadécimaux. */
    private const PASSWORD_BYTES = 16;

    public function execute(ActionContext $context, array $params): mixed
    {
        $spaceId = ValueTools::assertInt($params['spaceId'] ?? null, $context->span);
        $firstName = ValueTools::assertString($params['firstName'] ?? null, $context->span);
        $lastName = ValueTools::assertString($params['lastName'] ?? null, $context->span);
        $email = ValueTools::assertString($params['email'] ?? null, $context->span);
        $timezone = ValueTools::assertString($params['timezone'] ?? 'Europe/Paris', $context->span);
        $blocked = ValueTools::assertBool($params['blocked'] ?? false, $context->span);
        $sendEmail = ValueTools::assertBool($params['sendEmail'] ?? true, $context->span);

        // Par défaut, le message de bienvenue part : c'est le seul moyen pour
        // le titulaire de recevoir le secret généré.
        $sendWelcomeEmail = ValueTools::assertBool($params['sendWelcomeEmail'] ?? true, $context->span);

        // 16 octets aléatoires donnent 32 caractères hexadécimaux, dans la
        // plage acceptée par la politique de mot de passe (12 à 72 octets).
        $password = bin2hex(random_bytes(self::PASSWORD_BYTES));

        try {
            /** @var WFAdminUser $workflow */
            $workflow = $this->getSharedExtensionsClass(WFAdminUser::class);

            $created = $workflow->createUser((object) [
                'space_id' => $spaceId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'timezone' => $timezone,
                'blocked' => $blocked,
                'send_email' => $sendEmail,
                'password' => $password,
                'send_welcome_email' => $sendWelcomeEmail,
            ]);

            // Le secret ne sort jamais de l'action : on ne renvoie que l'état
            // de l'envoi du message.
            return [
                'id' => (int) ($created['id'] ?? 0),
                'spaceId' => (int) ($created['space_id'] ?? $spaceId),
                'firstName' => (string) ($created['first_name'] ?? $firstName),
                'lastName' => (string) ($created['last_name'] ?? $lastName),
                'email' => (string) ($created['email'] ?? $email),
                'blocked' => (bool) ($created['blocked'] ?? $blocked),
                'welcomeEmailSent' => $created['welcome_email_sent'] ?? null,
            ];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
