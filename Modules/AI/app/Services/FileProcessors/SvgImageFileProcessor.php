<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Phase 5 (doc S7): SVG is XML, not a raster image - this processor
 * treats it purely as untrusted markup to be SAFETY-SCANNED, never as
 * something to decode pixels from. It deliberately reuses Phase 2's
 * XmlFileProcessor XXE-rejection technique (same regex pre-check, same
 * DOMDocument + LIBXML_NONET loading) rather than inventing a second XML
 * security approach for one more format.
 *
 * Doc S7 explicitly allows "if safe rasterization is not available,
 * return a controlled capability/processing warning instead of
 * pretending." GD genuinely cannot decode SVG on any PHP build (it is
 * not a raster format - it has no pixels to decode until something
 * executes its drawing instructions), so this processor NEVER attempts
 * rasterization and never produces a preview/thumbnail for SVG - that
 * is the honest capability boundary this phase reports, not a bug to
 * work around.
 *
 * Security scan covers exactly what doc S7 calls out:
 * - `<script>` elements (JS execution).
 * - `on*` event-handler attributes (onclick, onload, etc. - inline JS).
 * - External resource references (`href`/`xlink:href` pointing at an
 *   `http://`/`https://` URL) - doc S7's SSRF/external-resource-loading
 *   concern; a same-document `#fragment` reference is not external and
 *   is left alone.
 * - XXE/entity-expansion, via the same pre-parse rejection XmlFileProcessor
 *   already uses.
 *
 * This processor NEVER rewrites, sanitizes, or otherwise modifies the
 * uploaded file - like every other processor in this module, it is
 * read-only analysis. A "sanitized SVG" output is explicitly out of
 * scope (doc S7 asks for detection/classification, not auto-remediation).
 */
class SvgImageFileProcessor implements AiFileProcessorInterface
{
    protected const SUPPORTED = ['image/svg+xml'];

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
            return AiFileProcessingResult::failed('IMAGE_INVALID');
        }

        if (preg_match('/<!ENTITY|<!DOCTYPE[^>]*\[/i', $raw) === 1) {
            // Same XXE/entity-expansion rejection as XmlFileProcessor -
            // an SVG has no legitimate use for custom entities either.
            return AiFileProcessingResult::failed('IMAGE_SVG_UNSAFE');
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $loaded = $dom->loadXML($raw, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        if (! $loaded || $dom->documentElement === null) {
            return AiFileProcessingResult::failed('IMAGE_INVALID');
        }

        if (strtolower($dom->documentElement->localName ?: $dom->documentElement->nodeName) !== 'svg') {
            return AiFileProcessingResult::failed('IMAGE_INVALID');
        }

        $findings = [
            'has_script' => false,
            'has_event_handlers' => false,
            'has_external_references' => false,
            'has_embedded_html' => false,
        ];
        $externalRefs = [];
        $eventAttributes = [];

        $this->scan($dom->documentElement, $findings, $externalRefs, $eventAttributes);

        $unsafe = $findings['has_script'] || $findings['has_event_handlers'] || $findings['has_external_references'] || $findings['has_embedded_html'];

        $width = $this->attributeAsFloat($dom->documentElement, 'width');
        $height = $this->attributeAsFloat($dom->documentElement, 'height');
        $viewBox = $dom->documentElement->getAttribute('viewBox') ?: null;

        $metadata = [
            'format' => 'svg',
            'mime_type' => 'image/svg+xml',
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => ($width && $height && $height > 0) ? round($width / $height, 4) : null,
            'view_box' => $viewBox,
            'animated' => false,
            'frame_count' => 1,
            'security' => [
                'safe' => ! $unsafe,
                'has_script' => $findings['has_script'],
                'has_event_handlers' => $findings['has_event_handlers'],
                'has_external_references' => $findings['has_external_references'],
                'has_embedded_html' => $findings['has_embedded_html'],
                'external_references' => array_values(array_unique($externalRefs)),
                'event_attributes_found' => array_values(array_unique($eventAttributes)),
            ],
            // Doc S7: never rasterized - GD cannot decode SVG, and no
            // alternative renderer is used, so no preview_assets key is
            // ever produced for this processor (see docblock).
            'preview_available' => false,
            'preview_unavailable_reason' => 'SVG_NOT_RASTERIZABLE',
        ];

        $warnings = ['IMAGE_SVG_NOT_RASTERIZED'];

        if ($unsafe) {
            // Doc S7: an unsafe SVG is still successfully "processed" -
            // the point is to classify and report it, not to treat
            // detection itself as a failure. The capability/processing
            // warning the doc asks for is this flag plus the IMAGE_SVG_UNSAFE
            // warning string, surfaced in metadata.security AND warnings
            // so a caller can act on either.
            $warnings[] = 'IMAGE_SVG_UNSAFE';
        }

        return AiFileProcessingResult::ok(
            text: null,
            metadata: $metadata,
            warnings: $warnings,
            documentType: 'image',
        );
    }

    /**
     * @param  array<string, bool>  $findings
     * @param  list<string>  $externalRefs
     * @param  list<string>  $eventAttributes
     */
    protected function scan(\DOMElement $node, array &$findings, array &$externalRefs, array &$eventAttributes): void
    {
        $localName = strtolower($node->localName ?: $node->nodeName);

        if ($localName === 'script') {
            $findings['has_script'] = true;
        }

        if ($localName === 'foreignobject') {
            // Doc S7: SVG's own mechanism for embedding arbitrary HTML
            // (foreignObject can contain full XHTML, which can itself
            // contain <script>/event handlers) - flagged regardless of
            // what's actually inside it, since HTML inside foreignObject
            // is attacker-controlled markup this scan does not also
            // parse as HTML.
            $findings['has_embedded_html'] = true;
        }

        if ($node->hasAttributes()) {
            foreach ($node->attributes as $attribute) {
                $name = strtolower($attribute->name);
                $value = trim($attribute->value);

                if (str_starts_with($name, 'on')) {
                    $findings['has_event_handlers'] = true;
                    $eventAttributes[] = $attribute->name;

                    continue;
                }

                if (in_array($name, ['href', 'xlink:href'], true) && preg_match('/^https?:\/\//i', $value) === 1) {
                    $findings['has_external_references'] = true;
                    $externalRefs[] = $value;
                }
            }
        }

        foreach ($node->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $this->scan($child, $findings, $externalRefs, $eventAttributes);
            }
        }
    }

    protected function attributeAsFloat(\DOMElement $node, string $name): ?float
    {
        $value = $node->getAttribute($name);

        if ($value === '') {
            return null;
        }

        // Strips a trailing unit (px, pt, mm, %...) - doc only needs a
        // numeric dimension, not full CSS-length-unit conversion.
        if (preg_match('/^(-?[0-9]*\.?[0-9]+)/', $value, $m) === 1) {
            return (float) $m[1];
        }

        return null;
    }
}
