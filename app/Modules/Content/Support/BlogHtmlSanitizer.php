<?php

namespace App\Modules\Content\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Allowlist sanitiser for post bodies coming out of the rich-text editor.
 *
 * Authors are trusted platform admins, but the output is rendered to every
 * visitor on the marketing site, so a compromised or careless admin must not
 * be able to plant stored XSS. Anything not on this list is stripped.
 */
class BlogHtmlSanitizer
{
    private HtmlSanitizer $sanitizer;

    public function __construct()
    {
        $config = (new HtmlSanitizerConfig)
            ->allowElement('p')
            ->allowElement('br')
            ->allowElement('strong')
            ->allowElement('b')
            ->allowElement('em')
            ->allowElement('i')
            ->allowElement('u')
            ->allowElement('s')
            ->allowElement('h2')
            ->allowElement('h3')
            ->allowElement('h4')
            ->allowElement('ul')
            ->allowElement('ol')
            ->allowElement('li')
            ->allowElement('blockquote')
            ->allowElement('code')
            ->allowElement('pre')
            ->allowElement('hr')
            ->allowElement('a', ['href', 'title', 'target', 'rel'])
            ->allowElement('img', ['src', 'alt', 'width', 'height'])
            // javascript:/vbscript: hrefs are the classic stored-XSS vector.
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->allowMediaSchemes(['https', 'http'])
            // Outbound links from our own domain should not leak window.opener.
            ->forceAttribute('a', 'rel', 'noopener noreferrer')
            ->dropElement('script')
            ->dropElement('style')
            ->dropElement('iframe')
            ->dropElement('form');

        $this->sanitizer = new HtmlSanitizer($config);
    }

    public function sanitize(string $html): string
    {
        return trim($this->sanitizer->sanitize($html));
    }

    /**
     * Plain-text projection of the body, used to derive excerpts and to check
     * that a post actually has content after sanitising.
     */
    public static function toPlainText(string $html): string
    {
        $text = preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '';

        return trim(html_entity_decode($text, ENT_QUOTES, 'UTF-8'));
    }
}
