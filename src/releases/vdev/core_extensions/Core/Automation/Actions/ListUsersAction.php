<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\Automation\Support\UserPayload;
use YTassy\Tycms\CoreExts\Core\WF\WFAdminUser;

final class ListUsersAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        $page = ValueTools::assertInt($params['page'] ?? 1, $context->span);
        $perPage = ValueTools::assertInt($params['perPage'] ?? 20, $context->span);
        $search = ValueTools::assertString($params['search'] ?? '', $context->span);

        try {
            /** @var WFAdminUser $workflow */
            $workflow = $this->getSharedExtensionsClass(WFAdminUser::class);
            $result = $workflow->listUsers($page, $perPage, $search);

            return [
                'users' => array_map(
                    static fn (array $user): array => UserPayload::map($user),
                    $result['users'] ?? [],
                ),
                'total' => (int) ($result['total'] ?? 0),
                'page' => (int) ($result['page'] ?? $page),
                'perPage' => (int) ($result['per_page'] ?? $perPage),
            ];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
