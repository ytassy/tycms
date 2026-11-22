<?php

namespace YTassy\Tycms\Lib\HtmlSanitizer;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitizes the HTML produced by a WYSIWYG editor before it is stored or rendered.
 *
 * Inline styles are limited to editor colors, font sizes and line heights. Image sources are limited
 * to relative URLs; videos are limited to site media URLs. YouTube and Vimeo
 * embeds are restricted to their official player URLs. Legacy table
 * presentation attributes are limited to their relevant table elements.
 */
final class HtmlSanitizer
{
    private const EMPTY_MEDIA_ELEMENT_PATTERN = '~<img(?![^>]*\\ssrc=")[^>]*\\s*/>|<iframe(?![^>]*\\ssrc=")[^>]*></iframe>|<video(?![^>]*\\ssrc=")[^>]*></video>~';

    private static ?SymfonyHtmlSanitizer $wysiwygSanitizer = null;

    /**
     * Keeps the small, presentation-oriented HTML subset supported by the framework.
     */
    public static function sanitize(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }

        $sanitizedHtml = self::getWysiwygSanitizer()->sanitize($html);

        return preg_replace(self::EMPTY_MEDIA_ELEMENT_PATTERN, '', $sanitizedHtml) ?? $sanitizedHtml;
    }

    private static function getWysiwygSanitizer(): SymfonyHtmlSanitizer
    {
        return self::$wysiwygSanitizer ??= new SymfonyHtmlSanitizer(self::createWysiwygConfig());
    }

    private static function createWysiwygConfig(): HtmlSanitizerConfig
    {
        $config = new HtmlSanitizerConfig();

        foreach ([
            'p', 'div', 'span', 'article', 'aside', 'section',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'strong', 'b', 'em', 'i', 'u', 's', 'del',
            'small', 'sub', 'sup', 'mark',
            'blockquote', 'pre', 'code', 'figure', 'figcaption',
            'ul', 'ol', 'li', 'dl', 'dt', 'dd',
            'caption', 'thead', 'tbody', 'tfoot',
        ] as $element) {
            $config = $config->allowElement($element, ['class']);
        }

        $config = $config
            ->allowElement('br')
            ->allowElement('hr')
            ->allowElement('a', ['class', 'href', 'title', 'target'])
            ->allowElement('img', ['alt', 'class', 'height', 'src', 'width'])
            ->allowElement('video', ['controls', 'src'])
            ->allowElement('iframe', ['height', 'src', 'title', 'width'])
            ->allowElement('ol', ['class', 'start', 'reversed'])
            ->allowElement('table', ['class', 'align', 'border', 'cellpadding', 'cellspacing', 'height', 'summary', 'width'])
            ->allowElement('colgroup', ['class', 'span', 'width'])
            ->allowElement('col', ['class', 'span', 'width'])
            ->allowElement('tr', ['class', 'align', 'height', 'valign'])
            ->allowElement('th', ['class', 'align', 'colspan', 'height', 'rowspan', 'scope', 'valign', 'width'])
            ->allowElement('td', ['class', 'align', 'colspan', 'height', 'rowspan', 'valign', 'width'])
            ->allowLinkSchemes(['https', 'mailto'])
            ->allowRelativeLinks()
            ->forceHttpsUrls()
            ->allowMediaSchemes(['https'])
            ->allowMediaHosts([
                'www.youtube-nocookie.com', 'www.youtube.com', 'youtube.com', 'm.youtube.com', 'youtu.be',
                'player.vimeo.com', 'vimeo.com', 'www.vimeo.com',
            ])
            ->allowRelativeMedias()
            ->allowAttribute('style', ['span', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'li', 'td', 'th', 'div', 'figure', 'tr', 'ul', 'ol', 'pre', 'img'])
            ->allowAttribute('dir', ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ul', 'ol', 'li', 'td', 'th', 'article', 'aside', 'section'])
            ->allowAttribute('id', ['a'])
            ->allowAttribute('title', ['a', 'img'])
            ->withAttributeSanitizer(new WysiwygMediaSourceAttributeSanitizer())
            ->withAttributeSanitizer(new WysiwygInlineStyleAttributeSanitizer())
            ->withAttributeSanitizer(new WysiwygTextAttributeSanitizer())
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->forceAttribute('iframe', 'allowfullscreen', '')
            ->forceAttribute('iframe', 'loading', 'lazy')
            ->forceAttribute('iframe', 'referrerpolicy', 'strict-origin-when-cross-origin')
            ->forceAttribute('iframe', 'title', 'Lecteur vidéo intégré');

        return $config;
    }
}
