<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalType;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationTriggers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageDocument;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\WF\BaseWF;

/** Traitement public d'un clic sur un bouton de page d'automatisation. */
final class WFPageButton extends BaseWF
{
    use TraitCoreSharedHelpers;
    use TraitCoreAdditionalHelpers;
    use TraitAutomationHelpers;

    private const SESSION_KEY = 'automation_button_clicks';
    private const MAX_PER_MINUTE = 10;
    private const MAX_PER_HOUR = 100;

    /**
     * @return array{message: ?string, level: string, reload: bool}
     */
    public function click(
        int $pageId,
        string $nodeId,
        string $locale,
        string $csrfToken,
        string $buttonToken,
    ): array {
        if (!$this->session->verifyCsrfToken($csrfToken)) {
            return $this->reject(
                'Jeton de formulaire invalide. Rechargez la page.',
                'AUTOMATION_PAGE_BUTTON_CSRF',
                [],
                'Bouton de page #' . $pageId . ' : jeton CSRF invalide.',
            );
        }

        if (mb_strlen($locale) > 20) {
            return $this->reject(
                'Action indisponible.',
                'AUTOMATION_PAGE_BUTTON_LOCALE',
                [],
                'Bouton de page #' . $pageId . ' : locale invalide.',
            );
        }

        if (!$this->corePageButtonToken->verify($pageId, $nodeId, $csrfToken, $buttonToken)) {
            return $this->reject(
                'Ce bouton n’est plus disponible. Rechargez la page.',
                'AUTOMATION_PAGE_BUTTON_TOKEN',
                [],
                'Bouton de page #' . $pageId . ' : jeton de rendu invalide.',
            );
        }

        $space = $this->coreSpaceHelper->getCurrentSpace();

        if ($space === null) {
            return $this->reject(
                'Action indisponible.',
                'AUTOMATION_PAGE_BUTTON_SPACE',
                [],
                'Bouton de page #' . $pageId . ' : Space courant indisponible.',
            );
        }

        $spaceId = (int) $space->id;
        $page = $this->corePageHelper->getPage($pageId);

        if ($page === null
            || (int) $page->space_id !== $spaceId
            || (int) $page->status !== 1
            || (int) $page->type !== 2
            || !is_object($page->data)
            || (int) ($page->data->version ?? 0) !== PageDocument::VERSION) {
            return $this->reject(
                'Action indisponible.',
                'AUTOMATION_PAGE_BUTTON_PAGE',
                ['space_id' => $spaceId],
                'Bouton de page #' . $pageId . ' : page absente, publiée hors du Space ou version de document incompatible.',
            );
        }

        $node = PageDocument::findNode($page->data, $nodeId);
        $props = is_object($node?->props ?? null) ? $node->props : null;
        $scenarioKey = $props?->automation_scenario_key ?? null;

        if ($node === null
            || (string) ($node->type ?? '') !== 'core.button'
            || (string) ($props?->button_action ?? '') !== 'automation'
            || !is_string($scenarioKey)) {
            return $this->reject(
                'Action indisponible.',
                'AUTOMATION_PAGE_BUTTON_NODE',
                ['space_id' => $spaceId],
                'Bouton de page #' . $pageId . ' : nœud « ' . $nodeId . ' » introuvable ou non déclaré comme bouton d’automatisation.',
            );
        }

        $scenario = $this->corePageButtonHelper->findPublished($spaceId, $scenarioKey);

        if ($scenario === null) {
            $diagnostic = $this->corePageButtonHelper->diagnose($spaceId, $scenarioKey);

            return $this->reject(
                'Action indisponible.',
                'AUTOMATION_PAGE_BUTTON_SCENARIO',
                ['space_id' => $spaceId],
                'Bouton de page #' . $pageId . ' : scénario « ' . $scenarioKey . ' » inutilisable ('
                    . $diagnostic['scenario'] . '/' . $diagnostic['trigger'] . '/' . $diagnostic['published'] . ').',
            );
        }

        $userId = $this->session->getUserId();
        $principalMode = (string) $scenario->principal_mode;

        if ($principalMode === 'trigger' && ($userId === null || $userId <= 0)) {
            return $this->reject(
                'Connectez-vous pour utiliser ce bouton.',
                'AUTOMATION_PAGE_BUTTON_ANONYMOUS',
                ['space_id' => $spaceId],
                'Bouton de page #' . $pageId . ' : visiteur anonyme refusé en mode déclencheur.',
            );
        }

        if ($principalMode === 'service') {
            return $this->reject(
                'Action indisponible.',
                'AUTOMATION_PAGE_BUTTON_SERVICE',
                ['space_id' => $spaceId],
                'Bouton de page #' . $pageId . ' : scénario « ' . $scenarioKey
                    . ' » en mode service refusé (scénario #' . (int) $scenario->id . ').',
            );
        }

        if ($principalMode === 'author' && (int) ($scenario->execution_user_id ?? 0) <= 0) {
            return $this->reject(
                'Action indisponible.',
                'AUTOMATION_PAGE_BUTTON_AUTHOR_MISSING',
                ['space_id' => $spaceId],
                'Bouton de page #' . $pageId . ' : scénario « ' . $scenarioKey
                    . ' » en mode auteur sans auteur publié (scénario #' . (int) $scenario->id . ').',
            );
        }

        if (!$this->consumeRateLimit($spaceId, $pageId, $nodeId)) {
            return $this->reject(
                'Trop de demandes. Réessayez dans quelques instants.',
                'AUTOMATION_PAGE_BUTTON_RATE_LIMIT',
                ['space_id' => $spaceId],
                'Bouton de page #' . $pageId . ' : limite de débit atteinte pour le scénario « '
                    . $scenarioKey . ' » (scénario #' . (int) $scenario->id . ').',
            );
        }

        $principalType = $userId !== null && $userId > 0
            ? AutomationPrincipalType::USER
            : AutomationPrincipalType::ANONYMOUS;

        $payload = [
            'page' => ['id' => $pageId],
            'button' => ['id' => $nodeId],
            'locale' => $locale,
            'actor' => [
                'type' => $principalType,
                'id' => $userId !== null && $userId > 0 ? $userId : null,
            ],
        ];

        $messageMode = $this->corePageButtonHelper->successMessageMode($props);
        $scenarioOutput = null;
        $logCode = 'AUTOMATION_PAGE_BUTTON_QUEUED';
        $logMessage = 'Bouton de page #' . $pageId . ' : clic enregistré pour le scénario « ' . $scenarioKey
            . ' » (scénario #' . (int) $scenario->id . ', principal ' . $principalType . ').';

        if ($messageMode === 'result') {
            // Le mode « résultat » a besoin de la sortie du run : il exécute
            // donc le scénario de façon synchrone, dans cette requête.
            try {
                $triggerResult = $this->automationTrigger->triggerNow(
                    $spaceId,
                    CoreAutomationTriggers::PAGE_BUTTON_CLICKED,
                    $payload,
                    $userId !== null && $userId > 0 ? $userId : null,
                    $scenarioKey,
                );
            } catch (\Throwable $exception) {
                $this->logError(
                    'Bouton de page #' . $pageId . ' : l’exécution du scénario « ' . $scenarioKey
                        . ' » a échoué (scénario #' . (int) $scenario->id . ').',
                    ['space_id' => $spaceId],
                    'AUTOMATION_PAGE_BUTTON_EXEC_FAILED',
                    'automation',
                );
                $this->logException($exception);

                return $this->reject(
                    'L’action n’a pas pu être exécutée. Réessayez plus tard.',
                    'AUTOMATION_PAGE_BUTTON_EXEC_FAILED',
                    ['space_id' => $spaceId],
                    'Bouton de page #' . $pageId . ' : exécution interrompue pour le scénario « '
                        . $scenarioKey . ' » (scénario #' . (int) $scenario->id . ').',
                );
            }

            $outcome = $triggerResult->runs[0] ?? null;

            if ($outcome === null || $outcome->isFailed()) {
                return $this->reject(
                    'L’action n’a pas pu être exécutée. Réessayez plus tard.',
                    'AUTOMATION_PAGE_BUTTON_RUN_FAILED',
                    ['space_id' => $spaceId],
                    'Bouton de page #' . $pageId . ' : run en échec pour le scénario « '
                        . $scenarioKey . ' » (scénario #' . (int) $scenario->id . ').',
                );
            }

            $scenarioOutput = $outcome->output;
            $logCode = 'AUTOMATION_PAGE_BUTTON_EXECUTED';
            $logMessage = 'Bouton de page #' . $pageId . ' : scénario « ' . $scenarioKey
                . ' » exécuté (scénario #' . (int) $scenario->id . ', principal ' . $principalType . ').';
        } else {
            try {
                $eventId = $this->automationTrigger->emit(
                    $spaceId,
                    CoreAutomationTriggers::PAGE_BUTTON_CLICKED,
                    $payload,
                    $principalType,
                    $userId !== null && $userId > 0 ? $userId : null,
                    $scenarioKey,
                );
            } catch (\Throwable $exception) {
                $this->logError(
                    'Bouton de page #' . $pageId . ' : l’événement du scénario « ' . $scenarioKey
                        . ' » n’a pas pu être enregistré (scénario #' . (int) $scenario->id . ').',
                    ['space_id' => $spaceId],
                    'AUTOMATION_PAGE_BUTTON_EMIT_FAILED',
                    'automation',
                );
                $this->logException($exception);
                $eventId = null;
            }

            if ($eventId === null) {
                return $this->reject(
                    'Action indisponible.',
                    'AUTOMATION_PAGE_BUTTON_EMIT_FAILED',
                    ['space_id' => $spaceId],
                    'Bouton de page #' . $pageId . ' : émission refusée pour le scénario « '
                        . $scenarioKey . ' » (scénario #' . (int) $scenario->id . ').',
                );
            }
        }

        $this->logInfo(
            $logMessage,
            ['space_id' => $spaceId],
            $logCode,
            'automation',
        );

        $feedback = $this->corePageButtonHelper->feedback(
            $props,
            $locale,
            $spaceId,
            $scenarioOutput,
        );

        return [
            'message' => $feedback['message'],
            'level' => $feedback['level'],
            'reload' => (bool) ($props->reload_on_close ?? false),
        ];
    }

