<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\ContextVariableCatalog;
use YTassy\Tycms\CoreExts\Automation\Reference\AutomationReferenceDocument;

final class AutomationReferenceDocumentTest extends TestCase
{
    #[Test]
    public function markdownDocumentsBuiltinsActionsContextAndTriggers(): void
    {
        $markdown = AutomationReferenceDocument::markdown(
            [
                [
                    'namespace' => 'str',
                    'id' => 'str::length',
                    'signature' => 'str::length(string $value): int',
                    'description' => 'Longueur en caractères.',
                    'effect' => 'pure',
                ],
                [
                    'namespace' => 'util',
                    'id' => 'util::log',
                    'signature' => 'util::log(string $message, ?array $context = null): null',
                    'description' => 'Écrit | dans le journal.',
                    'effect' => 'write',
                ],
            ],
            [[
                'id' => 'core::sendMail',
                'label' => 'Envoyer un e-mail',
                'description' => 'Envoie un modèle.',
                'return_type' => 'bool',
                'effect' => 'external',
                'required_permissions' => ['plg_core__SPACE_ADMIN'],
                'parameters' => [
                    [
                        'name' => 'template',
                        'type' => 'string',
                        'required' => true,
                        'default' => null,
                        'label' => 'Modèle',
                        'description' => 'Clé du modèle.',
                    ],
                    [
                        'name' => 'vars',
                        'type' => 'array',
                        'required' => false,
                        'default' => [],
                        'label' => 'Variables',
                        'description' => 'Variables | du modèle.',
                    ],
                ],
            ]],
            ContextVariableCatalog::base(),
            [[
                'id' => 'core.user.created',
                'label' => 'Compte créé',
                'description' => 'Après création.',
                'default_principal_mode' => 'trigger',
                'payload_fields' => [[
                    'name' => 'user.email',
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Adresse e-mail.',
                ]],
                'payload_example' => ['user' => ['email' => 'ada@example.test']],
            ]],
            [
                [
                    'id' => 'stop',
                    'signature' => 'stop(?string $message = null)',
                    'description' => 'Arrête le scénario avec succès.',
                ],
            ],
        );

        self::assertStringContainsString('# Automatisation — référence', $markdown);
        self::assertStringContainsString('## Contrôle du flux', $markdown);
        self::assertStringContainsString('stop(?string $message = null)', $markdown);
        self::assertStringContainsString('## Fonctions standard', $markdown);
        self::assertStringContainsString('str::length(string $value): int', $markdown);
        self::assertStringContainsString('Écrit \\| dans le journal.', $markdown);
        self::assertStringContainsString('## Actions', $markdown);
        self::assertStringContainsString('core::sendMail', $markdown);
        self::assertStringContainsString('plg_core__SPACE_ADMIN', $markdown);
        self::assertStringContainsString('Variables \\| du modèle.', $markdown);
        self::assertStringContainsString('## Variables de contexte', $markdown);
        self::assertStringContainsString('$principal', $markdown);
        self::assertStringContainsString('## Déclencheurs', $markdown);
        self::assertStringContainsString('core.user.created', $markdown);
        self::assertStringContainsString('user.email', $markdown);
        self::assertStringContainsString('"email": "ada@example.test"', $markdown);
    }
}
