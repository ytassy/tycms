<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Page;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageLocalizedText;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tools\HtmlTools;

/** Contrat commun aux composants Core et aux composants fournis par les extensions. */
abstract class PageComponent extends BaseHelper
{
    abstract public static function getId(): string;
    abstract public static function getLabel(): string;

    public static function getCategory(): string
    {
        return 'Extensions';
    }

    public static function getDefaultData(): array
    {
        return [];
    }

    public static function getPropsSchema(): ?array
    {
        return null;
    }

    public static function getEditorFields(): array
    {
        return [];
    }

    public static function getChildZones(): array
    {
        return [];
    }

    public static function getExclusiveReferenceFields(): array
    {
        return [];
    }

    public static function getNodeReferenceFields(): array
    {
        return [];
    }

    public static function getFormVueComponentName(): ?string
    {
        return null;
    }

    public static function getCanvasVueComponentName(): ?string
    {
        return null;
    }

    public static function getPublicVueComponentName(): ?string
    {
        return null;
    }

    /**
     * Composant public propre à un nœud.
     *
     * Permet à un composant à plusieurs modes de ne monter Vue que pour les
     * nœuds qui en ont besoin, sans transformer tous ses rendus en racines Vue.
     */
    public static function getPublicVueComponentNameFor(object $node): ?string
    {
        return static::getPublicVueComponentName();
    }

    public static function getCanvasMode(): PageCanvasMode
    {
        return static::getCanvasVueComponentName() === null ? PageCanvasMode::API : PageCanvasMode::VUE;
    }

    public function getGeneralForm(PageEditContext $context): ?Form
    {
        return null;
    }

    public function getEditContext(PageEditContext $context): array
    {
        return [];
    }

    /** Normalisation sans persistance ; le moteur conserve les options communes à part. */
    public function normalizeData(object $data, PageNormalizationContext $context): object
    {
        return clone $data;
    }

    public function validateData(object $data, PageNormalizationContext $context): void
    {
    }

    public function validateNode(object $node, PageNormalizationContext $context): void
    {
    }

    /** Les zones ont toujours la même structure, quel que soit leur propriétaire. */
    public function normalizeZone(string $name, object $zone, PageNormalizationContext $context): object
    {
        return $zone;
    }

    /** Données publiques éphémères ; elles ne sont jamais persistées dans le document. */
    public function getPublicProps(object $node, PageRenderContext $context): object
    {
        return clone $node->props;
    }

    abstract public function render(object $node, PageRenderContext $context): string;

    protected function normalizeText(mixed $value, int $maximum = 500, bool $html = false): string|object
    {
        return PageLocalizedText::normalize($value, $maximum, $html);
    }

    protected function escape(string $value): string
    {
        return HtmlTools::escape($value);
    }

    protected function assert(bool $valid, string $message): void
    {
        if (!$valid) ExceptionResponse::throwWarning($message);
    }
}
