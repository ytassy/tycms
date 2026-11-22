<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Webhook;

use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\WF\BaseHelper;

/** Génération, rotation et déchiffrement des secrets webhook. */
final class WebhookSecrets extends BaseHelper
{
    use TraitAutomationHelpers;

    public function generateToken(int $scenarioId, string $mode): string
    {
        $trigger = $this->automationRepository->findTrigger($scenarioId);
        $config = WebhookConfig::decode($trigger !== null ? (string) $trigger->config : null);
        $config['auth_mode'] = $mode;
        unset($config[WebhookConfig::HMAC_FIELD]);

        $token = bin2hex(random_bytes(32));

        $this->automationRepository->updateTriggerSecrets(
            $scenarioId,
            json_encode($config, JSON_THROW_ON_ERROR),
            hash('sha256', $token),
        );

        return $token;
    }

    public function generateHmacSecret(int $scenarioId): string
    {
        $trigger = $this->automationRepository->findTrigger($scenarioId);
        $config = WebhookConfig::decode($trigger !== null ? (string) $trigger->config : null);
        $config['auth_mode'] = WebhookAuthMode::HMAC;

        $secret = bin2hex(random_bytes(32));
        $config[WebhookConfig::HMAC_FIELD] = $this->cryptHelper()->encrypt(
            $secret,
            WebhookConfig::HMAC_PURPOSE,
        );

        $this->automationRepository->updateTriggerSecrets(
            $scenarioId,
            json_encode($config, JSON_THROW_ON_ERROR),
            null,
        );

        return $secret;
    }

    public function hmacSecret(object $trigger): ?string
    {
        $config = WebhookConfig::decode((string) ($trigger->config ?? ''));

        if (WebhookConfig::authMode($config) !== WebhookAuthMode::HMAC) {
            return null;
        }

        $encrypted = $config[WebhookConfig::HMAC_FIELD] ?? null;

        if (!is_string($encrypted) || $encrypted === '') {
            return null;
        }

        try {
            return $this->cryptHelper()->decrypt(
                $encrypted,
                WebhookConfig::HMAC_PURPOSE,
            );
        } catch (\Throwable $exception) {
            $this->logWarning(
                'Le secret HMAC du webhook est illisible (scénario #' . (int) ($trigger->scenario_id ?? 0) . ').',
                ['space_id' => (int) ($trigger->space_id ?? 0)],
                'AUTOMATION_WEBHOOK_SECRET_INVALID',
                'automation',
            );
            $this->logException($exception);

            return null;
        }
    }

    private function cryptHelper(): CryptHelper
    {
        /** @var CryptHelper $helper */
        $helper = $this->getSharedExtensionsClass(CryptHelper::class);

        return $helper;
    }
}
