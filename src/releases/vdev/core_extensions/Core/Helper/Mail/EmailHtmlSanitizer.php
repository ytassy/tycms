<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Mail;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Profil HTML commun aux e-mails.
 *
 * Il est plus strict que le profil des pages : les clients de messagerie
 * n'exécutent pas les scripts, et une iframe, une vidéo ou un formulaire n'a
 * pas sa place dans un message. Les images restent limitées aux médias
 * relatifs du site et les liens à HTTPS ou mailto.
 */
final class EmailHtmlSanitizer
{
    private static ?HtmlSanitizer $sanitizer = null;

    public static function sanitize(string $html): string
    {
        return self::getSanitizer()->sanitize($html);
    }

    private static function getSanitizer(): HtmlSanitizer
    {
        if (self::$sanitizer instanceof HtmlSanitizer) {
            return self::$sanitizer;
        }

        $config = (new HtmlSanitizerConfig())
            ->allowElement('p')->allowElement('div')->allowElement('span')
            ->allowElement('h1')->allowElement('h2')->allowElement('h3')->allowElement('h4')
            ->allowElement('strong')->allowElement('b')->allowElement('em')->allowElement('i')
            ->allowElement('br')->allowElement('hr')->allowElement('ul')->allowElement('ol')
            ->allowElement('li')->allowElement('table')->allowElement('tbody')
            ->allowElement('thead')->allowElement('tr')->allowElement('td')
            ->allowElement('th')->allowElement('a', ['href', 'title'])
            ->allowElement('img', ['src', 'alt'])
            ->allowLinkSchemes(['https', 'mailto'])
            ->allowRelativeMedias()
            ->forceHttpsUrls();

        return self::$sanitizer = new HtmlSanitizer($config);
    }
}