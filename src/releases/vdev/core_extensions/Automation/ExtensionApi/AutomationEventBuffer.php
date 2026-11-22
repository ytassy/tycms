<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\ExtensionApi;

use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\AutomationValueJson;
use YTassy\Tycms\CoreExts\Automation\Runtime\Identity\SystemIdGenerator;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Outbox des événements de domaine émis pendant une requête.
 *
 * Les lignes sont écrites dans la transaction métier. Après le commit, le
 * hook de route draine les identifiants émis et exécute les scénarios abonnés
 * de façon synchrone. Un événement enfant hérite de la corrélation et de la
 * profondeur du run qui l'a produit. Un producteur qui doit répondre avec le
 * résultat (webhook en mode `result`) peut traiter son propre événement avant
 * le commit avec `drainEvent()`.
 */
final class AutomationEventBuffer extends BaseHelper
{
    use TraitAutomationHelpers;

    /** Profondeur maximale d'une chaîne d'événements. */
    public const MAX_DEPTH = 8;

    /** Garde-fou sur le nombre d'événements traités pendant un même drain. */
    public const MAX_EVENTS_PER_DRAIN = 100;

    /** @var list<string> */
    private array $pendingEventIds = [];

    private bool $draining = false;

    /** @var list<array{eventId: ?string, depth: int, correlationId: ?string}> */
    private array $contexts = [];

    /**
     * Écrit un événement pending et le marque pour le drain de la requête.
     *
     * Retourne null lorsque l'automatisation est désactivée, que la charge
     * utile n'est pas une valeur sûre ou que la profondeur de chaîne est
     * dépassée : un événement invalide ne doit jamais annuler l'écriture
     * métier qui l'accompagne.
     *
     * @param array<mixed> $payload
     */
    public function emit(
        int $spaceId,
        string $triggerId,
        array $payload,
        string $principalType = AutomationPrincipalType::USER,
        ?int $principalId = null,
        ?string $targetScenarioKey = null,
    ): ?string {
        if (!$this->isAutomationEnabled()) {
            return null;
        }

        if ($spaceId <= 0 || preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*){1,4}\z/', $triggerId) !== 1) {
            $this->logWarning(
                'L’événement d’automatisation est mal identifié.',
                ['space_id' => $spaceId],
                'AUTOMATION_EVENT_INVALID',
                'automation',
            );

            return null;
        }

        if (!in_array($principalType, [
            AutomationPrincipalType::USER,
            AutomationPrincipalType::SERVICE,
            AutomationPrincipalType::ANONYMOUS,
        ], true)) {
            $this->logWarning(
                'Le principal de l’événement est invalide.',
                ['space_id' => $spaceId],
                'AUTOMATION_EVENT_INVALID',
                'automation',
            );

            return null;
        }

