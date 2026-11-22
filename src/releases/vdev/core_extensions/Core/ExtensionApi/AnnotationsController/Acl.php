<?php

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
class Acl
{
	public function __construct(
		public readonly ?int $spaceId = null,
		public readonly ?string $globalPermission = null,
		public readonly ?int $globalRole = null,
	)
	{
	}

}
