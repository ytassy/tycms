<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation;

use Override;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPayloadFieldDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalMode;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationProvider;
use YTassy\Tycms\CoreExts\Automation\Controller\AutomationController;
use YTassy\Tycms\CoreExts\Automation\Controller\AutomationWebhookController;
use YTassy\Tycms\CoreExts\Automation\Migration\Mig_1_0_0;
use YTassy\Tycms\CoreExts\Automation\Migration\Mig_1_0_1;
use YTassy\Tycms\CoreExts\Automation\Notification\NotificationSecrets;
use YTassy\Tycms\CoreExts\Automation\Notification\NotificationTargets;
use YTassy\Tycms\CoreExts\Automation\Schedule\CronWorker;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\Automation\Support\GoogleSheetsConfig;
use YTassy\Tycms\CoreExts\Core\Cron\CronTick;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\BasePermissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\ICronProvider;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCronProvider;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\Extensions\BasePlugin;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Routing\Route;

/**
 * Socle du moteur d'automatisation et du langage de scénarios.
 *
 * Le parser, le registre d'actions, les déclencheurs et l'exécution sont
 * ajoutés par lots. Aucune action métier n'est codée ici : les extensions
 * déclarent les leurs via IAutomationProvider.
 */
final class Plugin extends BasePlugin implements IAutomationProvider, ICronProvider
{
    use TraitAutomationHelpers;
    use TraitCronProvider;

    private ?AutomationActionRegistry $actionRegistry = null;
    private ?AutomationTriggerRegistry $triggerRegistry = null;

    public function getName(): string
    {
        return 'Automatisation';
    }

    public function getVersion(): string
    {
        // Les extensions Core partagent la version du système.
        return APP_VERSION;
    }

    public function getDescription(): string
    {
        return 'Moteur d’automatisation et langage de scénarios.';
    }

    public function getIconClass(): string
    {
        return 'fa-solid fa-diagram-project';
    }

    #[Override]
    protected function _getMigrations(): array
    {
        return [
            '1.0.0' => [Mig_1_0_0::class],
            '1.0.1' => [Mig_1_0_1::class],
        ];
    }

    public function coreOnRegisterAclPermissions(): ?BasePermissions
    {
        return new Permissions($this);
    }

    public function onAfterLoadExtensions(): void
    {
        $this->actionRegistry = new AutomationActionRegistry($this);
        $this->triggerRegistry = new AutomationTriggerRegistry($this);

        foreach ($this->app->getExtensions() as $ownerId => $extension) {
            if ($extension === $this || !$extension instanceof IAutomationProvider) {
                continue;
            }

            $this->actionRegistry->registerProvider((string) $ownerId, $extension);
            $this->triggerRegistry->registerProvider((string) $ownerId, $extension);
        }

        // Le moteur expose aussi ses propres déclencheurs (webhook, planifié).
        $this->actionRegistry->registerProvider('plg_automation', $this);
        $this->triggerRegistry->registerProvider('plg_automation', $this);
    }

    public function coreOnRegisterAutomationActions(AutomationActionRegistry $registry): void
    {
        // Aucune action métier dans le moteur ; les extensions les déclarent.
    }

    public function coreOnRegisterAutomationTriggers(AutomationTriggerRegistry $registry): void
    {
        $registry->register(new AutomationTriggerDefinition(
            id: 'automation.webhook.received',
            label: 'Webhook reçu',
            description: 'Déclenché par un appel HTTP externe authentifié.',
            defaultPrincipalMode: AutomationPrincipalMode::AUTHOR,
            payloadFields: [],
            payloadExample: [
                'event' => 'exemple',
                'data' => ['id' => 1],
            ],
        ));

        $registry->register(new AutomationTriggerDefinition(
            id: 'automation.schedule.tick',
            label: 'Planification',
            description: 'Déclenché par le worker selon la planification du scénario.',
            defaultPrincipalMode: AutomationPrincipalMode::AUTHOR,
            payloadFields: [
                new AutomationPayloadFieldDefinition(
                    'scheduled_at',
                    'string',
                    true,
                    'Date et heure du créneau, en ISO 8601.',
                ),
                new AutomationPayloadFieldDefinition(
                    'slot',
                    'string',
                    true,
                    'Créneau de planification, par exemple 2026-11-17T09:00.',
                ),
                new AutomationPayloadFieldDefinition(
                    'preset',
                    'string',
                    true,
                    'Preset de planification exécuté.',
                ),
            ],
            payloadExample: [
                'scheduled_at' => '2026-11-17T09:00:00+01:00',
                'slot' => '2026-11-17T09:00',
                'preset' => 'daily',
            ],
        ));
    }

