<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Job;

use InvalidArgumentException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Déclaration d'un type de job fourni par une extension.
 *
 * Le type est immuable et validé à la construction : un identifiant mal formé,
 * une classe de handler qui n'implémente pas le contrat ou des bornes hors
 * limites échouent au démarrage, jamais à l'exécution d'un job.
 *
 * Le payload n'est pas décrit ici. Le handler valide ce qu'il reçoit, car un
 * type peut évoluer sans migration de la file.
 */
final class JobType
{
    /** Durée attendue minimale d'un job, en secondes. */
    public const MIN_TIME_MIN = 1;

    /** Durée attendue maximale : au-delà, le job ne tiendrait pas dans un tick. */
    public const MIN_TIME_MAX = 20;

    /** Plafond de tentatives admis par un type. */
    public const MAX_ATTEMPTS_MAX = 10;

    /** Identifiant stable « extension.job », en minuscules. */
    public const ID_PATTERN = '/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/';

    /** Longueur maximale de la colonne `type`. */
    public const ID_MAX_LENGTH = 100;

    public readonly string $id;

    public readonly string $label;

    /** @var class-string<IJobHandler> */
    public readonly string $handlerClass;

    /** Durée attendue de l'exécution : condition de démarrage et bail. */
    public readonly int $minTime;

    public readonly int $maxAttempts;

    /**
     * @param class-string<IJobHandler> $handlerClass
     */
    public function __construct(
        string $id,
        string $label,
        string $handlerClass,
        int $minTime = 5,
        int $maxAttempts = 3,
    ) {
        if (preg_match(self::ID_PATTERN, $id) !== 1 || strlen($id) > self::ID_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'Identifiant de job invalide : "%s". Format attendu : extension.job en minuscules.',
                $id,
            ));
        }

        if (trim($label) === '') {
            throw new InvalidArgumentException(sprintf('Le type de job "%s" doit avoir un libellé.', $id));
        }

        // Refuser tôt une classe absente évite un job qui échoue à chaque tick.
        // Le handler hérite de BaseHelper : le worker l'instancie avec
        // l'Application isolée du principal, ce qui lui donne la base et les
        // helpers partagés comme à un helper ordinaire.
        if (!class_exists($handlerClass)
            || !is_subclass_of($handlerClass, IJobHandler::class)
            || !is_subclass_of($handlerClass, BaseHelper::class)) {
            throw new InvalidArgumentException(sprintf(
                'Le handler du job "%s" doit implémenter %s et étendre %s.',
                $id,
                IJobHandler::class,
                BaseHelper::class,
            ));
        }

        if ($minTime < self::MIN_TIME_MIN || $minTime > self::MIN_TIME_MAX) {
            throw new InvalidArgumentException(sprintf(
                'min_time du job "%s" hors limites (%d-%d).',
                $id,
                self::MIN_TIME_MIN,
                self::MIN_TIME_MAX,
            ));
        }

        if ($maxAttempts < 0 || $maxAttempts > self::MAX_ATTEMPTS_MAX) {
            throw new InvalidArgumentException(sprintf(
                'max_attempts du job "%s" hors limites (0-%d).',
                $id,
                self::MAX_ATTEMPTS_MAX,
            ));
        }

        $this->id = $id;
        $this->label = trim($label);
        $this->handlerClass = $handlerClass;
        $this->minTime = $minTime;
        $this->maxAttempts = $maxAttempts;
    }

    /** Extension propriétaire du type, déduite du préfixe. */
    public function producer(): string
    {
        return explode('.', $this->id, 2)[0];
    }
}
