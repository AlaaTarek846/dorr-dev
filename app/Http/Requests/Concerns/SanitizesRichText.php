<?php

namespace App\Http\Requests\Concerns;

/**
 * Allow-list sanitiser for rich-text translation fields.
 *
 * Rich-text editors post HTML, so translation values are scrubbed before
 * validation and persistence:
 *   - tags outside the allow-list are removed
 *   - inline event handlers (onclick, onerror, ...) are dropped
 *   - href/src are limited to safe protocols
 *   - style attributes are reduced to `text-align`
 *   - visually empty markup (`<p></p>`, `<p><br></p>`) collapses to an
 *     empty string so `required` validation still works
 */
trait SanitizesRichText
{
    protected function richTextAllowedTags(): string
    {
        return '<p><br><strong><b><em><i><u><s><strike><del><ins><mark><small>'
            .'<sub><sup><code><pre><blockquote><ul><ol><li>'
            .'<h2><h3><h4><h5><h6><a><img><hr><span><div>'
            .'<table><caption><thead><tbody><tfoot><tr><th><td>'
            .'<figure><figcaption>';
    }

    /**
     * Sanitise the given fields on every `translations.*` entry.
     *
     * @param  array<int, string>  $fields
     */
    protected function sanitizeRichTranslations(array $fields): void
    {
        $translations = $this->input('translations');

        if (! is_array($translations)) {
            return;
        }

        $sanitized = [];

        foreach ($translations as $key => $translation) {
            if (! is_array($translation)) {
                $sanitized[$key] = $translation;

                continue;
            }

            foreach ($fields as $field) {
                if (array_key_exists($field, $translation)) {
                    $translation[$field] = $this->sanitizeRichText((string) $translation[$field]);
                }
            }

            $sanitized[$key] = $translation;
        }

        $this->merge(['translations' => $sanitized]);
    }

    protected function sanitizeRichText(string $value): string
    {
        $clean = strip_tags($value, $this->richTextAllowedTags());
        $clean = $this->stripEventAttributes($clean);
        $clean = $this->scrubStyleAttributes($clean);
        $clean = $this->scrubUrlAttributes($clean);
        $clean = trim($clean);

        return $this->isVisuallyEmpty($clean) ? '' : $clean;
    }

    protected function stripEventAttributes(string $html): string
    {
        return (string) preg_replace(
            '/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '',
            $html
        );
    }

    /**
     * Keep `text-align` only, dropping any other inline style payload.
     */
    protected function scrubStyleAttributes(string $html): string
    {
        return (string) preg_replace_callback(
            '/\sstyle\s*=\s*("([^"]*)"|\'([^\']*)\')/i',
            function (array $matches): string {
                $style = $matches[2] ?? '';

                if ($style === '' && isset($matches[3])) {
                    $style = $matches[3];
                }

                if (preg_match('/text-align\s*:\s*(left|right|center|justify)/i', $style, $align)) {
                    return ' style="text-align:'.strtolower($align[1]).'"';
                }

                return '';
            },
            $html
        );
    }

    protected function scrubUrlAttributes(string $html): string
    {
        return (string) preg_replace_callback(
            '/\s(href|src)\s*=\s*("([^"]*)"|\'([^\']*)\')/i',
            function (array $matches): string {
                $url = $matches[3] ?? '';

                if ($url === '' && isset($matches[4])) {
                    $url = $matches[4];
                }

                $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

                if ($url !== '' && ! $this->isSafeRichTextUrl($url)) {
                    $url = '';
                }

                return ' '.$matches[1].'="'.e($url).'"';
            },
            $html
        );
    }

    protected function isSafeRichTextUrl(string $url): bool
    {
        $normalized = preg_replace('/[\s\x00-\x1F]+/u', '', $url) ?? $url;

        return (bool) preg_match('#^(https?://|mailto:|tel:|/)#i', $normalized);
    }

    protected function isVisuallyEmpty(string $html): bool
    {
        $text = preg_replace('/<[^>]*>/', '', $html) ?? '';
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\xc2\xa0", ' ', $text)) === '';
    }
}
