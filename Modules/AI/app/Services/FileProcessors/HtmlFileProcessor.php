<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Phase 2: uploaded HTML is treated as untrusted data (doc S18/S19) -
 * parsed with PHP's built-in DOMDocument/libxml (no new dependency:
 * ext-dom ships with PHP), never executed, never rendered as-is.
 * `<script>`/`<style>`/`<iframe>`/`<object>`/`<embed>`/`<noscript>` are
 * removed outright, every remaining element's `on*` event-handler
 * attributes are stripped, and `javascript:`/`data:` URLs in
 * `href`/`src` are stripped - so even if this normalized output were
 * ever echoed into a browser later, there is nothing left to execute.
 * `LIBXML_NONET` additionally stops libxml from following any network
 * reference while parsing.
 */
class HtmlFileProcessor implements AiFileProcessorInterface
{
    protected const SUPPORTED = ['text/html'];

    protected const STRIPPED_TAGS = ['script', 'style', 'iframe', 'object', 'embed', 'noscript'];

    protected const SKIPPED_STRUCTURAL_TAGS = ['nav', 'footer', 'aside'];

    public function supports(string $mimeType): bool
    {
        return in_array($mimeType, self::SUPPORTED, true);
    }

    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult
    {
        if (! $this->supports($mimeType)) {
            return AiFileProcessingResult::failed('unsupported_mime_type');
        }

        $raw = @file_get_contents($absolutePath);

        if ($raw === false) {
            return AiFileProcessingResult::failed('could_not_read_file');
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $raw);
        $raw = is_string($clean) ? $clean : $raw;

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadHTML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded) {
            return AiFileProcessingResult::failed('HTML_PARSE_FAILED');
        }

        $this->sanitize($dom);

        $titleNode = $dom->getElementsByTagName('title')->item(0);
        $title = $titleNode ? trim($titleNode->textContent) : '';

        $root = $dom->getElementsByTagName('body')->item(0) ?? $dom->documentElement;
        $blocks = [];

        if ($root !== null) {
            $this->walk($root, $blocks);
        }

        $text = trim(implode("\n", array_map(fn ($b) => $this->blockToText($b), $blocks)));

        if ($text === '') {
            return AiFileProcessingResult::failed('no_text_found');
        }

        return AiFileProcessingResult::ok(
            text: $text,
            metadata: ['title' => $title !== '' ? $title : null],
            blocks: $blocks,
            documentType: 'html',
        );
    }

    protected function sanitize(\DOMDocument $dom): void
    {
        foreach (self::STRIPPED_TAGS as $tag) {
            foreach (iterator_to_array($dom->getElementsByTagName($tag)) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $xpath = new \DOMXPath($dom);
        /** @var \DOMElement $element */
        foreach ($xpath->query('//*[@*]') ?: [] as $element) {
            foreach (iterator_to_array($element->attributes ?? []) as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim((string) $attribute->value);

                $isEventHandler = str_starts_with($name, 'on');
                $isDangerousUrl = in_array($name, ['href', 'src', 'action'], true)
                    && preg_match('/^\s*(javascript|data|vbscript):/i', $value);

                if ($isEventHandler || $isDangerousUrl) {
                    $element->removeAttribute($attribute->name);
                }
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function walk(\DOMNode $node, array &$blocks): void
    {
        foreach ($node->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            $tag = strtolower($child->nodeName);

            if (in_array($tag, self::SKIPPED_STRUCTURAL_TAGS, true)) {
                continue;
            }

            if (preg_match('/^h([1-6])$/', $tag, $m)) {
                $text = trim($child->textContent);

                if ($text !== '') {
                    $blocks[] = ['type' => 'heading', 'level' => (int) $m[1], 'text' => $text];
                }
            } elseif ($tag === 'p') {
                $text = trim($child->textContent);

                if ($text !== '') {
                    $blocks[] = ['type' => 'paragraph', 'text' => $text];
                }
            } elseif ($tag === 'ul' || $tag === 'ol') {
                $items = [];

                foreach ($child->getElementsByTagName('li') as $li) {
                    $itemText = trim($li->textContent);

                    if ($itemText !== '') {
                        $items[] = $itemText;
                    }
                }

                if ($items !== []) {
                    $blocks[] = ['type' => 'list', 'items' => $items];
                }
            } elseif ($tag === 'table') {
                $this->extractTable($child, $blocks);
            } elseif ($tag === 'a') {
                $linkText = trim($child->textContent);
                $href = $child instanceof \DOMElement ? $child->getAttribute('href') : '';

                if ($linkText !== '') {
                    $blocks[] = ['type' => 'link', 'text' => $linkText, 'url' => $href];
                }
            } elseif ($tag === 'blockquote') {
                $text = trim($child->textContent);

                if ($text !== '') {
                    $blocks[] = ['type' => 'quote', 'text' => $text];
                }
            } elseif ($tag === 'pre' || $tag === 'code') {
                $text = $child->textContent;

                if (trim($text) !== '') {
                    $blocks[] = ['type' => 'code', 'text' => $text];
                }
            } else {
                $this->walk($child, $blocks);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function extractTable(\DOMNode $table, array &$blocks): void
    {
        $rows = [];

        foreach ($table->getElementsByTagName('tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $cell) {
                if ($cell->nodeType !== XML_ELEMENT_NODE) {
                    continue;
                }

                if (in_array(strtolower($cell->nodeName), ['td', 'th'], true)) {
                    $cells[] = trim($cell->textContent);
                }
            }

            if ($cells !== []) {
                $rows[] = $cells;
            }
        }

        if ($rows === []) {
            return;
        }

        $blocks[] = ['type' => 'table', 'headers' => $rows[0], 'rows' => array_slice($rows, 1)];
    }

    protected function blockToText(array $block): string
    {
        return match ($block['type']) {
            'heading' => str_repeat('#', (int) $block['level']).' '.$block['text'],
            'paragraph', 'quote', 'link', 'code' => $block['text'],
            'list' => implode("\n", array_map(fn ($i) => '- '.$i, $block['items'])),
            'table' => implode("\n", array_map(
                fn ($row) => implode(' | ', $row),
                [$block['headers'], ...$block['rows']],
            )),
            default => '',
        };
    }
}
