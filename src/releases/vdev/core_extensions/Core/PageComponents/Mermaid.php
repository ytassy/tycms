<?php

namespace YTassy\Tycms\CoreExts\Core\PageComponents;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;
use InvalidArgumentException;
use YTassy\Tycms\CoreExts\Core\Helper\ShortCode\DiagramShortCode;
use YTassy\Tycms\Response\ExceptionResponse;

/** Composant Mermaid fourni par Core. */
final class Mermaid extends CorePageComponent
{
    public static function getId(): string
    {
        return 'core.mermaid';
    }

    public static function getLabel(): string
    {
        return 'Mermaid';
    }

    public static function getCategory(): string
    {
        return 'Contenu';
    }

    public static function getPropsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'diagram' => ['type' => 'string'],
                'options' => ['type' => 'object', 'additionalProperties' => true],
            ],
            'additionalProperties' => false,
        ];
    }

    public static function getDefaultData(): array
    {
        return ['diagram' => "flowchart LR\n    A[Début] --> B[Fin]", 'options' => (object) []];
    }

    public static function getFormVueComponentName(): ?string
    {
        return 'core/mermaid';
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return 'core/mermaid';
    }

    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        self::validate($data);
        return clone $data;
    }

    public static function validate(object $props): void
    {
        if (!is_string($props->diagram ?? '') || strlen($props->diagram ?? '') > 30000) {
            ExceptionResponse::throwWarning('Le code Mermaid doit être un texte de 30 000 octets maximum.');
        }
        if (isset($props->options) && !$props->options instanceof \stdClass) {
            ExceptionResponse::throwWarning('Options Mermaid invalides.');
        }
        try {
            $overrides = $props->options ?? (object) [];
            $resolved = DiagramShortCode::resolveOptions($overrides);
            foreach (get_object_vars($overrides) as $name => $value) {
                if ((string) $value !== (string) $resolved[$name]) throw new InvalidArgumentException('Valeur non canonique.');
            }
        }
        catch (InvalidArgumentException) { ExceptionResponse::throwWarning('Options Mermaid invalides.'); }
    }

    public function render(object $item, PageRenderContext $context): string
    {
        $props = $item->props;
        $spaceId = $context->spaceId;
        self::validate($props);

        // Lire les défauts au rendu : ne jamais figer l'héritage dans le document enregistré.
        return DiagramShortCode::renderSource($props->diagram ?? '', $props->options ?? (object) [],
            $this->coreShortCodeHelper->configuration($spaceId));
    }
}
