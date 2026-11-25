<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\Automation\Support\UserPayload;
use YTassy\Tycms\CoreExts\Core\WF\WFAdminUser;

/**
 * Modification des informations d'un compte existant.
 *
 * Ni mot de passe ni SSO : ces données ne se modifient pas depuis le serveur
 * MCP. Les comptes de service n'ont pas de profil modifiable ; le WF refuse
 * aussi les modifications de comptes ROOT par un non-ROOT.
 */
final class UpdateUserAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $userId = ValueTools::assertInt($params['userId'] ?? null, $context->span);
        $firstName = ValueTools::assertString($params['firstName'] ?? null, $context->span);
        $lastName = ValueTools::assertString($params['lastName'] ?? null, $context->span);
        $email = ValueTools::assertString($params['email'] ?? null, $context->span);
        $timezone = ValueTools::assertString($params['timezone'] ?? 'Europe/Paris', $context->span);
        $blocked = ValueTools::assertBool($params['blocked'] ?? false, $context->span);
        $sendEmail = ValueTools::assertBool($params['sendEmail'] ?? true, $context->span);

        try {
            /** @var WFAdminUser $workflow */
            $workflow = $this->getSharedExtensionsClass(WFAdminUser::class);

            return UserPayload::map($workflow->updateUser($userId, (object) [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'timezone' => $timezone,
                'blocked' => $blocked,
                'send_email' => $sendEmail,
            ]));
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
