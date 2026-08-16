<?php

namespace App\Core\Notifications;

/**
 * Turns the markdown body of an email into readable plain text.
 *
 * Laravel renders the same markdown view for both MIME parts, so without this
 * the text/plain alternative still carries `#`, `**` and `[text](url)` syntax.
 * Applied by resources/views/vendor/mail/text/layout.blade.php.
 */
class PlainTextMail
{
    public static function format(string $body): string
    {
        // [text](url) -> "text (url)", or just the URL when they are the same.
        $body = (string) preg_replace_callback(
            '/\[([^\]]*)\]\(([^)\s]+)\)/',
            function (array $matches): string {
                $text = trim($matches[1]);
                $url = $matches[2];

                return ($text === '' || $text === $url) ? $url : $text.' ('.$url.')';
            },
            $body,
        );

        // Heading markers: "## Title" -> "Title".
        $body = (string) preg_replace('/^ {0,3}#{1,6}[ \t]+/m', '', $body);

        // Emphasis markers around a span of text: **bold** / __bold__.
        $body = (string) preg_replace('/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/s', '$2', $body);

        // Blockquote markers used by markdown panels.
        $body = (string) preg_replace('/^ {0,3}>[ \t]?/m', '', $body);

        return trim($body);
    }
}
