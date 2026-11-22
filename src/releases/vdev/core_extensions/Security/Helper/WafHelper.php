<?php

namespace YTassy\Tycms\CoreExts\Security\Helper;

use ReflectionClass;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\CoreExts\Security\Traits\TraitSecurityHelpers;
use YTassy\Tycms\CoreExts\Security\Waf\BlockedResponse;
use YTassy\Tycms\CoreExts\Security\Waf\PatternScanner;
use YTassy\Tycms\CoreExts\Security\Waf\WafConfiguration;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Routing\Route;

/** Contrôle complémentaire des motifs, avant le contrôleur et toute transaction métier. */
class WafHelper extends BaseHelper
{
    use TraitSecurityHelpers;

    /** Héritage direct vers le Space 1 seulement ; la configuration locale reste enregistrée. */
    public function effectiveConfiguration(int $spaceId): array
    {
        $local = array_intersect_key($this->securityPolicyHelper->configuration($spaceId), WafConfiguration::defaults());
        $policySpaceId = $spaceId !== 1 && $local['waf_use_root'] ? 1 : $spaceId;
        $values = $policySpaceId === $spaceId ? $local
            : array_intersect_key($this->securityPolicyHelper->configuration(1), WafConfiguration::defaults());
        return ['space_id' => $policySpaceId, 'values' => $values];
    }

    public function check(Route $route): void
    {
        if ($this->isExempt($route)) return;
        $spaceId = $this->session->getSpaceId() ?? 1;
        $effective = $this->effectiveConfiguration($spaceId);
        $values = $effective['values'];
        if ($values['waf_mode'] === 'off') return;

        // Ne jamais déduire ROOT d’un champ HTTP ou de la simple présence d’un utilisateur connecté.
        if ($values['waf_skip_root'] && $this->session->getUserId() !== null && $this->coreAclHelper->isRoot()) return;
        $patterns = WafConfiguration::patterns($values['waf_patterns']);
        if ($patterns->strings === [] && get_object_vars($patterns->regexes) === []) return;

        $event = (new PatternScanner())->scan($this->request, $patterns);
        if ($event === null) return;
        $this->logEvent(['space_id' => $spaceId, 'policy_space_id' => $effective['space_id'],
            'route' => $route->name, 'mode' => $values['waf_mode'], ...$event]);

        // En blocage, une analyse incomplète ou une erreur PCRE ne vaut pas « aucune détection ».
        if ($values['waf_mode'] === 'block') {
            throw new ExceptionResponse(BlockedResponse::create($route, $this->request, $values['waf_message']));
        }
    }

    public function isExempt(Route $route): bool
    {
        // La réflexion exacte évite les collisions de noms courts dans les attributs du routeur.
        $class = new ReflectionClass($route->controller);
        return $class->getAttributes(SecurityDoNotCheckPatterns::class) !== []
            || $class->getMethod($route->controllerMethod)->getAttributes(SecurityDoNotCheckPatterns::class) !== [];
    }

    protected function logEvent(array $event): void
    {
        // Un événement au maximum par requête, sans URL brute, valeurs, cookies ou extraits détectés.
        $level = ($event['kind'] ?? null) === 'match'
            ? (($event['mode'] ?? null) === 'observe' ? 'INFO' : 'WARNING') : 'ERROR';
        $this->logger->log($level, 'security.waf', 'WAF_EVENT', 'Événement du pare-feu applicatif.', $event);
    }
}
