<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Event;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Occurrence d'un événement métier.
 *
 * L'objet porte le type, le Space réel, l'acteur, l'objet concerné, la charge
 * utile bornée et l'audience résolue. Il est construit par `EventEmitter`, qui
 * résout l'audience et affecte ensuite `logId`, puis, si la définition en
 * déclare un, le chemin interne calculé par le callable `link`.
 */
final class Event
{
    /** Profondeur maximale de la charge utile, niveau racine compris. */
    public const DATA_MAX_DEPTH = 4;

    /** Nombre maximal d'entrées d'un tableau de la charge utile. */
    public const DATA_MAX_ITEMS = 100;

    /** Longueur maximale d'une chaîne de la charge utile. */
    public const DATA_MAX_STRING_LENGTH = 500;

    /** Taille maximale du JSON encodé, alignée sur la colonne `data`. */
    public const DATA_MAX_BYTES = 65535;

    public const URL_PATH_MAX_LENGTH = 500;

    /**
     * Clés refusées : un événement ne transporte ni secret ni identifiant de
     * session. Le contrôle porte sur le nom de clé, jamais sur la valeur.
     */
    private const SENSITIVE_KEY_PATTERN = '/(password|passwd|secret|token|cookie|authorization|api[_-]?key|private[_-]?key|session)/i';

    public readonly string $type;

    public readonly \DateTimeImmutable $occurredAt;

    /** @var list<int> */
    public readonly array $recipientUserIds;

    /** Identifiant de la ligne `event_log`, affecté par l'écouteur de journal. */
    public ?int $logId = null;

    /** Chemin interne calculé par l'émetteur, ou null. */
    public ?string $urlPath = null;

    /**
     * @param array<string, mixed> $data
     * @param list<int> $recipientUserIds
     */
    public function __construct(
        public readonly EventDefinition $definition,
        public readonly int $spaceId,
        public readonly array $data,
        array $recipientUserIds,
        public readonly ?int $actorId = null,
        public readonly EventActorType $actorType = EventActorType::USER,
        public readonly ?string $subjectType = null,
        public readonly ?int $subjectId = null,
        ?string $urlPath = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        $this->type = $definition->id;

        // Journaux et notifications raisonnent en UTC ; les fuseaux d'affichage
        // restent la responsabilité de l'interface.
        $this->occurredAt = ($occurredAt ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));

        $this->assertSpace();
        $this->assertActor();
        $this->assertSubject();
        self::assertData($data);

