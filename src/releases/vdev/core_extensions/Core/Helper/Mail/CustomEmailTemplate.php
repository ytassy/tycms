<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateDefinition;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateVariable;
use YTassy\Tycms\Exceptions\ApplicationException;

/**
 * Valide les données d'un modèle d'e-mail créé depuis l'administration.
 *
 * Les modèles du code sont déclarés par les extensions avec leurs propres
 * variables. Un modèle personnalisé ne déclare que des variables texte,
 * obligatoires à l'envoi. Les contrôles restent ici, sans accès SQL ni ACL,
 * afin d'être utilisables avant toute écriture.
 */
final class CustomEmailTemplate
{
    /** Préfixe réservé ; suit la convention `<propriétaire>__<identifiant>`. */
    public const KEY_PREFIX = 'custom__';
    public const MAX_VARIABLES = 20;
    public const MAX_EXAMPLE_LENGTH = 200;

    public static function label(mixed $value): string
    {
        $label = is_string($value) ? trim($value) : '';

        // Le libellé sert uniquement à l'administration, jamais au contenu de l'e-mail.
        if ($label === '' || !mb_check_encoding($label, 'UTF-8')
            || mb_strlen($label, 'UTF-8') > 150
            || preg_match('/[\x00-\x1f\x7f]/', $label) === 1) {
            throw new ApplicationException(
                'Le libellé du modèle est obligatoire, sans caractère de contrôle et limité à 150 caractères.',
            );
        }

        return $label;
    }

    public static function description(mixed $value): string
    {
        $description = is_string($value) ? trim($value) : '';

        if (!mb_check_encoding($description, 'UTF-8') || mb_strlen($description, 'UTF-8') > 500) {
            throw new ApplicationException('La description du modèle est limitée à 500 caractères.');
        }

        return $description;
    }

    public static function renderMode(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['default', 'blank'], true)) {
            throw new ApplicationException('La mise en page du modèle est invalide.');
        }

        return $value;
    }

    /**
     * Convertit les variables saisies en définitions contrôlées.
     *
     * @return list<EmailTemplateVariable>
     */
    public static function variables(mixed $value): array
    {
        // Le validateur de schéma peut normaliser les objets JSON imbriqués en stdClass.
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (!is_array($value) || count($value) > self::MAX_VARIABLES) {
            throw new ApplicationException('Un modèle peut déclarer au plus 20 variables.');
        }

        $variables = [];
        foreach (array_values($value) as $item) {
            if (is_object($item)) {
                $item = get_object_vars($item);
            }
            if (!is_array($item) || !is_string($item['name'] ?? null)) {
                throw new ApplicationException('Chaque variable doit avoir un nom.');
            }

            $name = trim($item['name']);
            if (preg_match('/\A[a-z][a-z0-9_]{0,49}\z/', $name) !== 1 || isset($variables[$name])) {
                throw new ApplicationException(
                    'Le nom d’une variable doit commencer par une lettre minuscule et ne contenir '
                    . 'que des minuscules, des chiffres ou des soulignés, sans doublon.',
                );
            }

            $example = $item['example'] ?? '';
            if (!is_string($example) || !mb_check_encoding($example, 'UTF-8')
                || mb_strlen($example, 'UTF-8') > self::MAX_EXAMPLE_LENGTH) {
                throw new ApplicationException('L’exemple d’une variable est limité à 200 caractères.');
            }

            // Type texte uniquement : un lien variable exigerait un type URL contrôlé au rendu.
            $variables[$name] = new EmailTemplateVariable($name, 'text', true, trim($example));
        }

        return array_values($variables);
    }

    /** @param list<EmailTemplateVariable> $variables */
    public static function variableMap(array $variables): array
    {
        return array_column($variables, null, 'name');
    }

    /** @param list<EmailTemplateVariable> $variables */
    public static function encodeVariables(array $variables): string
    {
        return json_encode(
            array_map(
                static fn(EmailTemplateVariable $variable): array => [
                    'name' => $variable->name,
                    'example' => $variable->example,
                ],
                $variables,
            ),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );
    }

    /** @param object{label:string, description:string, render_mode:string, variables:string} $row */
    public static function definition(object $row): EmailTemplateDefinition
    {
        $variables = json_decode((string) $row->variables, true);
        if (!is_array($variables)) {
            throw new ApplicationException('Les variables de ce modèle personnalisé sont invalides.');
        }

        return new EmailTemplateDefinition(
            id: 'custom',
            label: (string) $row->label,
            description: (string) $row->description,
            customizable: true,
            renderMode: (string) $row->render_mode,
            variants: [],
            variables: self::variables($variables),
        );
    }

    /** Produit un identifiant ASCII stable à partir du libellé. */
    public static function slug(string $label): string
    {
        // Les accents français sont translittérés sans dépendre de la locale système.
        $source = strtr(mb_strtolower($label, 'UTF-8'), [
            'à' => 'a', 'â' => 'a', 'ä' => 'a', 'á' => 'a', 'ã' => 'a', 'å' => 'a',
            'ç' => 'c', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
            'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y',
            'œ' => 'oe', 'æ' => 'ae', 'ß' => 'ss',
        ]);

        // Repli pour les autres alphabets latins ; les caractères restants sont séparateurs.
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $source);
            if ($converted !== false) {
                $source = $converted;
            }
        }

        $slug = trim(mb_substr((string) preg_replace('/[^a-z0-9]+/', '_', $source), 0, 80, 'UTF-8'), '_');
        if ($slug === '') {
            return 'modele';
        }

        // L'identifiant doit commencer par une lettre, comme les clés du registre.
        return preg_match('/\A[a-z]/', $slug) === 1 ? $slug : 'm_' . $slug;
    }
}
