<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Registry;

/**
 * Documentation statique des fonctions standard du langage.
 *
 * Le catalogue d'exécution ne porte que les arités ; cette référence ajoute
 * les signatures lisibles et les descriptions destinées à l'éditeur et au
 * téléchargement. Les actions Core restent documentées par leur registre.
 */
final class BuiltinFunctionReference
{
    /**
     * namespace => nom => [signature, description]
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    private const FUNCTIONS = [
        'str' => [
            'length' => ['str::length(string $value): int', 'Longueur en caractères UTF-8.'],
            'byteLength' => ['str::byteLength(string $value): int', 'Longueur en octets.'],
            'lower' => ['str::lower(string $value): string', 'Convertit en minuscules.'],
            'upper' => ['str::upper(string $value): string', 'Convertit en majuscules.'],
            'trim' => ['str::trim(string $value, ?string $characters = null): string', 'Retire les caractères aux extrémités ; les espaces par défaut.'],
            'trimLeft' => ['str::trimLeft(string $value, ?string $characters = null): string', 'Retire les caractères au début.'],
            'trimRight' => ['str::trimRight(string $value, ?string $characters = null): string', 'Retire les caractères à la fin.'],
            'startsWith' => ['str::startsWith(string $value, string $prefix): bool', 'Vrai si la chaîne commence par le préfixe.'],
            'endsWith' => ['str::endsWith(string $value, string $suffix): bool', 'Vrai si la chaîne se termine par le suffixe.'],
            'contains' => ['str::contains(string $value, string $needle): bool', 'Vrai si la chaîne contient le fragment.'],
            'indexOf' => ['str::indexOf(string $value, string $needle): int', 'Position du fragment, ou -1 s’il est absent.'],
            'compare' => ['str::compare(string $left, string $right): int', 'Compare deux chaînes : -1, 0 ou 1.'],
            'replace' => ['str::replace(string $subject, string $search, string $replace): string', 'Remplace toutes les occurrences.'],
            'split' => ['str::split(string $subject, string $separator): array<string>', 'Découpe une chaîne en liste.'],
            'join' => ['str::join(array $items, string $separator): string', 'Réunit les éléments avec un séparateur.'],
            'substr' => ['str::substr(string $value, int $start, ?int $length = null): string', 'Extrait une portion de chaîne.'],
            'repeat' => ['str::repeat(string $value, int $times): string', 'Répète la chaîne un nombre de fois.'],
            'padLeft' => ['str::padLeft(string $value, int $length, ?string $pad = null): string', 'Complète à gauche jusqu’à la longueur demandée.'],
            'padRight' => ['str::padRight(string $value, int $length, ?string $pad = null): string', 'Complète à droite jusqu’à la longueur demandée.'],
            'truncate' => ['str::truncate(string $value, int $length, ?string $suffix = null): string', 'Tronque la chaîne avec un suffixe facultatif.'],
            'capitalize' => ['str::capitalize(string $value): string', 'Met la première lettre en majuscule.'],
            'slug' => ['str::slug(string $value): string', 'Produit un identifiant lisible pour une URL.'],
            'isBlank' => ['str::isBlank(string $value): bool', 'Vrai si la chaîne est vide ou ne contient que des espaces.'],
            'escapeHtml' => ['str::escapeHtml(string $value): string', 'Échappe le texte pour du HTML.'],
            'escapeAttr' => ['str::escapeAttr(string $value): string', 'Échappe le texte pour un attribut HTML.'],
        ],
        'arr' => [
            'length' => ['arr::length(array $items): int', 'Nombre d’éléments du tableau.'],
            'isEmpty' => ['arr::isEmpty(array $items): bool', 'Vrai si le tableau est vide.'],
            'keys' => ['arr::keys(array $items): array', 'Liste des clés.'],
            'values' => ['arr::values(array $items): array', 'Liste des valeurs.'],
            'has' => ['arr::has(array $items, string|int $key): bool', 'Vrai si la clé existe.'],
            'get' => ['arr::get(array $items, string|int $key, mixed $default = null): mixed', 'Valeur d’une clé, ou valeur par défaut.'],
            'set' => ['arr::set(array $items, string|int $key, mixed $value): array', 'Retourne un tableau avec la clé mise à jour.'],
            'remove' => ['arr::remove(array $items, string|int $key): array', 'Retourne un tableau sans la clé.'],
            'push' => ['arr::push(array $items, mixed $value): array', 'Retourne un tableau avec la valeur ajoutée à la fin.'],
            'prepend' => ['arr::prepend(array $items, mixed $value): array', 'Retourne un tableau avec la valeur ajoutée au début.'],
            'concat' => ['arr::concat(array $left, array $right): array', 'Concatène deux listes et réindexe les clés.'],
            'merge' => ['arr::merge(array $left, array $right): array', 'Fusionne deux maps ; la seconde gagne.'],
            'slice' => ['arr::slice(array $items, int $offset, ?int $length = null): array', 'Extrait une portion du tableau.'],
            'first' => ['arr::first(array $items): mixed', 'Première valeur, ou null si le tableau est vide.'],
            'last' => ['arr::last(array $items): mixed', 'Dernière valeur, ou null si le tableau est vide.'],
            'contains' => ['arr::contains(array $items, mixed $value): bool', 'Vrai si la valeur est présente.'],
            'indexOf' => ['arr::indexOf(array $items, mixed $value): int', 'Position de la valeur, ou -1 si elle est absente.'],
            'unique' => ['arr::unique(array $items): array', 'Retourne les valeurs uniques.'],
            'reverse' => ['arr::reverse(array $items): array', 'Inverse l’ordre des éléments.'],
            'column' => ['arr::column(array $list, string|int $key): array', 'Extrait une colonne d’une liste de maps.'],
            'sortBy' => ['arr::sortBy(array $list, string|int $key, ?string $direction = "asc"): array', 'Trie une liste de maps par une clé, de façon stable.'],
            'fromPairs' => ['arr::fromPairs(array $pairs): array', 'Construit une map depuis une liste de paires.'],
            'toPairs' => ['arr::toPairs(array $items): array', 'Transforme une map en liste de paires.'],
            'range' => ['arr::range(int $start, int $end, ?int $step = 1): array', 'Construit une liste de nombres.'],
        ],
        'math' => [
            'abs' => ['math::abs(int|float $value): int|float', 'Valeur absolue.'],
            'min' => ['math::min(int|float $value, int|float ...$values): int|float', 'Plus petite valeur.'],
            'max' => ['math::max(int|float $value, int|float ...$values): int|float', 'Plus grande valeur.'],
            'round' => ['math::round(int|float $value, ?int $precision = 0): int|float', 'Arrondit à la précision demandée.'],
            'floor' => ['math::floor(int|float $value): int', 'Entier inférieur ou égal.'],
            'ceil' => ['math::ceil(int|float $value): int', 'Entier supérieur ou égal.'],
            'clamp' => ['math::clamp(int|float $value, int|float $min, int|float $max): int|float', 'Borne une valeur entre un minimum et un maximum.'],
            'sum' => ['math::sum(array $values): int|float', 'Somme des nombres du tableau.'],
            'avg' => ['math::avg(array $values): int|float', 'Moyenne des nombres du tableau.'],
            'pow' => ['math::pow(int|float $base, int|float $exponent): int|float', 'Puissance.'],
            'sqrt' => ['math::sqrt(int|float $value): float', 'Racine carrée.'],
            'sign' => ['math::sign(int|float $value): int', 'Signe : -1, 0 ou 1.'],
            'randomInt' => ['math::randomInt(int $min, int $max): int', 'Entier aléatoire borné.'],
            'randomFloat' => ['math::randomFloat(): float', 'Nombre flottant aléatoire entre 0 et 1.'],
        ],
        'date' => [
            'now' => ['date::now(): date', 'Date et heure courantes dans le fuseau du Space.'],
            'today' => ['date::today(): date', 'Début du jour courant.'],
            'parse' => ['date::parse(string $value, ?string $timezone = null): date', 'Analyse une date textuelle.'],
            'format' => ['date::format(date $date, string $pattern, ?string $timezone = null): string', 'Formate une date avec un patron.'],
            'toIso' => ['date::toIso(date $date): string', 'Convertit en chaîne ISO 8601.'],
            'toTimestamp' => ['date::toTimestamp(date $date): int', 'Convertit en timestamp Unix.'],
            'fromTimestamp' => ['date::fromTimestamp(int $timestamp, ?string $timezone = null): date', 'Construit une date depuis un timestamp.'],
            'addSeconds' => ['date::addSeconds(date $date, int $seconds): date', 'Ajoute des secondes.'],
            'addMinutes' => ['date::addMinutes(date $date, int $minutes): date', 'Ajoute des minutes.'],
            'addHours' => ['date::addHours(date $date, int $hours): date', 'Ajoute des heures.'],
            'addDays' => ['date::addDays(date $date, int $days): date', 'Ajoute des jours.'],
            'addWeeks' => ['date::addWeeks(date $date, int $weeks): date', 'Ajoute des semaines.'],
            'addMonths' => ['date::addMonths(date $date, int $months): date', 'Ajoute des mois.'],
            'addYears' => ['date::addYears(date $date, int $years): date', 'Ajoute des années.'],
            'startOfDay' => ['date::startOfDay(date $date): date', 'Début du jour.'],
            'endOfDay' => ['date::endOfDay(date $date): date', 'Fin du jour.'],
            'startOfWeek' => ['date::startOfWeek(date $date): date', 'Début de la semaine.'],
            'startOfMonth' => ['date::startOfMonth(date $date): date', 'Début du mois.'],
            'endOfMonth' => ['date::endOfMonth(date $date): date', 'Fin du mois.'],
            'startOfYear' => ['date::startOfYear(date $date): date', 'Début de l’année.'],
            'endOfYear' => ['date::endOfYear(date $date): date', 'Fin de l’année.'],
            'diffInSeconds' => ['date::diffInSeconds(date $left, date $right): int', 'Différence en secondes.'],
            'diffInDays' => ['date::diffInDays(date $left, date $right): int', 'Différence en jours.'],
            'isBefore' => ['date::isBefore(date $left, date $right): bool', 'Vrai si la première date est antérieure.'],
            'isAfter' => ['date::isAfter(date $left, date $right): bool', 'Vrai si la première date est postérieure.'],
            'isSameDay' => ['date::isSameDay(date $left, date $right): bool', 'Vrai si les deux dates sont le même jour.'],
            'year' => ['date::year(date $date): int', 'Année.'],
            'month' => ['date::month(date $date): int', 'Mois.'],
            'day' => ['date::day(date $date): int', 'Jour du mois.'],
            'hour' => ['date::hour(date $date): int', 'Heure.'],
            'minute' => ['date::minute(date $date): int', 'Minute.'],
            'second' => ['date::second(date $date): int', 'Seconde.'],
            'weekday' => ['date::weekday(date $date): int', 'Jour de la semaine.'],
            'setTimezone' => ['date::setTimezone(date $date, string $timezone): date', 'Change le fuseau de la valeur.'],
            'timezone' => ['date::timezone(date $date): string', 'Fuseau de la valeur.'],
        ],
        'json' => [
            'encode' => ['json::encode(mixed $value, ?bool $pretty = false): string', 'Encode une valeur en JSON.'],
            'decode' => ['json::decode(string $value, ?int $maxDepth = 32): mixed', 'Décode du JSON en valeurs sûres.'],
            'isValid' => ['json::isValid(string $value): bool', 'Vrai si le texte est du JSON valide.'],
        ],
        'type' => [
            'of' => ['type::of(mixed $value): string', 'Nom du type de la valeur.'],
            'isNull' => ['type::isNull(mixed $value): bool', 'Vrai si la valeur est null.'],
            'isBool' => ['type::isBool(mixed $value): bool', 'Vrai si la valeur est un booléen.'],
            'isInt' => ['type::isInt(mixed $value): bool', 'Vrai si la valeur est un entier.'],
            'isFloat' => ['type::isFloat(mixed $value): bool', 'Vrai si la valeur est un flottant.'],
            'isNumber' => ['type::isNumber(mixed $value): bool', 'Vrai si la valeur est un nombre.'],
            'isString' => ['type::isString(mixed $value): bool', 'Vrai si la valeur est une chaîne.'],
            'isArray' => ['type::isArray(mixed $value): bool', 'Vrai si la valeur est un tableau.'],
            'isDate' => ['type::isDate(mixed $value): bool', 'Vrai si la valeur est une date.'],
            'toString' => ['type::toString(mixed $value): string', 'Convertit en chaîne.'],
            'toInt' => ['type::toInt(mixed $value): int', 'Convertit en entier.'],
            'toFloat' => ['type::toFloat(mixed $value): float', 'Convertit en flottant.'],
            'toBool' => ['type::toBool(mixed $value): bool', 'Convertit en booléen.'],
        ],
        'util' => [
            'log' => ['util::log(string $message, ?array $context = null): null', 'Écrit une entrée dans le journal du run.'],
            'uuid' => ['util::uuid(): string', 'Génère un identifiant UUID.'],
        ],
        'res' => [
            'confirm' => ['res::confirm(string $message): array', 'Prépare une confirmation à afficher au demandeur.'],
            'info' => ['res::info(string $message): array', 'Prépare un message d’information à afficher au demandeur.'],
            'warning' => ['res::warning(string $message): array', 'Prépare un avertissement à afficher au demandeur.'],
            'danger' => ['res::danger(string $message): array', 'Prépare un message d’alerte à afficher au demandeur.'],
            'error' => ['res::error(string $message): array', 'Prépare une erreur à afficher au demandeur.'],
        ],
    ];

    /**
     * @return list<array{namespace: string, id: string, signature: string, description: string}>
     */
    public static function all(): array
    {
        $result = [];

        foreach (self::FUNCTIONS as $namespace => $functions) {
            foreach ($functions as $name => $definition) {
                $result[] = [
                    'namespace' => $namespace,
                    'id' => $namespace . '::' . $name,
                    'signature' => $definition[0],
                    'description' => $definition[1],
                ];
            }
        }

        return $result;
    }
}
