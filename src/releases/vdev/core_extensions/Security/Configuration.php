<?php

namespace YTassy\Tycms\CoreExts\Security;

use RuntimeException;
use YTassy\Tycms\CoreExts\Security\Waf\WafConfiguration;

/** Séparer les contrats CSP et WAF sans autoriser de paramètres inconnus en base. */
final class Configuration
{
    public static function defaults(): array { return [...SecurityPolicy::defaults(), ...WafConfiguration::defaults()]; }

    public static function normalize(array $values): array
    {
        if (array_diff_key($values, self::defaults()) !== [] || array_diff_key(self::defaults(), $values) !== []) {
            throw new RuntimeException('Les paramètres de sécurité sont incomplets ou inconnus. Appliquez les migrations nécessaires.');
        }
        return [...SecurityPolicy::normalize(array_intersect_key($values, SecurityPolicy::defaults())),
            ...WafConfiguration::normalize(array_intersect_key($values, WafConfiguration::defaults()))];
    }
}
