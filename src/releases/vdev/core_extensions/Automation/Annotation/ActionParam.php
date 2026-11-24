<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Annotation;

use Attribute;

/**
 * Décrit un paramètre d'une action, dans l'ordre de la méthode de WorkFlow.
 *
 * `name` doit correspondre au nom de l'argument PHP. `type`, `required` et
 * `default` sont déduits de la signature PHP lorsqu'ils sont omis ; `label` et
 * `description` servent au catalogue et à la documentation.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ActionParam
{
    public function __construct(
        public string $name,
        public ?string $type = null,
        public ?bool $required = null,
        public mixed $default = null,
        public string $label = '',
        public string $description = '',
    ) {
    }
}