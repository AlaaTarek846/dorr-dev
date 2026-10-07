<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Phase 2: XML parsed with PHP's built-in DOMDocument (no new
 * dependency). XXE/network/entity-expansion protection (doc S22):
 *
 * - Any `<!ENTITY` declaration or an internal DTD subset (`<!DOCTYPE
 *   ... [ ... ]>`) is rejected outright before the file is even handed
 *   to libxml - this project has no legitimate use for custom XML
 *   entities, so refusing them entirely is simpler and safer than trying
 *   to parse "safely" in their presence (this is also this processor's
 *   defense against "billion laughs"-style entity-expansion attacks,
 *   which rely on exactly this DTD/ENTITY mechanism).
 * - `loadXML()` is called with `LIBXML_NONET` (no network access) and
 *   deliberately WITHOUT `LIBXML_NOENT`/`LIBXML_DTDLOAD` - PHP 8's
 *   bundled libxml2 (>= 2.9) already disables external entity
 *   resolution by default, and not passing those flags keeps it that
 *   way rather than re-enabling substitution.
 */
class XmlFileProcessor implements AiFileProcessorInterface
{
    protected const SUPPORTED = ['application/xml', 'text/xml'];

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

        if (preg_match('/<!ENTITY|<!DOCTYPE[^>]*\[/i', $raw) === 1) {
            return AiFileProcessingResult::failed('XML_SECURITY_VIOLATION');
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded || $dom->documentElement === null) {
            return AiFileProcessingResult::failed('XML_INVALID');
        }

        $maxNodes = (int) config('ai.files.xml_max_nodes', 2000);
        $blocks = [];
        $truncated = false;

        $this->walk($dom->documentElement, '/'.$dom->documentElement->nodeName, $blocks, $maxNodes, $truncated);

        $pretty = $dom->saveXML() ?: $raw;
        $textTruncated = mb_strlen($pretty) > 20000;
        $text = $textTruncated
            ? mb_substr($pretty, 0, 20000)."\n".__('ai.document_truncated_note')
            : $pretty;

        return AiFileProcessingResult::ok(
            text: $text,
            metadata: [
                'root_element' => $dom->documentElement->nodeName,
                'node_count' => count($blocks),
            ],
            blocks: $blocks,
            warnings: $truncated ? ['xml_truncated_nodes'] : [],
            documentType: 'xml',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function walk(\DOMElement $node, string $path, array &$blocks, int $maxNodes, bool &$truncated): void
    {
        if (count($blocks) >= $maxNodes) {
            $truncated = true;

            return;
        }

        $elementChildren = [];

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $elementChildren[] = $child;
            }
        }

        if ($elementChildren === []) {
            $attributes = [];

            if ($node->hasAttributes()) {
                foreach ($node->attributes as $attribute) {
                    $attributes[$attribute->name] = $attribute->value;
                }
            }

            $blocks[] = [
                'type' => 'data',
                'path' => $path,
                'value' => trim($node->textContent),
                'attributes' => $attributes,
            ];

            return;
        }

        $seenCount = [];

        foreach ($elementChildren as $child) {
            if (count($blocks) >= $maxNodes) {
                $truncated = true;

                return;
            }

            $name = $child->nodeName;
            $siblingCount = count(array_filter($elementChildren, fn ($c) => $c->nodeName === $name));
            $index = $seenCount[$name] ??= 0;
            $seenCount[$name]++;

            $childPath = $siblingCount > 1 ? "{$path}/{$name}[{$index}]" : "{$path}/{$name}";
            $this->walk($child, $childPath, $blocks, $maxNodes, $truncated);
        }
    }
}
