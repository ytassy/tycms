<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Lib\ObjectValidator;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidationError;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidator;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidatorFormat;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidatorFormatContext;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidatorFormatResult;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidatorNormalizer;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidatorNormalizerContext;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ObjectValidatorTest extends FrameworkTestCase
{
    private ObjectValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new ObjectValidator($this->createApplication());
    }

    #[Test]
    public function itValidatesDecodedJsonAndRejectsUndeclaredProperties(): void
    {
        $schema = [
            'schemas_ref' => [
                'address' => [
                    'type' => 'object',
                    'properties' => [
                        'city' => ['type' => 'string', 'minLength' => 2],
                    ],
                    'required' => ['city'],
                ],
            ],
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 2],
                    'address' => ['$ref' => 'address'],
                    'tags' => [
                        'type' => 'array',
                        'items' => ['type' => 'integer'],
                        'minItems' => 1,
                    ],
                ],
                'required' => ['name', 'address', 'tags'],
            ],
        ];

        $validPayload = json_decode(
            '{"name":"Ada","address":{"city":"Paris"},"tags":[1,2]}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        );
        $invalidPayload = json_decode(
            '{"name":"A","address":{"city":"P","extra":true},"tags":[1,"2"],"injected":true}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertTrue($this->validator->validate($validPayload, $schema)->isValid());

        $errors = $this->validator->validate($invalidPayload, $schema)->getErrors();
        self::assertSame([
            ['/name', 'minLength'],
            ['/address/city', 'minLength'],
            ['/address/extra', 'additionalProperties'],
            ['/tags/1', 'type'],
            ['/injected', 'additionalProperties'],
        ], array_map(
            static fn (ObjectValidationError $error): array => [$error->path, $error->keyword],
            $errors,
        ));
    }

    #[Test]
    public function itSanitizesHtmlBeforeValidatingAndDoesNotMutateThePayload(): void
    {
        $html = '<p onclick="alert(1)">Bonjour<script>alert(1)</script></p>';
        $payload = (object) ['content' => $html];

        $result = $this->validator->normalizeAndValidate($payload, [
            'type' => 'object',
            'properties' => [
                'content' => [
                    'type' => 'string',
                    'normalizer' => 'html-sanitizer',
                    'maxLength' => 1_000,
                ],
            ],
            'required' => ['content'],
        ]);

        self::assertTrue($result->isValid());
        self::assertNotSame($payload, $result->value);
        self::assertSame($html, $payload->content);
        self::assertSame(HtmlSanitizer::sanitize($html), $result->value->content);
    }

    #[Test]
    public function itValidatesTheNormalizedValue(): void
    {
        $this->validator->registerNormalizer('tests.trim', new class implements ObjectValidatorNormalizer {
            public function normalize(mixed $value, ObjectValidatorNormalizerContext $context): mixed
            {
                return is_string($value) ? trim($value) : $value;
            }
        });

        $result = $this->validator->normalizeAndValidate((object) ['label' => ' A '], [
            'type' => 'object',
            'properties' => [
                'label' => [
                    'type' => 'string',
                    'normalizer' => 'tests.trim',
                    'minLength' => 2,
                ],
            ],
            'required' => ['label'],
        ]);

        self::assertFalse($result->isValid());
        self::assertSame('A', $result->value->label);
        self::assertSame('/label', $result->getFirstError()?->path);
        self::assertSame('minLength', $result->getFirstError()?->keyword);
    }

    #[Test]
    public function itSupportsCustomFormatsWithAUserFacingMessage(): void
    {
        $this->validator->registerFormat('tests.allowed-value', new class implements ObjectValidatorFormat {
            public function validate(
                mixed $value,
                ObjectValidatorFormatContext $context,
            ): bool|ObjectValidatorFormatResult {
                return $value === 'allowed'
                    ? true
                    : ObjectValidatorFormatResult::invalid('Valeur non disponible.');
            }
        });

        $result = $this->validator->validate('blocked', [
            'type' => 'string',
            'format' => 'tests.allowed-value',
        ]);

        self::assertFalse($result->isValid());
        self::assertSame('format', $result->getFirstError()?->keyword);
        self::assertSame('Valeur non disponible.', $result->getFirstError()?->message);
    }

    #[Test]
    public function itValidatesTheMenuItemCreateSchemaLoadedByTheControllerParameter(): void
    {
        $schemaFile = APP_ROOT . '/releases/vdev/core_extensions/Core/Controllers/Admin/Schemas/MenuItemCreate.php';
        $controllerParam = new ControllerParam1(schemaFile: $schemaFile);
        $schema = $controllerParam->getSchema();

        self::assertIsArray($schema);
        self::assertTrue($this->validator->validate(json_decode(
            '{"title":"Accueil","extension":"plg_core","type":"page","params":{"pageId":12},"access_role_id":0}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        ), $schema)->isValid());

        $result = $this->validator->validate(json_decode(
            '{"title":"Accueil","extension":"plg_core","type":"page","injected":true}',
            false,
            512,
            JSON_THROW_ON_ERROR,
        ), $schema);

        self::assertFalse($result->isValid());
        self::assertSame('/injected', $result->getFirstError()?->path);
        self::assertSame('additionalProperties', $result->getFirstError()?->keyword);
    }

    #[Test]
    public function itValidatesMenuCreateAndUpdateSchemas(): void
    {
        $createSchema = (new ControllerParam1(
            schemaFile: APP_ROOT . '/releases/vdev/core_extensions/Core/Controllers/Admin/Schemas/MenuCreate.php',
        ))->getSchema();
        $updateSchema = (new ControllerParam1(
            schemaFile: APP_ROOT . '/releases/vdev/core_extensions/Core/Controllers/Admin/Schemas/MenuUpdate.php',
        ))->getSchema();

        self::assertTrue($this->validator->validate((object) [
            'space_id' => 1,
            'name' => 'Pied de page',
            'alias' => 'footer',
            'description' => 'Liens de bas de page.',
        ], $createSchema)->isValid());

        self::assertTrue($this->validator->validate((object) [
            'name' => 'Pied de page',
            'alias' => 'footer',
            'description' => '',
        ], $updateSchema)->isValid());

        self::assertFalse($this->validator->validate((object) [
            'name' => 'Pied de page',
            'space_id' => 1,
        ], $updateSchema)->isValid());
    }
}
