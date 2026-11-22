<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Session;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Runtime\DateValue;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Session\DatabaseSession;

/**
 * Données de scénario rangées dans la session du navigateur.
 *
 * Elles portent un état court entre deux requêtes d'un même visiteur : étapes
 * d'une inscription, assistant multi-pages.
 *
 * Deux protections structurent l'accès. Chaque clé reçoit d'abord un espace de
 * noms du Space, puis le préfixe `automation_` : un scénario ne peut donc ni
 * écraser un identifiant interne (`user_id`, `csrf_token`, `current_space_id`)
 * ni lire les données d'un autre Space du même domaine. La valeur rangée est
 * bornée en octets et en types, car la session partage ses écritures avec
 * l'authentification.
 */
final class BrowserSessionPropertyHelper extends BaseHelper
{
    /** Préfixe imposé à toute clé, visible dans le stockage de la session. */
    public const PREFIX = 'automation_';

    /** Plafond d'une valeur sérialisée, pour ne pas saturer le stockage partagé. */
    public const MAX_BYTES = 16384;

    /** Nombre maximal de segments d'un chemin de lecture imbriquée. */
    private const MAX_PATH_DEPTH = 8;

    /** Segment de clé : minuscules non accentuées, chiffres, soulignés, tirets. */
    private const KEY_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,47}\z/';

    /** Clé complète rangée en session, propre au Space du principal. */
    public function storageKey(int $spaceId, string $key, ActionContext $context): string
    {
        return self::PREFIX . $spaceId . '_' . $this->assertKey($key, $context);
    }

    /**
     * Valeur rangée, ou la valeur fournie si la clé est absente.
     *
     * La lecture est permise sur toute session : un scénario en mode auteur
     * peut relire un état rangé par un scénario du même Space. Une valeur d'un
     * autre producteur est ramenée aux types du langage, afin que le scénario
     * ne manipule jamais d'objet PHP.
     */
    public function read(int $spaceId, string $key, mixed $default, ActionContext $context): mixed
    {
        $value = $this->session->get($this->storageKey($spaceId, $key, $context));

        return $value === null ? $default : $this->toScriptValue($value);
    }

    /**
     * Écrit une valeur du langage et retourne sa représentation rangée.
     *
     * Seule la session du navigateur accepte une écriture.
     *
     * @throws \YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException
     */
    public function write(int $spaceId, string $key, mixed $value, ActionContext $context): mixed
    {
        $storageKey = $this->storageKey($spaceId, $key, $context);
        $stored = $this->toStoredValue($value, $context);

        if (strlen(serialize($stored)) > self::MAX_BYTES) {
            throw ValueTools::error(
                DiagnosticCode::LIMIT_EXCEEDED,
                'La valeur de session dépasse la taille autorisée ('
                    . self::MAX_BYTES . ' octets sérialisés).',
                $context->span,
            );
        }

        $this->browserSession($context)->set($storageKey, $stored);

        return $stored;
    }

    /**
     * Supprime la clé et indique si elle existait.
     *
     * Cette modification reste réservée à la session du navigateur.
     *
     * @throws \YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException
     */
    public function remove(int $spaceId, string $key, ActionContext $context): bool
    {
        $session = $this->browserSession($context);
        $storageKey = $this->storageKey($spaceId, $key, $context);
        $existed = $session->get($storageKey) !== null;

        $session->remove($storageKey);

        return $existed;
    }

    /** Lit un chemin `a.b` dans une valeur ; un chemin absent donne `null`. */
    public function path(mixed $value, string $path): mixed
    {
        $current = $value;

        foreach (explode('.', $path) as $depth => $segment) {
            if ($depth >= self::MAX_PATH_DEPTH || !is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * Convertit une valeur du langage vers sa forme rangée.
     *
     * Les dates prennent le format ISO de `date::toIso`, que `date::parse`
     * relit : la session ne contient ainsi aucun objet PHP à dé-sérialiser.
     * Un tableau doit être une map à clés non vides.
     *
     * @throws \YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException
     */
    private function toStoredValue(mixed $value, ActionContext $context): string|int|float|bool|array|null
    {
        if ($value instanceof DateValue) {
            return $value->toIso();
        }

        if ($value === null || is_int($value) || is_bool($value) || is_string($value)) {
            return $value;
        }

        if (is_float($value)) {
            return is_finite($value)
                ? $value
                : throw ValueTools::error(DiagnosticCode::INVALID_VALUE, 'Nombre flottant non fini.', $context->span);
        }

        if (is_array($value)) {
            $stored = [];

            foreach ($value as $key => $item) {
                if (!is_string($key) || $key === '') {
                    throw ValueTools::error(
                        DiagnosticCode::INVALID_ARGUMENT,
                        'Une valeur de session est une map à clés non vides.',
                        $context->span,
                    );
                }

                $stored[$key] = $this->toStoredValue($item, $context);
            }

            return $stored;
        }

        throw ValueTools::error(
            DiagnosticCode::INVALID_ARGUMENT,
            'Type de valeur inconnu pour la session.',
            $context->span,
        );
    }

    /** @throws \YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException */
    private function assertKey(string $key, ActionContext $context): string
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw ValueTools::error(
                DiagnosticCode::INVALID_ARGUMENT,
                'Clé de session invalide : au plus 48 minuscules non accentuées, chiffres, tirets ou underscores.',
                $context->span,
            );
        }

        return $key;
    }

    private function toScriptValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn (mixed $item): mixed => $this->toScriptValue($item), $value);
        }

        return is_string($value) || is_int($value) || is_float($value) || is_bool($value) ? $value : '';
    }

    /**
     * Session du navigateur, ou refus explicite d'une modification.
     *
     * Seule la session rangée en base est durable : c'est celle d'un visiteur
     * du site. Une `RunSession` (mode auteur, webhook, planification) est une
     * session mémoire propre au run ; y écrire persisterait des données de
     * session dans la trace du run. La lecture reste permise sur ces sessions,
     * qui ne modifient rien. Les clés, elles, restent propres au Space du
     * scénario par leur préfixe.
     */
    private function browserSession(ActionContext $context): DatabaseSession
    {
        if ($this->session instanceof DatabaseSession) {
            return $this->session;
        }

        throw ValueTools::error(
            DiagnosticCode::ACTION_UNAVAILABLE,
            'La session du navigateur n’est pas disponible pour ce run : '
                . 'elle n’existe que pour un scénario exécuté dans sa requête.',
            $context->span,
        );
    }
}
