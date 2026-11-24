<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Annotation;

use Attribute;

/**
 * Marque une classe de WorkFlow comme exposant des actions d'automatisation.
 *
 * La valeur `namespace` préfixe l'identifiant de chaque action déclarée dans
 * la classe ; par exemple `core` associé à la méthode `getUser` produit
 * l'identifiant `core::getUser`.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class ExposeAutomationActions
{
    public function __construct(public string $namespace)
    {
    }
}