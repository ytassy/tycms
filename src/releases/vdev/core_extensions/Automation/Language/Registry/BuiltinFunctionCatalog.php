<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Language\Registry;

/**
 * Signatures des fonctions intégrées du langage.
 *
 * Ce catalogue est volontairement statique en v1. Le registre dynamique des
 * extensions prendra le relais sans changer l'interface FunctionCatalog.
 */
final class BuiltinFunctionCatalog implements FunctionCatalog
{
    /** @var array<string, FunctionSignature> */
    private array $functions = [];

    /** @param array<string, FunctionSignature> $functions */
    public function __construct(array $functions = [])
    {
        $this->functions = $functions;
    }

    public static function create(): self
    {
        $definitions = [
            // str
            'str::length' => [1, 1],
            'str::byteLength' => [1, 1],
            'str::lower' => [1, 1],
            'str::upper' => [1, 1],
            'str::trim' => [1, 2],
            'str::trimLeft' => [1, 2],
            'str::trimRight' => [1, 2],
            'str::startsWith' => [2, 2],
            'str::endsWith' => [2, 2],
            'str::contains' => [2, 2],
            'str::indexOf' => [2, 2],
            'str::compare' => [2, 2],
            'str::replace' => [3, 3],
            'str::split' => [2, 2],
            'str::join' => [2, 2],
            'str::substr' => [2, 3],
            'str::repeat' => [2, 2],
            'str::padLeft' => [2, 3],
            'str::padRight' => [2, 3],
            'str::truncate' => [2, 3],
            'str::capitalize' => [1, 1],
            'str::slug' => [1, 1],
            'str::isBlank' => [1, 1],
            'str::escapeHtml' => [1, 1],
            'str::escapeAttr' => [1, 1],

            // arr
            'arr::length' => [1, 1],
            'arr::isEmpty' => [1, 1],
            'arr::keys' => [1, 1],
            'arr::values' => [1, 1],
            'arr::has' => [2, 2],
            'arr::get' => [2, 3],
            'arr::set' => [3, 3],
            'arr::remove' => [2, 2],
            'arr::push' => [2, 2],
            'arr::prepend' => [2, 2],
            'arr::concat' => [2, 2],
            'arr::merge' => [2, 2],
            'arr::slice' => [2, 3],
            'arr::first' => [1, 1],
            'arr::last' => [1, 1],
            'arr::contains' => [2, 2],
            'arr::indexOf' => [2, 2],
            'arr::unique' => [1, 1],
            'arr::reverse' => [1, 1],
            'arr::column' => [2, 2],
            'arr::sortBy' => [2, 3],
            'arr::fromPairs' => [1, 1],
            'arr::toPairs' => [1, 1],
            'arr::range' => [2, 3],

            // math
            'math::abs' => [1, 1],
            'math::min' => [1, null],
            'math::max' => [1, null],
            'math::round' => [1, 2],
            'math::floor' => [1, 1],
            'math::ceil' => [1, 1],
            'math::clamp' => [3, 3],
            'math::sum' => [1, 1],
            'math::avg' => [1, 1],
            'math::pow' => [2, 2],
            'math::sqrt' => [1, 1],
            'math::sign' => [1, 1],
            'math::randomInt' => [2, 2, FunctionEffect::READ],
            'math::randomFloat' => [0, 0, FunctionEffect::READ],

            // date
            'date::now' => [0, 0, FunctionEffect::READ],
            'date::today' => [0, 0, FunctionEffect::READ],
            'date::parse' => [1, 2],
            'date::format' => [2, 3],
            'date::toIso' => [1, 1],
            'date::toTimestamp' => [1, 1],
            'date::fromTimestamp' => [1, 2],
            'date::addSeconds' => [2, 2],
            'date::addMinutes' => [2, 2],
            'date::addHours' => [2, 2],
            'date::addDays' => [2, 2],
            'date::addWeeks' => [2, 2],
            'date::addMonths' => [2, 2],
            'date::addYears' => [2, 2],
            'date::startOfDay' => [1, 1],
            'date::endOfDay' => [1, 1],
            'date::startOfWeek' => [1, 1],
            'date::startOfMonth' => [1, 1],
            'date::endOfMonth' => [1, 1],
            'date::startOfYear' => [1, 1],
            'date::endOfYear' => [1, 1],
            'date::diffInSeconds' => [2, 2],
            'date::diffInDays' => [2, 2],
            'date::isBefore' => [2, 2],
            'date::isAfter' => [2, 2],
            'date::isSameDay' => [2, 2],
            'date::year' => [1, 1],
            'date::month' => [1, 1],
            'date::day' => [1, 1],
            'date::hour' => [1, 1],
            'date::minute' => [1, 1],
            'date::second' => [1, 1],
            'date::weekday' => [1, 1],
            'date::setTimezone' => [2, 2],
            'date::timezone' => [1, 1],

            // json
            'json::encode' => [1, 2],
            'json::decode' => [1, 2],
            'json::isValid' => [1, 1],

            // type
            'type::of' => [1, 1],
            'type::isNull' => [1, 1],
            'type::isBool' => [1, 1],
            'type::isInt' => [1, 1],
            'type::isFloat' => [1, 1],
            'type::isNumber' => [1, 1],
            'type::isString' => [1, 1],
            'type::isArray' => [1, 1],
            'type::isDate' => [1, 1],
            'type::toString' => [1, 1],
            'type::toInt' => [1, 1],
            'type::toFloat' => [1, 1],
            'type::toBool' => [1, 1],

            // util
            'util::log' => [1, 2, FunctionEffect::WRITE],
            'util::uuid' => [0, 0, FunctionEffect::READ],

            // res
            'res::confirm' => [1, 1],
            'res::info' => [1, 1],
            'res::warning' => [1, 1],
            'res::danger' => [1, 1],
            'res::error' => [1, 1],

            // Actions Core. Ces signatures de secours servent uniquement à la
            // validation statique sans registre d'actions. Dès que le registre
            // existe, ses définitions priment (AutomationFunctionCatalog).
            // Les maintenir ici reste nécessaire : un éditeur ou un test qui
            // valide sans application doit reconnaître ces identifiants.
            'core::sendMail' => [3, 3, FunctionEffect::EXTERNAL],
            'core::sendMailTemplate' => [2, 3, FunctionEffect::EXTERNAL],
            'core::notify' => [1, 3, FunctionEffect::EXTERNAL],
            'core::sendTelegram' => [1, 3, FunctionEffect::EXTERNAL],
            'core::sendSlack' => [1, 3, FunctionEffect::EXTERNAL],
            'core::sendDiscord' => [1, 3, FunctionEffect::EXTERNAL],
            'core::getUser' => [1, 1, FunctionEffect::READ],
            'core::listUsers' => [0, 4, FunctionEffect::READ],
            'core::addRole' => [3, 3, FunctionEffect::WRITE],
            'core::removeRole' => [3, 3, FunctionEffect::WRITE],
            'core::addToGroup' => [2, 2, FunctionEffect::WRITE],
            'core::removeFromGroup' => [2, 2, FunctionEffect::WRITE],
        ];

        $functions = [];

        foreach ($definitions as $identifier => $definition) {
            $functions[$identifier] = new FunctionSignature(
                $identifier,
                $definition[0],
                $definition[1],
                $definition[2] ?? FunctionEffect::PURE,
            );
        }

        return new self($functions);
    }

    public function find(string $identifier): ?FunctionSignature
    {
        return $this->functions[$identifier] ?? null;
    }

    /** @return array<string, FunctionSignature> */
    public function all(): array
    {
        return $this->functions;
    }
}
