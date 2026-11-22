<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;

use Attribute;

/** Prérequis explicites, cumulés et requis dans le même rôle. */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final class RequiresPermissions
{
    /** @var list<string> */
    public readonly array $permissions;

    public function __construct(string ...$permissions)
    {
        $this->permissions = array_values(array_unique($permissions));
    }
}