        $this->recipientUserIds = self::normaliseRecipients($recipientUserIds);
        $this->setUrlPath($urlPath);
    }

    /**
     * Affecte le chemin interne. L'émetteur l'appelle une seule fois, après le
     * calcul du callable de la définition.
     */
    public function setUrlPath(?string $urlPath): void
    {
        $this->urlPath = self::assertUrlPath($urlPath);
    }

    private function assertSpace(): void
    {
        if ($this->spaceId <= 0) {
            throw new InvalidArgumentException(sprintf(
                'L’événement "%s" exige un Space réel positif.',
                $this->type,
            ));
        }
    }

    private function assertActor(): void
    {
        if ($this->actorId !== null && $this->actorId <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Acteur invalide pour l’événement "%s".',
                $this->type,
            ));
        }

        // Un acteur identifié contredit un type sans compte.
        if ($this->actorId !== null && $this->actorType !== EventActorType::USER) {
            throw new InvalidArgumentException(sprintf(
                'L’événement "%s" porte un acteur identifié avec le type "%s".',
                $this->type,
                $this->actorType->value,
            ));
        }
    }

    private function assertSubject(): void
    {
        if ($this->subjectId !== null && $this->subjectType === null) {
            throw new InvalidArgumentException(sprintf(
                'L’événement "%s" porte un identifiant d’objet sans type.',
                $this->type,
            ));
        }

        if ($this->subjectId !== null && $this->subjectId <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Identifiant d’objet invalide pour l’événement "%s".',
                $this->type,
            ));
        }

        if ($this->subjectType !== null
            && (strlen($this->subjectType) > 100
                || preg_match('/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*\z/', $this->subjectType) !== 1)) {
            throw new InvalidArgumentException(sprintf(
                'Type d’objet invalide pour l’événement "%s" : "%s".',
                $this->type,
                $this->subjectType,
            ));
        }
    }

    /**
     * La charge utile est une map bornée de scalaires, listes et sous-maps.
     * Aucun objet PHP, ressource ou structure récursive n'est accepté.
     *
     * @param array<string, mixed> $data
     */
    private static function assertData(array $data): void
    {
        if ($data !== [] && (array_is_list($data) || !self::hasOnlyStringKeys($data))) {
            throw new InvalidArgumentException('La charge utile d’un événement doit être une map à clés textuelles.');
        }

        self::assertValue($data, 1);

        try {
            $encoded = json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new InvalidArgumentException('La charge utile d’un événement n’est pas encodable en JSON.', 0, $exception);
        }

        if (strlen($encoded) > self::DATA_MAX_BYTES) {
            throw new InvalidArgumentException('La charge utile d’un événement dépasse la taille autorisée.');
        }
    }

    /** @param array<array-key, mixed> $value */
    private static function hasOnlyStringKeys(array $value): bool
    {
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                return false;
            }
        }

        return true;
    }

    private static function assertValue(mixed $value, int $depth): void
    {
        if ($depth > self::DATA_MAX_DEPTH) {
            throw new InvalidArgumentException('La charge utile d’un événement est trop profonde.');
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return;
        }

        if (is_string($value)) {
            if (mb_strlen($value) > self::DATA_MAX_STRING_LENGTH) {
                throw new InvalidArgumentException('Une chaîne de la charge utile est trop longue.');
            }

            return;
        }

        if (!is_array($value)) {
            throw new InvalidArgumentException('La charge utile d’un événement ne peut contenir que des scalaires et des tableaux.');
        }

        if (count($value) > self::DATA_MAX_ITEMS) {
            throw new InvalidArgumentException('Un tableau de la charge utile contient trop d’entrées.');
        }

        foreach ($value as $key => $child) {
            if (is_string($key)) {
                self::assertKey($key);
            }

            self::assertValue($child, $depth + 1);
        }
    }

    private static function assertKey(string $key): void
    {
        if ($key === '' || mb_strlen($key) > 100) {
            throw new InvalidArgumentException('Clé invalide dans la charge utile d’un événement.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            throw new InvalidArgumentException('Une clé de la charge utile contient un caractère de contrôle.');
        }

        if (preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1) {
            throw new InvalidArgumentException(sprintf(
                'La clé "%s" est refusée dans la charge utile d’un événement.',
                $key,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $userIds
     * @return list<int>
     */
    private static function normaliseRecipients(array $userIds): array
    {
        if (!array_is_list($userIds)) {
            throw new InvalidArgumentException('L’audience d’un événement doit former une liste d’identifiants.');
        }

        $result = [];
        foreach ($userIds as $userId) {
            if (!is_int($userId) || $userId <= 0) {
                throw new InvalidArgumentException('Chaque destinataire d’un événement doit être un identifiant positif.');
            }

            $result[$userId] = true;
        }

        return array_keys($result);
    }

    /**
     * Le chemin est relatif au site : pas de schéma, pas d'hôte, pas de double
     * slash, pas d'antislash, pas de caractère de contrôle, pas de remontée.
     */
    private static function assertUrlPath(?string $urlPath): ?string
    {
        if ($urlPath === null) {
            return null;
        }

        if ($urlPath === '' || strlen($urlPath) > self::URL_PATH_MAX_LENGTH) {
            throw new InvalidArgumentException('Chemin de notification invalide : longueur incorrecte.');
        }

        if (preg_match('/[\x00-\x1F\x7F]/', $urlPath) === 1 || str_contains($urlPath, '\\')) {
            throw new InvalidArgumentException('Chemin de notification invalide : caractère interdit.');
        }

        if (preg_match('/\A[a-zA-Z][a-zA-Z0-9+.\-]*:/', $urlPath) === 1
            || str_starts_with($urlPath, '//')
            || str_contains($urlPath, '//')) {
            throw new InvalidArgumentException('Chemin de notification invalide : URL absolue ou double slash.');
        }

        foreach (explode('/', $urlPath) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Chemin de notification invalide : remontée de dossier.');
            }
        }

        return $urlPath;
    }
}
