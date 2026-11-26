<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Job;

use DateTimeInterface;

/**
 * Job prêt à être enfilé.
 *
 * Le producteur ne compose pas ce tableau à la main : chaque type expose une
 * fabrique typée sur son handler, par exemple
 * `SendMailJobHandler::forLocalRegistration($userId, $spaceId, $locale)`. Les
 * clés du payload et des options restent ainsi la propriété du type, et une
 * signature nommée se lit sans ouvrir la classe.
 *
 * Le payload ne porte que des références : la validation du type et la
 * normalisation des options ont lieu dans `JobQueue::enqueue()`.
 */
final class JobRequest
{
    /**
     * @param array<string, mixed> $payload
     * @param array{
     *     space_id?: int|null,
     *     priority?: int,
     *     available_at?: DateTimeInterface|string|null,
     *     dedup_key?: string|null,
     *     principal?: string,
     *     exec_user_id?: int|null,
     *     producer?: string|null,
     *     max_attempts?: int,
     *     created_by?: int|null
     * } $options
     */
    public function __construct(
        public readonly string $type,
        public readonly array $payload,
        public readonly array $options = [],
    ) {
    }
}
