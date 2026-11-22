<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationTriggers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Response\ExceptionResponse;

/** Résolution des scénarios liés aux boutons de page. */
final class PageButtonHelper extends BaseHelper
{
    use TraitAutomationHelpers;

    public const DEFAULT_SUCCESS_MESSAGE = 'Votre demande a été prise en compte.';

    private const MAX_MESSAGE_LENGTH = 500;

    /** @var array<string, object|null> */
    private array $publishedCache = [];

    public function triggerId(): string
    {
        return CoreAutomationTriggers::PAGE_BUTTON_CLICKED;
    }

    /** Mode de confirmation retenu par le bouton, avec repli sûr. */
    public function successMessageMode(?object $props): string
    {
        $mode = is_string($props?->success_message_mode ?? null)
            ? $props->success_message_mode
            : 'default';

        return in_array($mode, ['default', 'custom', 'result', 'none'], true)
            ? $mode
            : 'default';
    }

    /**
     * Message flash affiché après un clic réussi.
     *
     * `null` signifie que le bouton demande explicitement aucun message.
     * En mode `result`, seule une chaîne non vide est reprise ; sinon le
     * message par défaut évite une confirmation silencieuse involontaire.
     */
    public function successMessage(
        ?object $props,
        string $locale,
        int $spaceId,
        mixed $scenarioOutput = null,
    ): ?string {
        $mode = $this->successMessageMode($props);

        if ($mode === 'none') {
            return null;
        }

        if ($mode === 'result') {
            $message = is_string($scenarioOutput) ? trim($scenarioOutput) : '';

            return $message !== '' ? $this->truncate($message) : self::DEFAULT_SUCCESS_MESSAGE;
        }

        if ($mode === 'custom') {
            $message = $this->coreLanguageProjectionHelper->projectLocalizedTextValue(
                $props->success_message ?? null,
                $locale,
                $spaceId,
            );

            if ($message !== '') {
                return $this->truncate($message);
            }
        }

        return self::DEFAULT_SUCCESS_MESSAGE;
    }

    private function truncate(string $message): string
    {
        return mb_strlen($message) > self::MAX_MESSAGE_LENGTH
            ? mb_substr($message, 0, self::MAX_MESSAGE_LENGTH) . '…'
            : $message;
    }

    /**
     * Message et niveau de la modale après un clic.
     *
     * En mode `result`, une chaîne est une confirmation ; une map produite
     * par `res::*` choisit le niveau. Un résultat inexploitable retombe sur
     * le message par défaut.
     *
     * @return array{message: ?string, level: string}
     */
    public function feedback(
        ?object $props,
        string $locale,
        int $spaceId,
        mixed $scenarioOutput = null,
    ): array {
        if ($this->successMessageMode($props) === 'result') {
            $response = $this->scenarioResponse($scenarioOutput);

            if ($response !== null) {
                return $response;
            }
        }

        return [
            'message' => $this->successMessage($props, $locale, $spaceId, $scenarioOutput),
            'level' => 'success',
        ];
    }

    /** @return array{message: string, level: string}|null */
    private function scenarioResponse(mixed $output): ?array
    {
        if (is_object($output)) {
            $values = get_object_vars($output);
        } elseif (is_array($output)) {
            $values = $output;
        } else {
            return null;
        }

        $message = $values['message'] ?? null;

        if (!is_string($message) || trim($message) === '') {
            return null;
        }

        $rawLevel = $values['message_level'] ?? '';

        if ($rawLevel instanceof \BackedEnum) {
            $rawLevel = (string) $rawLevel->value;
        }

        $rawLevel = is_string($rawLevel) ? strtoupper($rawLevel) : '';
        $level = match ($rawLevel) {
            'INFO' => 'info',
            'SUCCESS' => 'success',
            'WARNING' => 'warning',
            'DANGER' => 'danger',
            'ERROR' => 'error',
            default => (($values['error'] ?? false) === true ? 'error' : 'success'),
        };

        // Un message marqué en erreur ne doit jamais prendre l'apparence d'un succès.
        if (($values['error'] ?? false) === true && in_array($level, ['success', 'info'], true)) {
            $level = 'error';
        }

        return [
            'message' => $this->truncate(trim($message)),
            'level' => $level,
        ];
    }

    public function assertScenarioKey(int $spaceId, string $key): void
    {
        if (!$this->automationEnabled()) {
            ExceptionResponse::throwWarning('L’automatisation n’est pas disponible.');
        }

        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $key) !== 1) {
            ExceptionResponse::throwWarning('Clé de scénario invalide.');
        }

        $scenario = $this->automationRepository->findScenarioByKey($spaceId, $key);

        if ($scenario === null || (string) $scenario->status !== 'active') {
            ExceptionResponse::throwWarning('Scénario introuvable ou inactif.');
        }

        $trigger = $this->automationRepository->findTrigger((int) $scenario->id);

        if ($trigger === null
            || (string) $trigger->trigger_id !== $this->triggerId()
            || (int) $trigger->enabled !== 1) {
            ExceptionResponse::throwWarning(
                'Le scénario doit avoir le déclencheur « Clic sur un bouton de page ».',
            );
        }
    }

    /** @return array{scenario: string, trigger: string, published: string} */
    public function diagnose(int $spaceId, string $key): array
    {
        if (!$this->automationEnabled()) {
            return ['scenario' => 'automation_disabled', 'trigger' => 'unknown', 'published' => 'unknown'];
        }

        if (preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $key) !== 1) {
            return ['scenario' => 'invalid_key', 'trigger' => 'unknown', 'published' => 'unknown'];
        }

        $scenario = $this->automationRepository->findScenarioByKey($spaceId, $key);

        if ($scenario === null) {
            return ['scenario' => 'not_found', 'trigger' => 'missing', 'published' => 'missing'];
        }

        if ((string) $scenario->status !== 'active') {
            return ['scenario' => 'inactive', 'trigger' => 'unknown', 'published' => 'unknown'];
        }

        $trigger = $this->automationRepository->findTrigger((int) $scenario->id);
        $triggerState = 'ok';

        if ($trigger === null) {
            $triggerState = 'missing';
        } elseif ((string) $trigger->trigger_id !== $this->triggerId()) {
            $triggerState = 'other_trigger';
        } elseif ((int) $trigger->enabled !== 1) {
            $triggerState = 'disabled';
        }

        return [
            'scenario' => 'ok',
            'trigger' => $triggerState,
            'published' => $this->automationRepository->findPublishedVersion((int) $scenario->id) === null
                ? 'missing'
                : 'ok',
        ];
    }

    private function automationEnabled(): bool
    {
        return isset($this->app->getExtensions()['plg_automation']);
    }

    public function findPublished(int $spaceId, string $key): ?object
    {
        if (!$this->automationEnabled()
            || preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $key) !== 1) {
            return null;
        }

        $cacheKey = $spaceId . ':' . $key;

        if (array_key_exists($cacheKey, $this->publishedCache)) {
            return $this->publishedCache[$cacheKey];
        }

        $scenario = $this->automationRepository->findScenarioByKey($spaceId, $key);
        $published = null;

        if ($scenario !== null && (string) $scenario->status === 'active') {
            $trigger = $this->automationRepository->findTrigger((int) $scenario->id);

            if ($trigger !== null
                && (string) $trigger->trigger_id === $this->triggerId()
                && (int) $trigger->enabled === 1
                && $this->automationRepository->findPublishedVersion((int) $scenario->id) !== null) {
                $published = $scenario;
            }
        }

        return $this->publishedCache[$cacheKey] = $published;
    }
}
