<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;

use Attribute;

/** Classe une permission dans un onglet et un groupe de l'éditeur de rôles. */
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class UiGroup
{
    public function __construct(
        public string $tab,
        public string $group,
    ) {
    }
}
