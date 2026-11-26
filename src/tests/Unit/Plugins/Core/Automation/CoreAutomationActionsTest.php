<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationActions;
use YTassy\Tycms\CoreExts\Core\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class CoreAutomationActionsTest extends FrameworkTestCase
{
    #[Test]
    public function coreDeclaresItsActionsWithValidHandlers(): void
    {
        $definitions = CoreAutomationActions::definitions();

        self::assertCount(65, $definitions);
        self::assertSame(
            ['core::sendMail', 'core::sendMailTemplate', 'core::notify', 'core::sendTelegram', 'core::sendSlack',
                'core::sendDiscord', 'core::listPageComponents', 'core::listPageHistory',
                'core::readPageVersion', 'core::listMenuTypes', 'core::getUser',
                'core::listPermissions', 'core::listRoles', 'core::getRole', 'core::createRole',
                'core::updateRole', 'core::deleteRole', 'core::listGroups', 'core::getGroup',
                'core::createGroup', 'core::updateGroup', 'core::setGroupRoles', 'core::deleteGroup',
                'core::createUser', 'core::updateUser', 'core::deleteUser',
                'core::listMediaSpace', 'core::listMedia', 'core::readMediaFile',
                'core::createMediaDirectory', 'core::createMediaFile', 'core::saveMediaFile',
                'core::renameMedia', 'core::moveMedia', 'core::deleteMedia',
                'core::listMenus', 'core::createMenu', 'core::updateMenu',
                'core::createPageMenuItem', 'core::createTextMenuItem',
                'core::createSeparatorMenuItem', 'core::deleteMenuItem',
                'core::listUsers', 'core::addRole',
                'core::removeRole', 'core::addToGroup', 'core::removeFromGroup',
                'core::sessionRead', 'core::sessionWrite', 'core::sessionRemove',
                'google_sheets::appendRow', 'google_sheets::appendRows', 'google_sheets::insertRow',
                'google_sheets::readRange',
                'google_sheets::readSheet', 'google_sheets::updateRange', 'google_sheets::listSheets',
                'google_sheets::findRows', 'google_sheets::upsertRow', 'google_sheets::clearRange',
                'google_calendar::listEvents', 'google_calendar::getEvent',
                'google_calendar::createEvent', 'google_calendar::updateEvent',
                'google_calendar::deleteEvent'],
            array_map(static fn ($definition): string => $definition->id, $definitions),
        );

        foreach ($definitions as $definition) {
            self::assertTrue(class_exists($definition->handlerClass));
            self::assertTrue(is_subclass_of($definition->handlerClass, BaseAutomationAction::class));
        }
    }

    #[Test]
    public function notificationActionsExposeTheirPublicParameters(): void
    {
        $definitions = array_column(CoreAutomationActions::definitions(), null, 'id');

        foreach (['core::notify', 'core::sendTelegram', 'core::sendSlack', 'core::sendDiscord'] as $id) {
            $definition = $definitions[$id];
            self::assertSame('bool', $definition->returnType);
            self::assertSame('external', $definition->effect);
            self::assertContains('plg_automation__PUBLISH', $definition->requiredPermissions);
            self::assertSame('message', $definition->parameters[0]->name);
        }

        self::assertSame('target', $definitions['core::sendTelegram']->parameters[2]->name);
        self::assertSame('target', $definitions['core::sendSlack']->parameters[2]->name);
        self::assertSame('target', $definitions['core::sendDiscord']->parameters[2]->name);
        self::assertSame('channels', $definitions['core::notify']->parameters[2]->name);
    }

    #[Test]
    public function mailActionsExposeTheirPublicParameters(): void
    {
        $definitions = array_column(CoreAutomationActions::definitions(), null, 'id');

        foreach (['core::sendMail', 'core::sendMailTemplate'] as $id) {
            $definition = $definitions[$id];
            self::assertSame('bool', $definition->returnType);
            self::assertSame('external', $definition->effect);
            self::assertContains('plg_core__SPACE_ADMIN', $definition->requiredPermissions);
        }

        self::assertSame(
            ['to', 'subject', 'htmlBody'],
            array_map(static fn ($parameter): string => $parameter->name, $definitions['core::sendMail']->parameters),
        );
        self::assertSame(
            ['template', 'to', 'vars'],
            array_map(static fn ($parameter): string => $parameter->name, $definitions['core::sendMailTemplate']->parameters),
        );
    }

    #[Test]
    public function googleSheetsActionsExposeTheirContract(): void
    {
        $definitions = array_column(CoreAutomationActions::definitions(), null, 'id');

        $append = $definitions['google_sheets::appendRow'];
        self::assertSame('int', $append->returnType);
        self::assertSame('external', $append->effect);
        self::assertContains('plg_automation__PUBLISH', $append->requiredPermissions);
        self::assertSame(['spreadsheet_id', 'sheet_id', 'range', 'values', 'value_input_option'],
            array_map(static fn ($parameter): string => $parameter->name, $append->parameters));

        $read = $definitions['google_sheets::readSheet'];
        self::assertSame('array', $read->returnType);
        self::assertSame('read', $read->effect);
        self::assertSame(['spreadsheet_id', 'sheet_id', 'value_render_option'],
            array_map(static fn ($parameter): string => $parameter->name, $read->parameters));

        $insert = $definitions['google_sheets::insertRow'];
        self::assertSame('bool', $insert->returnType);
        self::assertSame(
            ['spreadsheet_id', 'sheet_id', 'row_index', 'values'],
            array_map(static fn ($parameter): string => $parameter->name, $insert->parameters),
        );
        self::assertSame('google_sheets::upsertRow', $definitions['google_sheets::upsertRow']->id);
        self::assertSame('google_sheets::clearRange', $definitions['google_sheets::clearRange']->id);
    }

    #[Test]
    public function googleCalendarActionsExposeTheirContract(): void
    {
        $definitions = array_column(CoreAutomationActions::definitions(), null, 'id');

        $create = $definitions['google_calendar::createEvent'];
        self::assertSame('array', $create->returnType);
        self::assertSame('external', $create->effect);
        self::assertContains('plg_automation__PUBLISH', $create->requiredPermissions);
        self::assertSame(
            ['calendar_id', 'summary', 'start', 'end', 'description', 'location', 'time_zone',
                'attendees', 'send_updates'],
            array_map(static fn ($parameter): string => $parameter->name, $create->parameters),
        );
        self::assertSame('read', $definitions['google_calendar::listEvents']->effect);
    }

    #[Test]
    public function corePluginRegistersActionsUnderItsOwnership(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_core', new Plugin($app));

        self::assertTrue($registry->has('core::sendMail'));
        self::assertTrue($registry->has('core::sendMailTemplate'));
        self::assertTrue($registry->has('core::notify'));
        self::assertTrue($registry->has('core::sendTelegram'));
        self::assertTrue($registry->has('core::addRole'));
        self::assertTrue($registry->has('core::listPageHistory'));
        self::assertTrue($registry->has('core::readPageVersion'));
        self::assertTrue($registry->has('google_sheets::appendRow'));
        self::assertTrue($registry->has('google_sheets::insertRow'));
        self::assertTrue($registry->has('google_sheets::upsertRow'));
        self::assertTrue($registry->has('google_calendar::createEvent'));
        self::assertSame('plg_core', $registry->ownerOf('core::sendMail'));
        self::assertSame('plg_core', $registry->ownerOf('core::sendMailTemplate'));
        self::assertSame('plg_core', $registry->ownerOf('google_sheets::appendRow'));
        self::assertSame('plg_core', $registry->ownerOf('google_calendar::createEvent'));

        // L'action getUser est désormais déclarée par annotation sur le WF.
        self::assertTrue($registry->has('core::getUser'));
        self::assertSame('plg_core', $registry->ownerOf('core::getUser'));

        self::assertCount(65, $registry->all());
    }
}
