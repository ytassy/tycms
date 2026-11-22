<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
class PermDef
{
	public string $name;
    public bool $manageableByRoleCrud = false;
    public ?UiGroup $uiGroup = null;

    /** @var list<string> Prérequis directs déclarés dans le catalogue PHP. */
    public array $requiresPermissions = [];

	public function __construct(
        public readonly string $uiTitle,
        public readonly string $uiDescription,
	)
	{
	}

}
