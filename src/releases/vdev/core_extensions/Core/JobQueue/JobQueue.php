<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

use DateTimeInterface;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobRequest;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tools\DateTools;

/**
 * Façade de la file de jobs.
 *
 * `enqueue()` écrit dans la transaction de l'appelant et ne valide rien : un
 * métier annulé ne laisse aucun job orphelin, la ligne de file faisant office
 * de boîte d'envoi. Les lectures et l'annulation servent l'administration et
 * les producteurs, jamais le worker.
 */
final class JobQueue extends BaseHelper
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const PRINCIPAL_NONE = 'none';
    public const PRINCIPAL_SYSTEM = 'system';
    public const PRINCIPAL_USER = 'user';

    public const PRIORITY_MIN = 0;
    public const PRIORITY_MAX = 100;
    public const PRIORITY_DEFAULT = 50;

    /**
     * Enfile un job et retourne son identifiant.
     *
     * Le `JobRequest` vient d'une fabrique typée du handler, jamais d'un tableau
     * composé à la main par le producteur. Avec `dedup_key`, un job déjà présent
     * pour le même type et la même clé est retourné au lieu d'en créer un second.
     *
     * @throws TechException Type inconnu, payload invalide ou option refusée.
     */
    public function enqueue(JobRequest $request): int
    {
        $jobType = $this->jobType($request->type);
        $normalised = $this->normaliseOptions($jobType, $request->options);
        $encoded = JobPayload::encode($request->payload);

        // L'upsert rend l'enfilement idempotent sans lecture préalable : la clé
        // unique (type, dedup_key) tranche, et LAST_INSERT_ID(id) rend
        // l'identifiant de la ligne existante.
        $this->db->execute(
            <<<'SQL'
                INSERT INTO jobs
                    (space_id, type, producer, payload, status, priority, attempts, max_attempts,
                     principal, exec_user_id, created_by, dedup_key, available_at, created)
                VALUES (%, %, %, %, 'pending', %, 0, %, %, %, %, %,
                        COALESCE(%, current_timestamp()), current_timestamp())
                ON DUPLICATE KEY UPDATE id = LAST_INSERT_ID(id)
                SQL,
            [
                $normalised['space_id'],
                $jobType->id,
                $normalised['producer'],
                $encoded,
                $normalised['priority'],
                $normalised['max_attempts'],
                $normalised['principal'],
                $normalised['exec_user_id'],
                $normalised['created_by'],
                $normalised['dedup_key'],
                $normalised['available_at'],
            ],
        );

        return (int) $this->db->getLastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(int $jobId): ?array
    {
        $row = $this->db->selectOneOrNull(
            <<<'SQL'
                SELECT id, space_id, type, producer, status, priority, attempts, max_attempts,
                       principal, exec_user_id, created_by, dedup_key, available_at,
                       reserved_until, cancel_requested, last_error, created,
                       started_at, finished_at, duration_ms
                FROM jobs
                WHERE id = %
                LIMIT 1
                SQL,
            [$jobId],
        );

        return $row === null ? null : (array) $row;
    }

    /**
     * Annule un job en attente, ou demande l'arrêt d'un job en cours.
     *
     * Le résultat vient de la mise à jour conditionnelle elle-même : zéro ligne
     * signifie « job absent » ou « déjà terminé ».
     */
    public function cancel(int $jobId): bool
    {
        $cancelled = $this->db->execute(
            "UPDATE jobs
                SET status = 'cancelled', finished_at = current_timestamp()
              WHERE id = % AND status = 'pending'",
            [$jobId],
        );

        if ($cancelled > 0) {
            return true;
        }

        // Un job déjà réservé ne peut pas être retiré de la file sans risquer
        // d'écraser le travail en cours : on demande son arrêt coopératif.
        $requested = $this->db->execute(
            "UPDATE jobs
                SET cancel_requested = 1
              WHERE id = % AND status = 'processing'",
            [$jobId],
        );

        return $requested > 0;
    }

    /** Indique s'il reste au moins un job dû, sans charger de ligne. */
    public function hasDueWork(): bool
    {
        return $this->db->selectOneOrNull(
            "SELECT 1 AS due FROM jobs WHERE status = 'pending' AND available_at <= now() LIMIT 1",
        ) !== null;
    }

    private function jobType(string $type): JobType
    {
        /** @var JobTypeRegistry $registry */
        $registry = $this->getSharedExtensionsClass(JobTypeRegistry::class);
        $jobType = $registry->get($type);

        if ($jobType === null) {
            throw new TechException(sprintf(
                'Type de job inconnu : "%s". Aucune extension ne l’a enregistré.',
                $type,
            ));
        }

        return $jobType;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{
     *     space_id: int|null,
     *     priority: int,
     *     available_at: string|null,
     *     dedup_key: string|null,
     *     principal: string,
     *     exec_user_id: int|null,
     *     producer: string,
     *     max_attempts: int,
     *     created_by: int|null
     * }
     */
    private function normaliseOptions(JobType $jobType, array $options): array
    {
        $spaceId = $this->optionalPositiveInt($options['space_id'] ?? null, 'space_id');
        $createdBy = $this->optionalPositiveInt($options['created_by'] ?? null, 'created_by');
        $execUserId = $this->optionalPositiveInt($options['exec_user_id'] ?? null, 'exec_user_id');

        $priority = $options['priority'] ?? self::PRIORITY_DEFAULT;

        if (!is_int($priority) || $priority < self::PRIORITY_MIN || $priority > self::PRIORITY_MAX) {
            throw new TechException(sprintf(
                'Priorité de job invalide : elle doit être un entier entre %d et %d.',
                self::PRIORITY_MIN,
                self::PRIORITY_MAX,
            ));
        }

        $principal = $options['principal'] ?? self::PRINCIPAL_NONE;

        if (!in_array($principal, [self::PRINCIPAL_NONE, self::PRINCIPAL_SYSTEM, self::PRINCIPAL_USER], true)) {
            throw new TechException(sprintf('Principal de job invalide : "%s".', (string) $principal));
        }

        // L'utilisateur d'exécution n'a de sens qu'avec le principal `user`, et
        // il vient d'un contexte serveur : la file ne peut pas vérifier son
        // origine, le contrat le réserve aux producteurs de confiance.
        if ($principal === self::PRINCIPAL_USER && $execUserId === null) {
            throw new TechException('Le principal "user" exige un exec_user_id.');
        }

        if ($principal !== self::PRINCIPAL_USER && $execUserId !== null) {
            throw new TechException('exec_user_id n’est admis qu’avec le principal "user".');
        }

        $producer = $options['producer'] ?? $jobType->producer();

        if (!is_string($producer) || preg_match('/\A[a-z][a-z0-9_]{1,39}\z/', $producer) !== 1) {
            throw new TechException(sprintf('Producteur de job invalide : "%s".', (string) $producer));
        }

        $maxAttempts = $options['max_attempts'] ?? $jobType->maxAttempts;

        if (!is_int($maxAttempts) || $maxAttempts < 0 || $maxAttempts > JobType::MAX_ATTEMPTS_MAX) {
            throw new TechException(sprintf(
                'max_attempts invalide : entier attendu entre 0 et %d.',
                JobType::MAX_ATTEMPTS_MAX,
            ));
        }

        $dedupKey = $options['dedup_key'] ?? null;

        if ($dedupKey !== null) {
            $dedupKey = is_string($dedupKey) ? trim($dedupKey) : '';

            if ($dedupKey === '' || strlen($dedupKey) > 120) {
                throw new TechException('dedup_key invalide : chaîne non vide de 120 caractères au plus.');
            }
        }

        return [
            'space_id' => $spaceId,
            'priority' => $priority,
            'available_at' => $this->normaliseDate($options['available_at'] ?? null),
            'dedup_key' => $dedupKey,
            'principal' => $principal,
            'exec_user_id' => $execUserId,
            'producer' => $producer,
            'max_attempts' => $maxAttempts,
            'created_by' => $createdBy,
        ];
    }

    private function optionalPositiveInt(mixed $value, string $name): ?int
    {
        if ($value === null) {
            return null;
        }

        if (!is_int($value) || $value <= 0) {
            throw new TechException(sprintf('%s doit être un identifiant entier positif.', $name));
        }

        return $value;
    }

    private function normaliseDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTools::toSql($value);
        }

        if (!is_string($value) || trim($value) === '') {
            throw new TechException('available_at doit être une date ou une chaîne de date.');
        }

        $sql = DateTools::parseToSql($value);

        if ($sql === null) {
            throw new TechException('available_at n’est pas une date valide.');
        }

        return $sql;
    }
}