    public function getActionRegistry(): ?AutomationActionRegistry
    {
        return $this->actionRegistry;
    }

    public function getTriggerRegistry(): ?AutomationTriggerRegistry
    {
        return $this->triggerRegistry;
    }

    /**
     * Les événements émis pendant la requête sont drainés après le commit.
     *
     * Le hook est déjà appelé hors transaction ; ses erreurs sont journalisées
     * par le runner et ne remplacent jamais la réponse métier.
     */
    public function onAfterRouteExecuted(Route $route, bool $transactionCommitted): void
    {
        $this->automationEventBuffer->drain($transactionCommitted);
    }

    /**
     * Hook CRON global : exécute les planifications arrivées à échéance.
     *
     * @return array<string, int>
     */
    public function onCronMinute(CronTick $tick): array
    {
        /** @var CronWorker $worker */
        $worker = $this->getSharedExtensionsClass(CronWorker::class);

        return $worker->run($tick);
    }

    public function getControllers(): array
    {
        return [
            new AutomationController($this),
            new AutomationWebhookController($this),
        ];
    }

    /** La configuration contient des secrets de notification et Google. */
    public function getConfigEditPermissions(): array
    {
        return [Permissions::ADMIN];
    }

    public function coreRegisterConfigForm(): ?Form
    {
        $enabled = static fn (string $name, string $label, string $default = '0'): array => [
            'name' => $name,
            'type' => 'core.select',
            'label' => $label,
            'default' => $default,
            'options' => [
                ['value' => '0', 'label' => 'Non'],
                ['value' => '1', 'label' => 'Oui'],
            ],
        ];

        return new Form($this, [
            'id' => 'plugin.automation.config',
            'version' => 1,
            'sections' => [
                [
                    'id' => 'notifications',
                    'label' => 'Notifications d’échec',
                    'fields' => [
                        ['type' => 'core.note', 'description' => 'Ces canaux sont réservés aux runs en échec. Une notification best-effort est envoyée après la persistance du statut. Aucun payload, aucune donnée utilisateur et aucun secret ne sont transmis. Les jetons et webhooks sont chiffrés avec le secret de l’installation.'],
                        $enabled('notify_on_failure', 'Notifier les runs en échec', '1'),
                        $enabled('telegram_enabled', 'Telegram'),
                        ['name' => 'telegram_token', 'type' => 'core.text', 'label' => 'Jeton du bot Telegram', 'default' => '', 'ui' => ['inputType' => 'password'], 'validation' => ['maxLength' => 512]],
                        ['name' => 'telegram_chat_id', 'type' => 'core.text', 'label' => 'Identifiant du chat Telegram', 'default' => '', 'validation' => ['maxLength' => 64]],
                        $enabled('slack_enabled', 'Slack'),
                        ['name' => 'slack_webhook', 'type' => 'core.text', 'label' => 'Webhook Slack', 'default' => '', 'ui' => ['inputType' => 'password'], 'validation' => ['maxLength' => 512]],
                        $enabled('discord_enabled', 'Discord'),
                        ['name' => 'discord_webhook', 'type' => 'core.text', 'label' => 'Webhook Discord', 'default' => '', 'ui' => ['inputType' => 'password'], 'validation' => ['maxLength' => 512]],
                    ],
                ],
                [
                    'id' => 'notifications_scenario',
                    'label' => 'Notifications de scénario',
                    'fields' => [
                        ['type' => 'core.note', 'description' => 'Ces canaux sont indépendants des notifications d’échec. Ils servent à core::notify et comme destination par défaut de core::sendTelegram, core::sendSlack et core::sendDiscord. Les jetons et webhooks sont chiffrés avec le secret de l’installation.'],
                        $enabled('scenario_telegram_enabled', 'Telegram'),
                        ['name' => 'scenario_telegram_token', 'type' => 'core.text', 'label' => 'Jeton du bot Telegram', 'default' => '', 'ui' => ['inputType' => 'password'], 'validation' => ['maxLength' => 512]],
                        ['name' => 'scenario_telegram_chat_id', 'type' => 'core.text', 'label' => 'Identifiant du chat Telegram', 'default' => '', 'validation' => ['maxLength' => 64]],
                        $enabled('scenario_slack_enabled', 'Slack'),
                        ['name' => 'scenario_slack_webhook', 'type' => 'core.text', 'label' => 'Webhook Slack', 'default' => '', 'ui' => ['inputType' => 'password'], 'validation' => ['maxLength' => 512]],
                        $enabled('scenario_discord_enabled', 'Discord'),
                        ['name' => 'scenario_discord_webhook', 'type' => 'core.text', 'label' => 'Webhook Discord', 'default' => '', 'ui' => ['inputType' => 'password'], 'validation' => ['maxLength' => 512]],
                        ['type' => 'core.note', 'description' => 'Profils nommés facultatifs, utilisés avec le paramètre target de core::sendTelegram/Slack/Discord. Format JSON : {"support":{"type":"telegram","token":"123:ABC","chat_id":"12345"},"alertes":{"type":"slack","webhook":"https://hooks.slack.com/services/..."}}. Les clés sont en minuscules, chiffres, tirets ou underscores. Le champ est chiffré au repos et affiché ici pour permettre son édition.'],
                        ['name' => NotificationSecrets::TARGETS_FIELD, 'type' => 'core.code', 'syntax' => 'json', 'label' => 'Profils de notification', 'default' => '', 'validation' => ['maxBytes' => NotificationTargets::MAX_JSON_BYTES]],
                    ],
                ],
                [
                    'id' => 'google_sheets',
                    'label' => 'Google API (Sheets et Agenda)',
                    'fields' => [
                        ['type' => 'core.note', 'description' => 'Collez ici le JSON du compte de service Google. Partagez chaque classeur et chaque agenda avec l’adresse client_email du compte. La clé privée est chiffrée au repos et affichée uniquement aux administrateurs autorisés.'],
                        ['name' => GoogleSheetsConfig::FIELD, 'type' => 'core.code', 'syntax' => 'json', 'label' => 'Configuration Google API JSON', 'default' => '', 'validation' => ['maxBytes' => GoogleSheetsConfig::MAX_BYTES]],
                    ],
                ],
                [
                    'id' => 'maintenance',
                    'label' => 'Maintenance et rétention',
                    'fields' => [
                        ['type' => 'core.note', 'description' => 'La reprise des événements bloqués et le marquage des runs interrompus sont manuels, depuis l’écran de maintenance. Le cron ne les reprend pas automatiquement. Le nettoyage ne supprime que les événements terminés et les runs terminés ; les étapes des runs supprimés suivent la cascade.'],
                        ['name' => 'event_retention_days', 'type' => 'core.number', 'label' => 'Rétention des événements (jours)', 'default' => 30, 'validation' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650]],
                        ['name' => 'run_retention_days', 'type' => 'core.number', 'label' => 'Rétention des runs et étapes (jours)', 'default' => 90, 'validation' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 3650]],
                    ],
                ],
            ],
        ]);
    }

    public function prepareConfigForStorage(int $spaceId, array $params): array
    {
        $stored = $this->storedConfig($spaceId);

        foreach (NotificationSecrets::FIELDS as $field) {
            $value = $params[$field] ?? '';
            $value = is_string($value) ? $value : '';

            if ($value === '' || $value === NotificationSecrets::MASK) {
                $params[$field] = (string) ($stored[$field] ?? '');
                continue;
            }

            $params[$field] = $this->cryptHelper()->encrypt(
                $value,
                NotificationSecrets::PURPOSE,
            );
        }

        $targets = $params[NotificationSecrets::TARGETS_FIELD] ?? null;
        $targets = is_string($targets) ? trim($targets) : null;

        if ($targets === null || $targets === NotificationSecrets::MASK) {
            $params[NotificationSecrets::TARGETS_FIELD] = (string) (
                $stored[NotificationSecrets::TARGETS_FIELD] ?? ''
            );
        } elseif ($targets === '') {
            // Un champ vidé retire les profils ; le masque, lui, conserve l'existant.
            $params[NotificationSecrets::TARGETS_FIELD] = '';
        } else {
            try {
                NotificationTargets::parse($targets);
            } catch (\InvalidArgumentException $exception) {
                ExceptionResponse::throwWarning($exception->getMessage());
            }

            $params[NotificationSecrets::TARGETS_FIELD] = $this->cryptHelper()->encrypt(
                $targets,
                NotificationSecrets::TARGETS_PURPOSE,
            );
        }

        if (!array_key_exists(GoogleSheetsConfig::FIELD, $params)) {
            // Une sauvegarde partielle ne doit pas effacer la configuration.
            $params[GoogleSheetsConfig::FIELD] = (string) ($stored[GoogleSheetsConfig::FIELD] ?? '');
        } else {
            $google = $params[GoogleSheetsConfig::FIELD] ?? '';
            $google = is_string($google) ? trim($google) : '';

            if ($google === '') {
                // Le champ affiché en clair permet de retirer volontairement la configuration.
                $params[GoogleSheetsConfig::FIELD] = '';
            } else {
                if (strlen($google) > GoogleSheetsConfig::MAX_BYTES) {
                    ExceptionResponse::throwWarning('La configuration Google dépasse la taille maximale.');
                }

                try {
                    GoogleSheetsConfig::validateJson($google);
                } catch (\InvalidArgumentException $exception) {
                    ExceptionResponse::throwWarning($exception->getMessage());
                }

                $params[GoogleSheetsConfig::FIELD] = $this->cryptHelper()->encrypt(
                    $google,
                    GoogleSheetsConfig::PURPOSE,
                );
            }
        }

        return $params;
    }

    public function prepareConfigForEditor(int $spaceId, array $params): array
    {
        $stored = $this->storedConfig($spaceId);

        foreach (NotificationSecrets::FIELDS as $field) {
            $params[$field] = ((string) ($stored[$field] ?? '')) !== ''
                ? NotificationSecrets::MASK
                : '';
        }

        $params[NotificationSecrets::TARGETS_FIELD] = $this->decryptTargets(
            (string) ($stored[NotificationSecrets::TARGETS_FIELD] ?? ''),
            $spaceId,
        );
        $params[GoogleSheetsConfig::FIELD] = $this->decryptGoogleSheets(
            (string) ($stored[GoogleSheetsConfig::FIELD] ?? ''),
            $spaceId,
        );

        return $params;
    }

    private function decryptGoogleSheets(string $value, int $spaceId): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return $this->cryptHelper()->decrypt($value, GoogleSheetsConfig::PURPOSE);
        } catch (\Throwable $exception) {
            $this->logWarning(
                'La configuration Google Sheets est illisible.',
                ['space_id' => $spaceId],
                'GOOGLE_SHEETS_CONFIG_INVALID',
                'automation',
            );
            $this->logException($exception);

            return '';
        }
    }

    private function decryptTargets(string $value, int $spaceId): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return $this->cryptHelper()->decrypt(
                $value,
                NotificationSecrets::TARGETS_PURPOSE,
            );
        } catch (\Throwable $exception) {
            $this->logWarning(
                'Les profils de notification sont illisibles.',
                ['space_id' => $spaceId],
                'AUTOMATION_NOTIFICATION_SECRET_INVALID',
                'automation',
            );
            $this->logException($exception);

            return '';
        }
    }

    /** @return array<string, mixed> */
    private function storedConfig(int $spaceId): array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT params FROM config WHERE space_id = % AND extension = % LIMIT 1',
            [$spaceId, 'plg_automation'],
        );

        if ($row === null) {
            return [];
        }

        $decoded = json_decode((string) $row->params, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function cryptHelper(): CryptHelper
    {
        /** @var CryptHelper $helper */
        $helper = $this->getSharedExtensionsClass(CryptHelper::class);

        return $helper;
    }
}
