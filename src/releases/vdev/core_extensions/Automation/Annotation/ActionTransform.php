<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Annotation;

use Attribute;

/**
 * Normalise le résultat d'une action avant sa traversée de la frontière de
 * valeurs.
 *
 * `class::method` est une méthode statique appelée avec le résultat brut de la
 * méthode de WorkFlow. Elle sert à projeter une structure riche (ORM, tableau
 * interne) vers des valeurs sûres (scalaires, tableaux).
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ActionTransform
{
    /** @param class-string $class */
    public function __construct(public string $class, public string $method)
    {
    }
}