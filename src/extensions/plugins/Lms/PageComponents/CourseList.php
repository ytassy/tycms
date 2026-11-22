<?php

declare(strict_types=1);

namespace YTassy\Tycms\Plugins\Lms\PageComponents;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageComponent;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageRenderContext;

/** Premier composant LMS ; le canevas valide l’intégration Vue sans données de cours. */
final class CourseList extends PageComponent
{
    public static function getId(): string { return 'lms.course-list'; }

    public static function getLabel(): string { return 'Liste des cours'; }

    public static function getCategory(): string { return 'LMS'; }

    public static function getCanvasVueComponentName(): ?string { return 'lms/course-list'; }

    public static function getPropsSchema(): array
    {
        return ['type' => 'object', 'properties' => [], 'additionalProperties' => false];
    }

    public function render(object $node, PageRenderContext $context): string
    {
        // Aucun cours ni texte de démonstration n’est publié avant la définition du métier.
        return '';
    }
}
