<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateVariable;
use YTassy\Tycms\CoreExts\Core\Helper\Media\MediaHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Exceptions\ApplicationException;

/** Valide les emplacements des variables avant tout rendu de HTML administrable. */
final class EmailTemplateContent
{
    private const TOKEN = '/%([a-z][a-z0-9_]*)%/';

    /** @param array<string,EmailTemplateVariable> $variables */
    public static function validate(string $subject, string $body, array $variables): string
    {
        if (!mb_check_encoding($subject, 'UTF-8') || !mb_check_encoding($body, 'UTF-8')
            || trim($subject) === '' || strlen($subject) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $subject) === 1
            || trim($body) === '' || strlen($body) > 131072) {
            throw new ApplicationException("Objet ou contenu du modèle d'e-mail invalide.");
        }

        // L'objet accepte les variables autorisées, y compris les URL validées au rendu.
        preg_match_all(self::TOKEN, $subject, $subjectMatches);
        if (preg_match('/%[^%\s]+%/', preg_replace(self::TOKEN, '', $subject)) === 1) {
            throw new ApplicationException("Marqueur de variable invalide dans l'objet.");
        }
        foreach ($subjectMatches[1] as $name) {
            if (!isset($variables[$name]) || !$variables[$name]->allowedInSubject) {
                throw new ApplicationException("Variable d'objet inconnue ou interdite : {$name}.");
            }
        }

        preg_match_all(self::TOKEN, $body, $bodyMatches);
        if (preg_match('/%[^%\s]+%/', preg_replace(self::TOKEN, '', $body)) === 1) {
            throw new ApplicationException('Marqueur de variable invalide dans le contenu.');
        }
        foreach ($bodyMatches[1] as $name) {
            if (!isset($variables[$name])) {
                throw new ApplicationException("Variable de contenu inconnue : {$name}.");
            }
        }

        // Les secrets requis dans le corps ne peuvent être omis par une surcharge.
        foreach ($variables as $name => $variable) {
            if ($variable->requiredInBody && !in_array($name, $bodyMatches[1], true)) {
                throw new ApplicationException("Variable obligatoire absente du contenu : {$name}.");
            }
        }

        // Protéger les URL occupant un href entier pendant le nettoyage HTML.
        $sentinels = [];
        $protected = preg_replace_callback(
            '/\bhref\s*=\s*(["\'])\s*%([a-z][a-z0-9_]*)%\s*\1/i',
            static function (array $match) use ($variables, &$sentinels): string {
                $name = $match[2];
                if (!in_array($variables[$name]->type ?? null, ['url', 'https_url'], true)) {
                    throw new ApplicationException("Variable de lien invalide : {$name}.");
                }
                $sentinel = 'https://email-template-placeholder.invalid/' . bin2hex(random_bytes(16));
                $sentinels[$sentinel] = '%' . $name . '%';
                return 'href="' . $sentinel . '"';
            },
            $body,
        );

        if ($protected === null) {
            throw new ApplicationException('Contenu HTML invalide.');
        }

        $sanitized = EmailHtmlSanitizer::sanitize($protected);
        foreach ($sentinels as $sentinel => $token) {
            if (substr_count($sanitized, $sentinel) !== 1) {
                throw new ApplicationException('Un lien variable a été retiré du contenu HTML.');
            }
            $sanitized = str_replace($sentinel, $token, $sanitized);
        }

        // Un attribut éliminé par le nettoyeur ne peut pas cacher une variable.
        preg_match_all(self::TOKEN, $sanitized, $keptMatches);
        if (count($bodyMatches[1]) !== count($keptMatches[1])) {
            throw new ApplicationException('Une variable se trouve dans un élément HTML interdit.');
        }
        if (trim(strip_tags($sanitized)) === '' && !str_contains($sanitized, '<img')) {
            throw new ApplicationException('Le modèle est vide après nettoyage.');
        }

        return $sanitized;
    }

    public static function assertImages(string $body, Space $space, MediaHelper $media): void
    {
        // Le nettoyage ne conserve que src et alt ; les URL restent dans le Space propriétaire.
        preg_match_all('~<img\b[^>]*\bsrc="([^"]+)"[^>]*>~i', $body, $matches);
        foreach ($matches[1] as $url) {
            try {
                $media->assertSelectableFile($space, html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'image');
            } catch (\RuntimeException $exception) {
                throw new ApplicationException("Image du modèle d'e-mail invalide.", previous: $exception);
            }
        }

        if (substr_count(strtolower($body), '<img') !== count($matches[1])) {
            throw new ApplicationException("Image du modèle d'e-mail sans source valide.");
        }
    }

    /** @param array<string,EmailTemplateVariable> $variables @param array<string,string> $values */
    public static function render(string $subject, string $body, array $variables, array $values): array
    {
        $body = self::validate($subject, $body, $variables);
        foreach ($variables as $name => $variable) {
            if ($variable->required && (!array_key_exists($name, $values) || $values[$name] === '')) {
                throw new ApplicationException("Valeur de variable manquante : {$name}.");
            }
        }

        // Substitution unique : le contenu d'une valeur ne devient jamais un modèle.
        $replace = static function (array $match) use ($variables, $values): string {
            $name = $match[1];
            $value = $values[$name] ?? '';
            if (!is_string($value) || strlen($value) > 8192 || !mb_check_encoding($value, 'UTF-8')) {
                throw new ApplicationException("Valeur de variable invalide : {$name}.");
            }
            if (in_array($variables[$name]->type, ['url', 'https_url'], true)
                && (filter_var($value, FILTER_VALIDATE_URL) === false
                    || !in_array(parse_url($value, PHP_URL_SCHEME),
                        $variables[$name]->type === 'https_url' ? ['https'] : ['http', 'https'], true)
                    || parse_url($value, PHP_URL_USER) !== null
                    || parse_url($value, PHP_URL_PASS) !== null)) {
                throw new ApplicationException("URL de variable invalide : {$name}.");
            }
            return $value;
        };

        $renderedSubject = preg_replace_callback(self::TOKEN, $replace, $subject);
        if ($renderedSubject === null || preg_match('/[\x00-\x1f\x7f]/', $renderedSubject) === 1) {
            throw new ApplicationException("Objet d'e-mail invalide.");
        }
        $renderedBody = preg_replace_callback(
            self::TOKEN,
            static fn (array $match): string => htmlspecialchars($replace($match), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            $body,
        );

        return [$renderedSubject, $renderedBody];
    }
}
