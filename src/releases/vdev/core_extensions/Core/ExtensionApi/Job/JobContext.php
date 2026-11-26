<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Job;

/**
 * Contexte d'exécution transmis au handler.
 *
 * Il ne porte que des données déjà résolues par le worker : le handler ne
 * relit ni la ligne de job ni le payload pour retrouver son identité. Les
 * valeurs sont en lecture seule.
 */
final class JobContext
{
    public function __construct(
        public readonly int $jobId,
        public readonly string $type,
        public readonly ?int $spaceId,
        public readonly string $principal,
        public readonly ?int $execUserId,
        public readonly string $producer,
        public readonly int $attempts,
        public readonly int $maxAttempts,
    ) {
    }

    /** Un job qui rejoue une tentative après échec. */
    public function isRetry(): bool
    {
        return $this->attempts > 1;
    }

    /** Il reste au handler au moins une tentative après celle-ci. */
    public function canRetry(): bool
    {
        return $this->attempts < $this->maxAttempts;
    }
}
