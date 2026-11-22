<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;

use Attribute;

/** Marque une permission que ROLE_CRUD peut gérer sous réserve de la posséder. */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class RoleCrudManageable
{
}