        if ($targetScenarioKey !== null
            && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $targetScenarioKey) !== 1) {
            $this->logWarning(
                'La clé de scénario ciblée par l’événement est invalide.',
                ['space_id' => $spaceId],
                'AUTOMATION_EVENT_INVALID',
                'automation',
            );

            return null;
        }

        $context = $this->currentContext();
        $depth = $context === null ? 0 : $context['depth'] + 1;

        if ($depth > self::MAX_DEPTH) {
            $this->logWarning(
                'La chaîne d’événements dépasse la profondeur autorisée.',
                ['space_id' => $spaceId, 'depth' => $depth],
                'AUTOMATION_EVENT_DEPTH_EXCEEDED',
                'automation',
            );

            return null;
        }

        try {
            $safePayload = ValueTools::assertSafe($payload, new Limits(), self::neutralSpan());
        } catch (\Throwable $exception) {
            $this->logWarning(
                'La charge utile de l’événement est invalide.',
                ['space_id' => $spaceId],
                'AUTOMATION_EVENT_PAYLOAD_INVALID',
                'automation',
            );
            $this->logException($exception);

            return null;
        }

        if (!is_array($safePayload) || ($safePayload !== [] && array_is_list($safePayload))) {
            $this->logWarning(
                'La charge utile de l’événement doit être une map.',
                ['space_id' => $spaceId],
                'AUTOMATION_EVENT_PAYLOAD_INVALID',
                'automation',
            );

            return null;
        }

        $eventId = (new SystemIdGenerator())->uuid();
        $correlationId = $context['correlationId'] ?? $eventId;
        $causationId = $context['eventId'] ?? null;

        try {
            $this->automationRepository->createEvent(
                $eventId,
                $spaceId,
                $triggerId,
                $targetScenarioKey,
                AutomationValueJson::encode($safePayload),
                $principalType,
                $principalId,
                $correlationId,
                $depth,
                $causationId,
            );
        } catch (\Throwable $exception) {
            $this->logError(
                'L’événement d’automatisation n’a pas pu être enregistré.',
                ['space_id' => $spaceId, 'depth' => $depth],
                'AUTOMATION_EVENT_PERSIST_FAILED',
                'automation',
            );
            $this->logException($exception);

            return null;
        }

        $this->pendingEventIds[] = $eventId;

        return $eventId;
    }

    /** Ouvre le contexte d'un run : les émissions deviennent des enfants. */
    public function enterEventContext(?string $eventId, int $depth, ?string $correlationId): void
    {
        $this->contexts[] = [
            'eventId' => $eventId,
            'depth' => $depth,
            'correlationId' => $correlationId,
        ];
    }

    public function leaveEventContext(): void
    {
        array_pop($this->contexts);
    }

    /**
     * Traite immédiatement un événement encore en attente et renvoie les
     * résultats des runs.
     *
     * Utilisé par un producteur qui doit répondre avec le résultat, comme le
     * webhook en mode `result`. Les événements enfants émis par les actions
     * restent dans la file et sont drainés après le commit.
     *
     * @return list<array<string, mixed>>
     */
    public function drainEvent(string $eventId): array
    {
        $index = array_search($eventId, $this->pendingEventIds, true);

        if ($index === false || $this->draining) {
            return [];
        }

        array_splice($this->pendingEventIds, $index, 1);
        $this->draining = true;

        try {
            return $this->automationEventDispatcher->dispatch($eventId);
        } catch (\Throwable $exception) {
            // Laisser le drain de fin de requête reprendre l'événement si le
            // dispatcher n'a pas pu le terminer.
            $this->pendingEventIds[] = $eventId;
            $this->logError(
                'Un événement d’automatisation n’a pas pu être traité immédiatement.',
                [],
                'AUTOMATION_EVENT_DISPATCH_FAILED',
                'automation',
            );
            $this->logException($exception);

            return [];
        } finally {
            $this->draining = false;
        }
    }

    /**
     * Traite les événements émis pendant la requête.
     *
     * `$writesCommitted` vaut false lorsqu'aucun drain n'est permis : les
     * lignes ont alors été annulées avec la transaction métier.
     */
    public function drain(bool $writesCommitted): void
    {
        if (!$writesCommitted) {
            $this->pendingEventIds = [];

            return;
        }

        if ($this->draining) {
            return;
        }

        $this->draining = true;
        $processed = 0;

        try {
            while ($this->pendingEventIds !== []) {
                if ($processed >= self::MAX_EVENTS_PER_DRAIN) {
                    $this->logWarning(
                        'Le drain d’événements dépasse le quota de la requête.',
                        [],
                        'AUTOMATION_EVENT_DRAIN_LIMIT',
                        'automation',
                    );
                    break;
                }

                $eventId = array_shift($this->pendingEventIds);
                ++$processed;

                try {
                    $this->automationEventDispatcher->dispatch($eventId);
                } catch (\Throwable $exception) {
                    $this->logError(
                        'Un événement d’automatisation n’a pas pu être traité.',
                        [],
                        'AUTOMATION_EVENT_DISPATCH_FAILED',
                        'automation',
                    );
                    $this->logException($exception);
                }
            }
        } finally {
            $this->draining = false;
        }
    }

    /** @return array{eventId: ?string, depth: int, correlationId: ?string}|null */
    private function currentContext(): ?array
    {
        return $this->contexts === [] ? null : $this->contexts[array_key_last($this->contexts)];
    }

    private function isAutomationEnabled(): bool
    {
        return isset($this->app->getExtensions()['plg_automation']);
    }

    private static function neutralSpan(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}
