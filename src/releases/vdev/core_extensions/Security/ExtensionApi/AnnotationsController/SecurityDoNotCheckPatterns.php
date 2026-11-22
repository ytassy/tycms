<?php

namespace YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController;

use Attribute;

/** Exempter uniquement les motifs du WAF, sans modifier ACL, CSRF ou validations métier. */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class SecurityDoNotCheckPatterns {}