    private function consumeRateLimit(int $spaceId, int $pageId, string $nodeId): bool
    {
        // Les clics différés créent un événement, les clics synchrones un run
        // sans événement : additionner les deux garde un quota horaire commun.
        $recent = $this->automationRepository->countRecentEvents(
            $spaceId,
            CoreAutomationTriggers::PAGE_BUTTON_CLICKED,
            3600,
        ) + $this->automationRepository->countRecentDetachedRuns(
            $spaceId,
            CoreAutomationTriggers::PAGE_BUTTON_CLICKED,
            3600,
        );

        if ($recent >= self::MAX_PER_HOUR) {
            return false;
        }

        $now = time();
        $clicks = $this->session->get(self::SESSION_KEY, []);
        $clicks = is_array($clicks) ? $clicks : [];
        $scope = $pageId . ':' . $nodeId;

        // Purger les portées et horodatages anciens à chaque tentative.
        foreach ($clicks as $key => $timestamps) {
            $timestamps = is_array($timestamps) ? $timestamps : [];
            $kept = array_values(array_filter(
                $timestamps,
                static fn (mixed $timestamp): bool => is_int($timestamp) && $timestamp > $now - 60,
            ));

            if ($kept === []) {
                unset($clicks[$key]);
            } else {
                $clicks[$key] = $kept;
            }
        }

        if (count($clicks[$scope] ?? []) >= self::MAX_PER_MINUTE) {
            return false;
        }

        $clicks[$scope][] = $now;
        $this->session->set(self::SESSION_KEY, $clicks);

        return true;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{message: string, level: string, reload: bool}
     */
    private function reject(
        string $message,
        string $code,
        array $context,
        ?string $logMessage = null,
    ): array {
        $this->logWarning($logMessage ?? $message, $context, $code, 'automation');

        return [
            'message' => $message,
            'level' => 'warning',
            'reload' => false,
        ];
    }
}
