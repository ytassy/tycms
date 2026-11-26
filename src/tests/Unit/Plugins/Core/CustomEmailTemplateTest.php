<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\CustomEmailTemplate;
use YTassy\Tycms\Exceptions\ApplicationException;

final class CustomEmailTemplateTest extends TestCase
{
    public function testSlugKeepsTheReservedPrefixAndAnAsciiIdentifier(): void
    {
        // La clé complète suit la convention `<propriétaire>__<identifiant>` du registre.
        self::assertSame('custom__', CustomEmailTemplate::KEY_PREFIX);
        self::assertSame('bienvenue_apres_commande', CustomEmailTemplate::slug('Bienvenue après commande'));
        self::assertSame('evenement_2026', CustomEmailTemplate::slug('Événement 2026'));
    }

    public function testSlugAlwaysStartsWithALetterAndNeverStaysEmpty(): void
    {
        self::assertSame('m_123_modeles', CustomEmailTemplate::slug('123 modèles'));
        self::assertSame('modele', CustomEmailTemplate::slug('!!!'));
        self::assertLessThanOrEqual(150, strlen(CustomEmailTemplate::KEY_PREFIX . CustomEmailTemplate::slug(str_repeat('a', 300))));
    }

    public function testVariablesBecomeRequiredTextVariables(): void
    {
        $variables = CustomEmailTemplate::variables([
            ['name' => 'first_name', 'example' => 'Alice'],
            ['name' => 'order_id', 'example' => ''],
        ]);

        self::assertCount(2, $variables);
        self::assertSame('first_name', $variables[0]->name);
        self::assertSame('text', $variables[0]->type);
        self::assertTrue($variables[0]->required);
        self::assertSame('Alice', $variables[0]->example);
        self::assertSame('text', $variables[1]->type);
        self::assertSame('', $variables[1]->example);
    }

    public function testVariablesAcceptTheStdClassItemsProducedByTheSchemaValidator(): void
    {
        // Le validateur transmet les objets JSON imbriqués en stdClass, pas en tableaux.
        $variables = CustomEmailTemplate::variables([
            (object) ['name' => 'first_name', 'example' => 'Alice'],
            (object) ['name' => 'order_id'],
        ]);

        self::assertCount(2, $variables);
        self::assertSame('first_name', $variables[0]->name);
        self::assertSame('Alice', $variables[0]->example);
        self::assertSame('order_id', $variables[1]->name);
        self::assertSame('', $variables[1]->example);
    }

    public function testVariablesRefuseInvalidNamesDuplicatesAndOversizedLists(): void
    {
        foreach (['First', '1name', 'a-b', str_repeat('a', 51)] as $name) {
            $this->expectApplicationException(
                static fn () => CustomEmailTemplate::variables([['name' => $name, 'example' => '']]),
            );
        }

        $this->expectApplicationException(static fn () => CustomEmailTemplate::variables([
            ['name' => 'first_name', 'example' => ''],
            ['name' => 'first_name', 'example' => ''],
        ]));

        $this->expectApplicationException(static fn () => CustomEmailTemplate::variables(
            array_map(static fn (int $index): array => ['name' => 'variable_' . $index, 'example' => ''], range(1, 21)),
        ));
    }

    public function testVariablesRefuseAnOversizedExample(): void
    {
        $this->expectApplicationException(static fn () => CustomEmailTemplate::variables([
            ['name' => 'first_name', 'example' => str_repeat('a', 201)],
        ]));
    }

    public function testLabelDescriptionAndRenderModeAreBounded(): void
    {
        self::assertSame('Modèle', CustomEmailTemplate::label('  Modèle  '));
        self::assertSame('', CustomEmailTemplate::description(null));
        self::assertSame('blank', CustomEmailTemplate::renderMode('blank'));

        $this->expectApplicationException(static fn () => CustomEmailTemplate::label(''));
        $this->expectApplicationException(static fn () => CustomEmailTemplate::label(str_repeat('a', 151)));
        $this->expectApplicationException(static fn () => CustomEmailTemplate::description(str_repeat('a', 501)));
        $this->expectApplicationException(static fn () => CustomEmailTemplate::renderMode('raw'));
    }

    public function testDefinitionDecodesStoredVariablesAndRefusesInvalidJson(): void
    {
        $variables = CustomEmailTemplate::variables([['name' => 'first_name', 'example' => 'Alice']]);
        $row = (object) [
            'label' => 'Bienvenue',
            'description' => 'Message de bienvenue.',
            'render_mode' => 'default',
            'variables' => CustomEmailTemplate::encodeVariables($variables),
        ];

        $definition = CustomEmailTemplate::definition($row);
        self::assertSame('Bienvenue', $definition->label);
        self::assertTrue($definition->customizable);
        self::assertSame('default', $definition->renderMode);
        self::assertSame('first_name', $definition->variables[0]->name);
        self::assertSame('Alice', $definition->variables[0]->example);

        $this->expectApplicationException(static fn () => CustomEmailTemplate::definition((object) [
            'label' => 'Bienvenue',
            'description' => '',
            'render_mode' => 'default',
            'variables' => '{invalid',
        ]));
    }

    private function expectApplicationException(callable $call): void
    {
        try {
            $call();
        } catch (ApplicationException $exception) {
            self::assertNotSame('', $exception->getMessage());
            return;
        }

        self::fail('Une ApplicationException était attendue.');
    }
}
