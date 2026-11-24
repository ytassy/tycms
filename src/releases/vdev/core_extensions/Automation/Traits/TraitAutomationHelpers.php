<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Traits;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationEventBuffer;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTrigger;
use YTassy\Tycms\CoreExts\Automation\Helper\AutomationRepository;
use YTassy\Tycms\CoreExts\Automation\Mcp\McpProtocol;
use YTassy\Tycms\CoreExts\Automation\Notification\AutomationFailureNotifier;
use YTassy\Tycms\CoreExts\Automation\Notification\AutomationNotificationSender;
use YTassy\Tycms\CoreExts\Automation\Runtime\AutomationEventDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\AutomationRunExecutor;
use YTassy\Tycms\CoreExts\Automation\Runtime\McpCodeRunner;

trait TraitAutomationHelpers
{
    protected AutomationRepository $automationRepository {
        get => $this->getSharedExtensionsClass(AutomationRepository::class);
    }

    protected AutomationRunExecutor $automationRunExecutor {
        get => $this->getSharedExtensionsClass(AutomationRunExecutor::class);
    }

    protected McpCodeRunner $mcpCodeRunner {
        get => $this->getSharedExtensionsClass(McpCodeRunner::class);
    }

    protected McpProtocol $mcpProtocol {
        get => $this->getSharedExtensionsClass(McpProtocol::class);
    }

    protected AutomationTrigger $automationTrigger {
        get => $this->getSharedExtensionsClass(AutomationTrigger::class);
    }

    protected AutomationEventBuffer $automationEventBuffer {
        get => $this->getSharedExtensionsClass(AutomationEventBuffer::class);
    }

    protected AutomationEventDispatcher $automationEventDispatcher {
        get => $this->getSharedExtensionsClass(AutomationEventDispatcher::class);
    }

    protected AutomationFailureNotifier $automationFailureNotifier {
        get => $this->getSharedExtensionsClass(AutomationFailureNotifier::class);
    }

    protected AutomationNotificationSender $automationNotificationSender {
        get => $this->getSharedExtensionsClass(AutomationNotificationSender::class);
    }
}
