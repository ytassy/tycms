<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Registry;

/**
 * Documentation des variables de contexte fournies au scénario.
 *
 * `$input` dépend du déclencheur : il est assemblé depuis la définition de
 * celui-ci. Les trois autres variables sont stables et en lecture seule.
 */
final class ContextVariableCatalog
{
    /**
     * @return list<array{
     *     name: string,
     *     description: string,
     *     readonly: bool,
     *     fields: list<array{name: string, type: string, required: bool, description: string}>,
     *     example: array<string, mixed>
     * }>
     */
    public static function base(): array
    {
        return [
            [
                'name' => '$principal',
                'description' => 'Identité d’exécution fiable fournie par le moteur.',
                'readonly' => true,
                'fields' => [
                    ['name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Identifiant du compte.'],
                    ['name' => 'type', 'type' => 'string', 'required' => true, 'description' => 'Type de principal fourni par le moteur.'],
                    ['name' => 'firstName', 'type' => 'string', 'required' => true, 'description' => 'Prénom du compte.'],
                    ['name' => 'lastName', 'type' => 'string', 'required' => true, 'description' => 'Nom du compte.'],
                    ['name' => 'email', 'type' => 'string', 'required' => true, 'description' => 'Adresse e-mail du compte.'],
                ],
                'example' => [
                    'id' => 42,
                    'type' => 'user',
                    'firstName' => 'Ada',
                    'lastName' => 'Lovelace',
                    'email' => 'ada@example.test',
                ],
            ],
            [
                'name' => '$space',
                'description' => 'Space du scénario.',
                'readonly' => true,
                'fields' => [
                    ['name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Identifiant du Space.'],
                    ['name' => 'name', 'type' => 'string', 'required' => true, 'description' => 'Nom du Space.'],
                    ['name' => 'timezone', 'type' => 'string', 'required' => true, 'description' => 'Fuseau horaire IANA du Space.'],
                ],
                'example' => [
                    'id' => 2,
                    'name' => 'Site principal',
                    'timezone' => 'Europe/Paris',
                ],
            ],
            [
                'name' => '$run',
                'description' => 'Run courant.',
                'readonly' => true,
                'fields' => [
                    ['name' => 'id', 'type' => 'int', 'required' => true, 'description' => 'Identifiant du run.'],
                    ['name' => 'scenarioId', 'type' => 'int', 'required' => true, 'description' => 'Identifiant du scénario.'],
                    ['name' => 'versionId', 'type' => 'int', 'required' => true, 'description' => 'Version publiée exécutée.'],
                    ['name' => 'startedAt', 'type' => 'date', 'required' => true, 'description' => 'Date de début ; à formater avec date::format.'],
                    ['name' => 'trigger', 'type' => 'string', 'required' => true, 'description' => 'Identifiant du déclencheur, ou manual / dry-run.'],
                ],
                'example' => [
                    'id' => 123,
                    'scenarioId' => 7,
                    'versionId' => 5,
                    'startedAt' => '2026-11-16T09:30:00+01:00',
                    'trigger' => 'core.user.created',
                ],
            ],
        ];
    }

    /**
     * @param list<array{name: string, type: string, required: bool, description: string}> $payloadFields
     * @param array<string, mixed>|null $payloadExample
     * @return array{
     *     name: string,
     *     description: string,
     *     readonly: bool,
     *     fields: list<array{name: string, type: string, required: bool, description: string}>,
     *     example: array<string, mixed>|null
     * }
     */
    public static function input(array $payloadFields, ?array $payloadExample): array
    {
        return [
            'name' => '$input',
            'description' => 'Charge utile du déclencheur, non fiable et toujours une map. En exécution manuelle ou en simulation, l’entrée de test est libre.',
            'readonly' => true,
            'fields' => $payloadFields,
            'example' => $payloadExample,
        ];
    }
}
