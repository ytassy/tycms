<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Lib\Forms;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\Application;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Lib\Forms\CallbackFormComputedProvider;
use YTassy\Tycms\Lib\Forms\CallbackFormOptionsProvider;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Lib\Forms\FormComputedContext;
use YTassy\Tycms\Lib\Forms\FormFactory;
use YTassy\Tycms\Lib\Forms\FormFieldType;
use YTassy\Tycms\Lib\Forms\FormOption;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\CoreExts\MainTemplate\Config as MainTemplateConfig;
use YTassy\Tycms\CoreExts\Core\Helper\Forms\FormTypesHelper;
use YTassy\Tycms\CoreExts\MainTemplate\Helpers\CssHelper;
use YTassy\Tycms\CoreExts\MainTemplate\Template;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class FormTest extends FrameworkTestCase
{
    #[Test]
    public function itExportsAJsonSafeDefinitionForRia(): void
    {
        $app = $this->createApplication();
        $form = (new Form($app))
            ->setId('catalog.product.edit')
            ->setLabel('Produit')
            ->addSection('general', 'Informations générales')
            ->addField('general', 'title', 'core.text', [
                'label' => 'Titre',
                'required' => true,
                'validation' => ['maxLength' => 255],
            ])
            ->addField('general', 'status', 'core.radio', [
                'label' => 'Statut',
                'default' => 'draft',
                'options' => [
                    'draft' => 'Brouillon',
                    'published' => 'Publié',
                ],
                'visibleWhen' => [
                    'field' => 'title',
                    'operator' => 'truthy',
                ],
            ])
            ->addField('general', 'params', 'core.json', [
                'label' => 'Paramètres',
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'maxItems' => ['type' => 'integer', 'minimum' => 1],
                    ],
                ],
            ])
            ->addNote('general', 'Information', 'Ce bloc ne produit pas de valeur.');

        $definition = $form->getDefForRia(['title' => 'Clavier', 'ignored' => 'not exported']);

        self::assertSame('catalog.product.edit', $definition['id']);
        self::assertSame([
            'title' => '',
            'status' => 'draft',
            'params' => [],
        ], $form->getDefaultValues());
        self::assertSame([
            'title' => 'Clavier',
            'status' => 'draft',
            'params' => [],
        ], $definition['initialValues']);
        self::assertSame('forms/text', $definition['sections'][0]['fields'][0]['component']);
        self::assertSame([
            ['value' => 'draft', 'label' => 'Brouillon'],
            ['value' => 'published', 'label' => 'Publié'],
        ], $definition['sections'][0]['fields'][1]['options']);
        self::assertSame('forms/json', $definition['sections'][0]['fields'][2]['component']);
        self::assertArrayNotHasKey('name', $definition['sections'][0]['fields'][3]);
        self::assertJson(json_encode($form, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function itCanHideTheTabBarForASingleSection(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.single-section')
            ->setHideSingleSectionTab()
            ->addSection('general', 'Général')
            ->addField('general', 'title', 'core.text');

        self::assertTrue($form->getDefForRia()['hideSingleSectionTab']);
    }

    #[Test]
    public function itCanExcludeAnEditableFieldFromSubmission(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.read-only-value')
            ->addSection('general', 'Général')
            ->addField('general', 'spaceTitle', 'core.text', ['submittable' => false])
            ->addField('general', 'name', 'core.text', ['required' => true]);

        $definition = $form->getDefForRia(['spaceTitle' => 'Site principal']);
        $schema = $form->getSubmissionSchema();

        self::assertFalse($definition['sections'][0]['fields'][0]['submittable']);
        self::assertArrayNotHasKey('spaceTitle', $schema['properties']);
        self::assertArrayHasKey('name', $schema['properties']);
    }

    #[Test]
    public function itResolvesTrustedCallbackOptionsWithoutExposingTheCallback(): void
    {
        $app = $this->createApplication();
        $form = (new Form($app))
            ->setId('catalog.product.edit')
            ->addSection('general', 'Informations générales')
            ->addField('general', 'categoryId', 'core.select', [
                'label' => 'Catégorie',
                'optionsProvider' => new CallbackFormOptionsProvider(
                    static fn ($context): array => [
                        ['value' => $context->getAttribute('spaceId'), 'label' => 'Espace courant'],
                    ],
                ),
            ]);

        $definition = $form->getDefForRia([], ['spaceId' => 42]);
        $field = $definition['sections'][0]['fields'][0];

        self::assertSame([['value' => 42, 'label' => 'Espace courant']], $field['options']);
        self::assertArrayNotHasKey('optionsProvider', $field);
        self::assertJson(json_encode($definition, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function itAcceptsTheSameCanonicalDefinitionAsAnArray(): void
    {
        $form = Form::fromArray($this->createApplication(), [
            'id' => 'catalog.settings',
            'version' => 1,
            'sections' => [[
                'id' => 'general',
                'label' => 'Général',
                'fields' => [
                    [
                        'name' => 'enabled',
                        'type' => 'core.radio',
                        'default' => true,
                        'options' => [
                            ['value' => true, 'label' => 'Oui'],
                            ['value' => false, 'label' => 'Non'],
                        ],
                    ],
                    [
                        'type' => 'core.note',
                        'label' => 'Attention',
                        'description' => 'Une donnée de contexte.',
                    ],
                ],
            ]],
        ]);

        $definition = $form->getDefForRia();

        self::assertSame(['enabled' => true], $form->getDefaultValues());
        self::assertSame(true, $definition['initialValues']['enabled']);
        self::assertSame('forms/note', $definition['sections'][0]['fields'][1]['component']);
    }

    #[Test]
    public function itCreatesDefaultsForUndeclaredCoreFieldValues(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.defaults')
            ->addSection('general', 'Général')
            ->addField('general', 'title', 'core.text')
            ->addField('general', 'description', 'core.textarea')
            ->addField('general', 'template', 'core.code')
            ->addField('general', 'token', 'core.hidden')
            ->addField('general', 'color', 'core.color')
            ->addField('general', 'displayInMenu', 'core.switch')
            ->addField('general', 'icon', 'core.icon-picker')
            ->addField('general', 'quantity', 'core.number')
            ->addField('general', 'categoryId', 'core.select')
            ->addField('general', 'published', 'core.radio')
            ->addField('general', 'params', 'core.json', [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'enabled' => ['type' => 'boolean'],
                        'limit' => ['type' => 'integer', 'minimum' => 2],
                    ],
                    'required' => ['enabled', 'limit'],
                ],
            ]);

        self::assertSame([
            'title' => '',
            'description' => '',
            'template' => '',
            'token' => '',
            'color' => '#000000',
            'displayInMenu' => false,
            'icon' => '',
            'quantity' => null,
            'categoryId' => null,
            'published' => null,
            'params' => ['enabled' => false, 'limit' => 2],
        ], $form->getDefaultValues());
    }

    #[Test]
    public function itExportsAndValidatesSwitchAndIconPickerFields(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.presentation')
            ->addSection('general', 'Général')
            ->addField('general', 'displayInMenu', 'core.switch')
            ->addField('general', 'icon', 'core.icon-picker');

        $fields = $form->getDefForRia()['sections'][0]['fields'];

        self::assertSame('forms/switch', $fields[0]['component']);
        self::assertSame('forms/icon-picker', $fields[1]['component']);
        self::assertTrue($form->validateSubmittedValues((object) [
            'displayInMenu' => true,
            'icon' => 'fa-solid fa-star',
        ])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) [
            'displayInMenu' => 'true',
            'icon' => 12,
        ])->isValid());
    }

    #[Test]
    public function itExportsStdClassValuesAsJsonObjects(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.params')
            ->addSection('general', 'Général')
            ->addField('general', 'params', 'core.json', [
                'default' => new \stdClass(),
                'schema' => ['type' => 'object', 'additionalProperties' => true],
            ]);

        $definition = $form->getDefForRia();

        self::assertInstanceOf(\stdClass::class, $definition['initialValues']['params']);
        self::assertSame('{"params":{}}', json_encode($definition['initialValues'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function itExportsCodeFieldsWithASupportedSyntax(): void
    {
        foreach (['html' => '<h1>Title</h1>', 'mermaid' => "flowchart LR\nA-->B"] as $syntax => $content) {
            $form = (new Form($this->createApplication()))
                ->setId('catalog.template')
                ->addSection('template', 'Template')
                ->addField('template', 'markup', 'core.code', [
                    'label' => 'Markup',
                    'syntax' => $syntax,
                ]);

            $definition = $form->getDefForRia();

            self::assertSame('forms/code', $definition['sections'][0]['fields'][0]['component']);
            self::assertSame($syntax, $definition['sections'][0]['fields'][0]['syntax']);
            self::assertSame('', $definition['initialValues']['markup']);
            self::assertTrue($form->validateSubmittedValues((object) ['markup' => $content])->isValid());
        }
    }

    #[Test]
    public function itRejectsAnUnsupportedCodeFieldSyntax(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.template')
            ->addSection('template', 'Template')
            ->addField('template', 'source', 'core.code', ['syntax' => 'php']);

        $this->expectException(TechException::class);
        $form->getDefForRia();
    }

    #[Test]
    public function itComputesAndPersistsNonSubmittableFields(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.styles')
            ->addSection('styles', 'Styles')
            ->addField('styles', 'source', 'core.code', [
                'default' => 'body { color: red; }',
                'syntax' => 'scss',
            ])
            ->addField('styles', 'compiled', 'core.computed', [
                'label' => 'CSS compilé',
                'syntax' => 'css',
                'schema' => ['type' => 'string'],
                'computedProvider' => new CallbackFormComputedProvider(
                    static fn (FormComputedContext $context): string => sprintf(
                        '/* space %d */%s',
                        $context->getAttribute('space_id'),
                        $context->getValue('source'),
                    ),
                ),
            ]);

        $definition = $form->getDefForRia();
        $computedField = $definition['sections'][0]['fields'][1];

        self::assertSame('forms/computed', $computedField['component']);
        self::assertFalse($computedField['submittable']);
        self::assertArrayNotHasKey('computedProvider', $computedField);
        self::assertArrayNotHasKey('compiled', $form->getDefaultValues());
        self::assertArrayHasKey('compiled', $form->getPersistenceSchema()['properties']);
        self::assertTrue($form->validateSubmittedValues((object) ['source' => 'a { color: blue; }'])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) [
            'source' => 'a { color: blue; }',
            'compiled' => 'forged value',
        ])->isValid());

        $submittedValues = $form->validateSubmittedValues((object) ['source' => 'a { color: blue; }'])->value;
        self::assertIsObject($submittedValues);

        $values = $form->resolveComputedValues(
            $form->getInitialValues(get_object_vars($submittedValues)),
            ['space_id' => 5],
        );

        self::assertSame([
            'source' => 'a { color: blue; }',
            'compiled' => '/* space 5 */a { color: blue; }',
        ], $values);
    }

    #[Test]
    public function itRejectsComputedFieldsWithoutATrustedProvider(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.styles')
            ->addSection('styles', 'Styles')
            ->addField('styles', 'compiled', 'core.computed', [
                'schema' => ['type' => 'string'],
            ]);

        $this->expectException(TechException::class);
        $form->getDefForRia();
    }

    #[Test]
    public function itRejectsAnInvalidComputedProviderValue(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.styles')
            ->addSection('styles', 'Styles')
            ->addField('styles', 'source', 'core.text')
            ->addField('styles', 'compiled', 'core.computed', [
                'schema' => ['type' => 'string'],
                'computedProvider' => new CallbackFormComputedProvider(
                    static fn (FormComputedContext $context): int => 42,
                ),
            ]);

        $this->expectException(TechException::class);
        $form->resolveComputedValues(['source' => 'body {}']);
    }

    #[Test]
    public function mainTemplateCompilesScssIntoItsComputedCssField(): void
    {
        $form = $this->createMainTemplateForm($this->createApplication());

        $values = $form->resolveComputedValues(
            $form->getInitialValues(['theme_custom_scss' => 'body { color: red; }']),
            ['space_id' => 1, 'extension' => 'tpl_main'],
        );

        self::assertSame('body { color: red; }', $values['theme_custom_scss']);
        self::assertSame('body{color:red}', $values['theme_custom_compiled_css']);
    }

    #[Test]
    public function mainTemplateExposesItsImagesAsMediaFileQuestions(): void
    {
        $form = $this->createMainTemplateForm($this->createApplication());
        $fields = [];
        foreach ($form->getDefForRia()['sections'] as $section) {
            $fields += array_column($section['fields'], null, 'name');
        }

        foreach (['logo', 'logo_email', 'favicon'] as $name) {
            self::assertSame('forms/media-file', $fields[$name]['component']);
            self::assertSame('image', $fields[$name]['mediaType']);
        }
        self::assertSame('any', $fields['logo']['manualInputMode']);
        self::assertSame('media', $fields['logo_email']['manualInputMode'] ?? 'media');
        self::assertSame('', $form->getDefaultValues()['logo_email']);
        self::assertFalse($form->validateSubmittedValues((object) ['email_footer_url' => 'javascript:alert(1)'])->isValid());
        self::assertTrue($form->validateSubmittedValues((object) ['email_footer_url' => 'https://example.test/'])->isValid());
    }

    #[Test]
    public function fontQuestionProvidesChoicesAndPreviewMetadataWithoutTemplateConfiguration(): void
    {
        $app = $this->createApplication();
        (new FormTypesHelper($app))->registerFontFamilyFieldType();
        $form = (new Form($app))->setId('test.font')
            ->addSection('general', 'Police')
            ->addField('general', 'font', 'core.font_family');
        $field = $form->getDefForRia()['sections'][0]['fields'][0];

        self::assertSame('forms/font-family', $field['component']);
        self::assertSame('arial', $form->getDefaultValues()['font']);
        self::assertSame(['arial', 'verdana', 'georgia', 'times_new_roman', 'courier_new'], array_column($field['options'], 'value'));
        self::assertSame('Verdana', $field['options'][1]['data']['fontName']);
        self::assertSame('Verdana, Geneva, sans-serif', $field['options'][1]['data']['fontFamily']);
        self::assertNull($field['options'][1]['data']['fontCssUrl']);
        self::assertTrue($form->validateSubmittedValues((object) ['font' => 'arial'])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) ['font' => 'google_uninstalled'])->isValid());
    }

    #[Test]
    public function mainTemplateUsesTheReusableFontQuestionForBothTextAndHeadings(): void
    {
        $form = $this->createMainTemplateForm($this->createApplication());
        $fields = [];
        foreach ($form->getDefinition()['sections'] as $section) {
            $fields += array_column($section['fields'], null, 'name');
        }
        foreach (['theme_font_family', 'theme_heading_font_family'] as $name) {
            self::assertSame('core.font_family', $fields[$name]['type']);
            self::assertArrayNotHasKey('optionsProvider', $fields[$name]);
            self::assertArrayNotHasKey('ui', $fields[$name]);
        }
    }

    #[Test]
    public function optionMetadataCannotContainObjectsOrNestedPayloads(): void
    {
        $this->expectException(TechException::class);
        FormOption::normaliseList([['value' => 'font', 'label' => 'Police', 'data' => ['unsafe' => (object) ['url' => 'https://example.test']]]]);
    }

    #[Test]
    public function mainTemplateValidatesThemeSettings(): void
    {
        $form = $this->createMainTemplateForm($this->createApplication());

        self::assertTrue($form->validateSubmittedValues((object) [
            'theme_primary' => '#123456',
            'theme_body_bg' => '#FAFAFA',
            'theme_components_bg' => '#FFFFFF',
            'theme_header_height' => 120,
            'theme_logo_height' => 60,
            'theme_header_bg' => '#123456',
            'theme_header_color' => '#FFFFFF',
            'theme_menu_hover_bg' => '#ABCDEF',
            'theme_menu_active_bg' => '#123456',
            'theme_menu_hover_color' => '#654321',
            'theme_menu_active_color' => '#FEDCBA',
            'theme_subheader_bg' => '#C0FFEE',
            'theme_subheader_color' => '#102030',
            'theme_footer_bg' => '#654321',
            'theme_footer_color' => '#FEDCBA',
            'theme_font_family' => 'arial',
            'theme_font_size' => 18,
            'theme_line_height' => 1.6,
            'theme_border_width' => 2,
            'theme_border_radius' => 12,
            'theme_box_shadow' => 'strong',
        ])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) [
            'theme_font_family' => 'https://example.test/font.css',
        ])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) [
            'theme_font_size' => 25,
        ])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) [
            'theme_primary' => '#12345678',
        ])->isValid());
        foreach ([40, 200] as $height) {
            self::assertTrue($form->validateSubmittedValues((object) ['theme_header_height' => $height])->isValid());
        }
        foreach ([39, 201, '80px;display:none'] as $height) {
            self::assertFalse($form->validateSubmittedValues((object) ['theme_header_height' => $height])->isValid());
        }
        foreach ([20, 200] as $height) {
            self::assertTrue($form->validateSubmittedValues((object) ['theme_logo_height' => $height])->isValid());
        }
        foreach ([19, 201, '40px;display:none'] as $height) {
            self::assertFalse($form->validateSubmittedValues((object) ['theme_logo_height' => $height])->isValid());
        }
        foreach ([
            'theme_components_bg', 'theme_header_bg', 'theme_header_color',
            'theme_menu_hover_bg', 'theme_menu_active_bg', 'theme_menu_hover_color', 'theme_menu_active_color',
            'theme_subheader_bg', 'theme_subheader_color', 'theme_footer_bg', 'theme_footer_color',
        ] as $field) {
            foreach (['#12345678', '#FFFFFF;display:none'] as $unsafeColor) {
                self::assertFalse($form->validateSubmittedValues((object) [
                    $field => $unsafeColor,
                ])->isValid());
            }
        }
    }

    #[Test]
    public function mainTemplateRestrictsAdminUiVariant(): void
    {
        $form = $this->createMainTemplateForm($this->createApplication());

        // La variante moderne est le défaut ; les anciennes configurations
        // n'ont donc pas besoin d'être réenregistrées pour en bénéficier.
        $values = $form->getInitialValues([]);
        self::assertSame('modern', $values['theme_admin_ui']);

        foreach (['modern', 'classic'] as $variant) {
            self::assertTrue($form->validateSubmittedValues((object) [
                'theme_admin_ui' => $variant,
            ])->isValid());
        }

        // L'attribut HTML ne doit jamais recevoir une valeur arbitraire.
        self::assertFalse($form->validateSubmittedValues((object) [
            'theme_admin_ui' => 'autre-valeur',
        ])->isValid());
    }

    #[Test]
    public function mainTemplateGeneratesBootstrapThemeCss(): void
    {
        $css = (new CssHelper($this->createApplication()))->renderThemeCss((object) [
            'theme_primary' => '#123456',
            'theme_body_bg' => '#FAFAFA',
            'theme_components_bg' => '#F0E0D0',
            'theme_header_height' => 120,
            'theme_logo_height' => 60,
            'theme_header_bg' => '#112233',
            'theme_header_color' => '#FFFFFF',
            'theme_menu_hover_bg' => '#ABCDEF',
            'theme_menu_active_bg' => '#123456',
            'theme_menu_hover_color' => '#654321',
            'theme_menu_active_color' => '#FEDCBA',
            'theme_subheader_bg' => '#223344',
            'theme_subheader_color' => '#AABBCC',
            'theme_footer_bg' => '#334455',
            'theme_footer_color' => '#CCDDEE',
            'theme_body_color' => '#102030',
            'theme_font_family' => 'arial',
            'theme_font_size' => 18,
            'theme_border_radius' => 12,
            'theme_box_shadow' => 'none',
        ]);

        self::assertStringContainsString('--bs-primary: #123456', $css);
        self::assertStringContainsString('--bs-primary-rgb: 18, 52, 86', $css);
        self::assertStringContainsString('--bs-body-bg: #F0E0D0', $css);
        self::assertStringContainsString('--bs-body-bg-rgb: 240, 224, 208', $css);
        self::assertStringContainsString('--theme-page-bg: #FAFAFA', $css);
        self::assertStringContainsString('background-color: var(--theme-page-bg)', $css);
        self::assertStringContainsString(".main-template-header {\n    --header-height: 120px;", $css);
        self::assertStringContainsString(".main-template-header .navbar-brand > img {\n    height: 60px;", $css);
        self::assertStringContainsString('--theme-header-bg: #112233', $css);
        self::assertStringContainsString('--theme-header-color: #FFFFFF', $css);
        self::assertStringContainsString('--theme-menu-hover-bg: #ABCDEF', $css);
        self::assertStringContainsString('--theme-menu-active-bg: #123456', $css);
        self::assertStringContainsString('--theme-menu-hover-color: #654321', $css);
        self::assertStringContainsString('--theme-menu-active-color: #FEDCBA', $css);
        self::assertStringContainsString('--theme-subheader-bg: #223344', $css);
        self::assertStringContainsString('--theme-subheader-color: #AABBCC', $css);
        self::assertStringContainsString('--theme-footer-bg: #334455', $css);
        self::assertStringContainsString('--theme-footer-color: #CCDDEE', $css);
        self::assertStringContainsString('--bs-navbar-color: rgba(255, 255, 255, 0.65)', $css);
        self::assertStringContainsString("stroke='%23FFFFFF'", $css);
        self::assertStringContainsString(".main-template-subheader {\n    background-color: var(--theme-subheader-bg);", $css);
        self::assertStringContainsString('--bs-heading-color: var(--theme-subheader-color)', $css);
        self::assertStringContainsString(".main-template-footer {\n    background-color: var(--theme-footer-bg);", $css);
        self::assertStringContainsString('--bs-link-color-rgb: 204, 221, 238', $css);
        self::assertStringContainsString('--bs-body-font-family: Arial, Helvetica, sans-serif', $css);
        self::assertStringContainsString("html {\n    font-size: 18px;", $css);
        self::assertStringContainsString('--bs-border-radius: 12px', $css);
        self::assertStringContainsString('--bs-box-shadow: none', $css);
        self::assertStringContainsString('.btn-primary {', $css);
        self::assertStringContainsString('--bs-btn-bg: #123456', $css);
        self::assertStringContainsString('.form-control:focus,', $css);
    }

    #[Test]
    public function mainTemplatePreservesLegacyBackgroundsWhenOpeningAndSavingConfiguration(): void
    {
        $app = $this->createApplication();
        $form = $this->createMainTemplateForm($app);
        $template = new Template($app);
        $helper = new CssHelper($app);
        $legacyParams = [
            'theme_body_bg' => '#AABBCC', 'theme_surface_bg' => '#DDEEFF',
            'theme_body_color' => '#112233', 'theme_heading_color' => '#445566',
        ];
        $legacyCss = $helper->renderThemeCss((object) $legacyParams);

        // L'éditeur doit copier l'ancien fond avant que le défaut du nouveau champ s'applique.
        $values = $form->getInitialValues($template->prepareConfigForEditor(7, $legacyParams));
        self::assertSame('#AABBCC', $values['theme_components_bg']);
        self::assertSame('#DDEEFF', $values['theme_header_bg']);
        self::assertSame('#112233', $values['theme_header_color']);
        self::assertSame('#DDEEFF', $values['theme_menu_active_bg']);
        self::assertSame('#112233', $values['theme_menu_hover_color']);
        self::assertSame('#112233', $values['theme_menu_active_color']);
        self::assertSame('#DDEEFF', $values['theme_subheader_bg']);
        self::assertSame('#445566', $values['theme_subheader_color']);
        self::assertSame('#DDEEFF', $values['theme_footer_bg']);
        $validation = $form->validateSubmittedValues((object) $values);
        self::assertTrue($validation->isValid());
        self::assertSame($legacyCss, $helper->renderThemeCss($validation->value));

        // Après séparation, modifier le body ne recopie plus sa couleur sur les composants.
        $values['theme_body_bg'] = '#123456';
        $values['theme_surface_bg'] = '#FFFFFF';
        $values['theme_body_color'] = '#000000';
        $values['theme_heading_color'] = '#000000';
        $reopened = $template->prepareConfigForEditor(7, $values);
        self::assertSame('#AABBCC', $reopened['theme_components_bg']);
        $css = $helper->renderThemeCss((object) $reopened);
        self::assertStringContainsString('--theme-page-bg: #123456', $css);
        self::assertStringContainsString('--bs-body-bg: #AABBCC', $css);
        self::assertStringContainsString('--theme-header-bg: #DDEEFF', $css);
        self::assertStringContainsString('--theme-header-color: #112233', $css);
        self::assertStringContainsString('--theme-menu-hover-bg: #FFFFFF', $css);
        self::assertStringContainsString('--theme-menu-active-bg: #DDEEFF', $css);
        self::assertStringContainsString('--theme-menu-hover-color: #112233', $css);
        self::assertStringContainsString('--theme-menu-active-color: #112233', $css);
        self::assertStringContainsString('--theme-subheader-bg: #DDEEFF', $css);
        self::assertStringContainsString('--theme-subheader-color: #445566', $css);
        self::assertStringContainsString('--theme-footer-bg: #DDEEFF', $css);

        // Le rendu ne doit jamais insérer une valeur CSS non validée, même déjà stockée.
        foreach (['theme_components_bg', 'theme_header_bg', 'theme_header_color',
            'theme_menu_hover_bg', 'theme_menu_active_bg', 'theme_menu_hover_color', 'theme_menu_active_color',
            'theme_subheader_bg', 'theme_subheader_color', 'theme_footer_bg', 'theme_footer_color'] as $field) {
            $reopened[$field] = '#FFFFFF;display:none';
        }
        $css = $helper->renderThemeCss((object) $reopened);
        self::assertStringNotContainsString('display:none', $css);
        self::assertStringContainsString('--bs-body-bg: #123456', $css);
    }

    #[Test]
    public function mainTemplateLimitsCustomScssToOneHundredKib(): void
    {
        $form = $this->createMainTemplateForm($this->createApplication());

        self::assertTrue($form->validateSubmittedValues((object) [
            'theme_custom_scss' => str_repeat('é', 51_200),
        ])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) [
            'theme_custom_scss' => str_repeat('é', 51_201),
        ])->isValid());
    }

    #[Test]
    public function mainTemplateHidesScssCompilerDetailsOutsideDevelopmentAndDebug(): void
    {
        $app = $this->createApplication();
        $app->getConf()->dev = false;
        $app->getConf()->debug = false;
        $form = $this->createMainTemplateForm($app);

        try {
            $form->resolveComputedValues(
                $form->getInitialValues(['theme_custom_scss' => 'body {']),
                ['space_id' => 1, 'extension' => 'tpl_main'],
            );
            self::fail('Expected an SCSS compilation error.');
        } catch (ExceptionResponse $exception) {
            self::assertSame(
                'Le SCSS personnalisé est invalide.',
                $exception->getResponse()->getMessage(),
            );
        }
    }

    #[Test]
    public function mainTemplateIncludesScssCompilerDetailsInDevelopmentOrDebug(): void
    {
        foreach ([
            ['dev' => true, 'debug' => false],
            ['dev' => false, 'debug' => true],
        ] as $flags) {
            $app = $this->createApplication();
            $app->getConf()->dev = $flags['dev'];
            $app->getConf()->debug = $flags['debug'];
            $form = $this->createMainTemplateForm($app);

            try {
                $form->resolveComputedValues(
                    $form->getInitialValues(['theme_custom_scss' => 'body {']),
                    ['space_id' => 1, 'extension' => 'tpl_main'],
                );
                self::fail('Expected an SCSS compilation error.');
            } catch (ExceptionResponse $exception) {
                self::assertStringStartsWith(
                    'Le SCSS personnalisé est invalide. ',
                    $exception->getResponse()->getMessage(),
                );
            }
        }
    }

    #[Test]
    public function itValidatesSixOrEightDigitHexColors(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.colors')
            ->addSection('general', 'General')
            ->addField('general', 'color', 'core.color', [
                'palette' => ['#112233', '#AABBCC80'],
            ]);

        $definition = $form->getDefForRia();
        self::assertSame('forms/color', $definition['sections'][0]['fields'][0]['component']);
        self::assertSame('#000000', $definition['initialValues']['color']);
        self::assertTrue($form->validateSubmittedValues((object) ['color' => '#AaBbCc'])->isValid());
        self::assertTrue($form->validateSubmittedValues((object) ['color' => '#AABBCC80'])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) ['color' => '#ABC'])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) ['color' => '#AABBCCGG'])->isValid());

        $opaqueForm = (new Form($this->createApplication()))
            ->setId('catalog.opaque-colors')
            ->addSection('general', 'General')
            ->addField('general', 'color', 'core.color', ['allowAlpha' => false]);

        self::assertTrue($opaqueForm->validateSubmittedValues((object) ['color' => '#AABBCC'])->isValid());
        self::assertFalse($opaqueForm->validateSubmittedValues((object) ['color' => '#AABBCC80'])->isValid());
    }

    #[Test]
    public function itValidatesASubmittedValueAgainstItsTrustedDefinition(): void
    {
        $form = (new Form($this->createApplication()))
            ->setId('catalog.settings')
            ->addSection('general', 'GÃ©nÃ©ral')
            ->addField('general', 'title', 'core.text', [
                'required' => true,
                'validation' => ['maxLength' => 10],
            ])
            ->addField('general', 'enabled', 'core.radio', [
                'required' => true,
                'options' => [
                    ['value' => true, 'label' => 'Oui'],
                    ['value' => false, 'label' => 'Non'],
                ],
            ])
            ->addField('general', 'params', 'core.json', [
                'schema' => [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1],
                    ],
                    'required' => ['limit'],
                ],
            ]);

        self::assertTrue($form->validateSubmittedValues((object) [
            'title' => 'Catalogue',
            'enabled' => true,
            'params' => (object) ['limit' => 4],
        ])->isValid());

        $invalid = $form->validateSubmittedValues((object) [
            'title' => 'Titre beaucoup trop long',
            'enabled' => 'true',
            'params' => (object) ['limit' => 0],
            'unexpected' => true,
        ]);

        self::assertFalse($invalid->isValid());
        self::assertNotEmpty($invalid->getErrors());
    }

    #[Test]
    public function extensionsCanRegisterAQualifiedCustomFieldType(): void
    {
        $app = $this->createApplication();
        /** @var FormFactory $factory */
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        $factory->registerType(new FormFieldType(
            'catalog.product-picker',
            'catalog/forms/product-picker',
            acceptsOptions: true,
        ));

        $form = (new Form($app))
            ->setId('catalog.order.edit')
            ->addSection('general', 'Informations générales')
            ->addField('general', 'productId', 'catalog.product-picker', [
                'options' => [['value' => 4, 'label' => 'Produit de démonstration']],
            ]);

        $field = $form->getDefForRia()['sections'][0]['fields'][0];

        self::assertSame('catalog/forms/product-picker', $field['component']);
        self::assertSame([['value' => 4, 'label' => 'Produit de démonstration']], $field['options']);
    }

    #[Test]
    public function itValidatesOptionsOfACustomFieldType(): void
    {
        $app = $this->createApplication();
        /** @var FormFactory $factory */
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        $factory->registerType(new FormFieldType(
            'catalog.product-picker',
            'catalog/forms/product-picker',
            acceptsOptions: true,
        ));

        $form = (new Form($app))
            ->setId('catalog.order.edit')
            ->addSection('general', 'Informations générales')
            ->addField('general', 'productId', 'catalog.product-picker', [
                'required' => true,
                'options' => [['value' => 4, 'label' => 'Produit de démonstration']],
            ]);

        self::assertTrue($form->validateSubmittedValues((object) ['productId' => 4])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) ['productId' => 5])->isValid());
    }

    #[Test]
    public function customFieldTypesCanProvideDefaultStaticOptions(): void
    {
        $app = $this->createApplication();
        /** @var FormFactory $factory */
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        $factory->registerType(new FormFieldType(
            'catalog.status-select',
            'forms/select',
            acceptsOptions: true,
            defaultOptions: [
                'draft' => 'Brouillon',
                'published' => 'Publié',
            ],
        ));

        $form = (new Form($app))
            ->setId('catalog.product.edit')
            ->addSection('general', 'Général')
            ->addField('general', 'status', 'catalog.status-select', ['required' => true]);

        $field = $form->getDefForRia()['sections'][0]['fields'][0];

        self::assertSame([
            ['value' => 'draft', 'label' => 'Brouillon'],
            ['value' => 'published', 'label' => 'Publié'],
        ], $field['options']);
        self::assertTrue($form->validateSubmittedValues((object) ['status' => 'draft'])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) ['status' => 'archived'])->isValid());
    }

    #[Test]
    public function fieldOptionsOverrideTheTypeDefaultOptionsProvider(): void
    {
        $app = $this->createApplication();
        /** @var FormFactory $factory */
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        $defaultProviderCalled = false;
        $factory->registerType(new FormFieldType(
            'catalog.status-select',
            'forms/select',
            acceptsOptions: true,
            defaultOptionsProvider: new CallbackFormOptionsProvider(
                static function () use (&$defaultProviderCalled): array {
                    $defaultProviderCalled = true;

                    return ['draft' => 'Brouillon'];
                },
            ),
        ));

        $form = (new Form($app))
            ->setId('catalog.product.edit')
            ->addSection('general', 'Général')
            ->addField('general', 'status', 'catalog.status-select', [
                'options' => ['published' => 'Publié'],
            ]);

        $field = $form->getDefForRia()['sections'][0]['fields'][0];

        self::assertSame([['value' => 'published', 'label' => 'Publié']], $field['options']);
        self::assertFalse($defaultProviderCalled);
    }

    #[Test]
    public function customFieldTypesCanProvideDefaultDynamicOptions(): void
    {
        $app = $this->createApplication();
        /** @var FormFactory $factory */
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        $factory->registerType(new FormFieldType(
            'catalog.space-select',
            'forms/select',
            acceptsOptions: true,
            defaultOptionsProvider: new CallbackFormOptionsProvider(
                static fn ($context): array => [
                    ['value' => $context->getAttribute('spaceId'), 'label' => 'Espace courant'],
                ],
            ),
        ));

        $form = (new Form($app))
            ->setId('catalog.product.edit')
            ->addSection('general', 'Général')
            ->addField('general', 'spaceId', 'catalog.space-select', ['required' => true]);

        $field = $form->getDefForRia([], ['spaceId' => 42])['sections'][0]['fields'][0];

        self::assertSame([['value' => 42, 'label' => 'Espace courant']], $field['options']);
        self::assertTrue($form->validateSubmittedValues((object) ['spaceId' => 42], ['spaceId' => 42])->isValid());
        self::assertFalse($form->validateSubmittedValues((object) ['spaceId' => 7], ['spaceId' => 42])->isValid());
    }

    #[Test]
    public function aTypeCannotCombineDefaultOptionsSources(): void
    {
        $this->expectException(TechException::class);

        new FormFieldType(
            'catalog.status-select',
            'forms/select',
            acceptsOptions: true,
            defaultOptions: ['draft' => 'Brouillon'],
            defaultOptionsProvider: new CallbackFormOptionsProvider(static fn (): array => []),
        );
    }

    #[Test]
    public function itRejectsDuplicateTypeRegistrations(): void
    {
        $factory = new FormFactory($this->createApplication());

        $this->expectException(TechException::class);
        $factory->registerType(new FormFieldType('core.text', 'forms/other-text'));
    }

    #[Test]
    public function itRequiresAQualifiedCustomTypeId(): void
    {
        $factory = new FormFactory($this->createApplication());

        $this->expectException(TechException::class);
        $factory->registerType(new FormFieldType('productPicker', 'catalog/forms/product-picker'));
    }

    private function createMainTemplateForm(Application $app): Form
    {
        /** @var FormFactory $factory */
        $factory = $app->getOrAddSharedExtensionsClass(FormFactory::class);
        foreach ([
            'catalog.core_menu' => [1 => 'Menu de test'],
            'catalog.core_page_item' => [3 => 'Page de test'],
        ] as $id => $options) {
            $factory->registerType(new FormFieldType(
                $id,
                'forms/select',
                acceptsOptions: true,
                valueSchema: ['type' => ['integer', 'null']],
                defaultOptions: $options,
            ));
        }

        $factory->registerType(new FormFieldType(
            'core.media_file',
            'forms/media-file',
            implicitDefault: '',
            valueSchema: ['type' => 'string', 'maxLength' => 2048],
        ));

        (new FormTypesHelper($app))->registerFontFamilyFieldType();

        return (new MainTemplateConfig($app))->getConfigForm();
    }
}
