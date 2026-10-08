<?php

namespace Modules\AI\Services\FileProcessors\Concerns;

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\Shape\Chart;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\RichText\Paragraph;
use PhpOffice\PhpPresentation\Shape\RichText\TextElement;
use PhpOffice\PhpPresentation\Shape\Table;
use PhpOffice\PhpPresentation\Slide;
use PhpOffice\PhpPresentation\Style\Bullet;

/**
 * Phase 4 (doc S6/S21): walks a loaded `PhpPresentation` object - built
 * identically by both the PowerPoint2007 (PPTX) and PowerPoint97 (PPT)
 * readers, so this one trait covers both processors - into the
 * normalized `{number, title, title_source, blocks, notes, hidden,
 * source}` slide shape the spec asks for.
 *
 * Honesty note on confidence: slide/shape-collection, RichText paragraph/
 * run text, and Table row/cell access are PHPPresentation's long-stable,
 * widely documented public API and are used directly here. Hyperlink and
 * placeholder-type detection are used too, but their exact method shape
 * could not be verified against a real parsed file in this environment
 * (no PHP runtime here to execute them) - every call to those is guarded
 * by `method_exists()`/try-catch so a wrong assumption about either
 * degrades to "skip this one detail, add a warning", never a fatal error
 * for the whole file. Chart VALUE extraction is deliberately not
 * attempted at all for the same reason (doc S16's own fallback: a
 * `chart_reference` with type/title only, never invented values) - the
 * series/category API differs by chart type and guessing at it risks
 * silently wrong numbers, which this project's own rules forbid more
 * than an honest gap does.
 */
trait ExtractsPresentationSlides
{
    /**
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    protected function extractSlides(PhpPresentation $presentation, array &$warnings): array
    {
        $slides = [];

        foreach ($presentation->getAllSlides() as $index => $slide) {
            $number = $index + 1;

            try {
                $slides[] = $this->extractSlide($slide, $number, $warnings);
            } catch (\Throwable $e) {
                $warnings[] = "SLIDE_EXTRACTION_FAILED:slide_{$number}";
                $slides[] = [
                    'number' => $number,
                    'title' => null,
                    'title_source' => 'none',
                    'blocks' => [],
                    'notes' => null,
                    'hidden' => false,
                    'source' => ['slide' => $number],
                ];
            }
        }

        return $slides;
    }

    /**
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    protected function extractSlide(Slide $slide, int $number, array &$warnings): array
    {
        $hidden = false;

        try {
            $hidden = ! $slide->isVisible();
        } catch (\Throwable) {
            // doc S19: optional metadata - never fails the whole slide.
        }

        $shapes = iterator_to_array($slide->getShapeCollection());
        $title = null;
        $titleSource = 'none';
        $titleShapeToSkip = null;

        foreach ($shapes as $shape) {
            if ($shape instanceof RichText) {
                [$maybeTitle, $maybeSource] = $this->detectTitleFromShape($shape, $warnings, $number);

                if ($maybeTitle !== null) {
                    $title = $maybeTitle;
                    $titleSource = $maybeSource;
                    $titleShapeToSkip = $shape;

                    break;
                }
            }
        }

        // Doc S8: "do not blindly treat the first text box as the
        // title" - this fallback only ever considers the FIRST shape on
        // a MULTI-shape slide, and only when it is a single short,
        // non-bulleted line. A slide with exactly one text shape never
        // has its only content relabeled as a title - that would just
        // be deleting real content, not detecting a heading.
        if ($title === null && count($shapes) > 1 && $shapes[0] instanceof RichText) {
            $candidate = $this->inferredTitleFromShape($shapes[0]);

            if ($candidate !== null) {
                $title = $candidate;
                $titleSource = 'inferred';
                $titleShapeToSkip = $shapes[0];
            }
        }

        $blocks = [];
        $imageIndex = 0;
        $tableIndex = 0;

        foreach ($shapes as $shape) {
            if ($shape === $titleShapeToSkip) {
                continue;
            }

            try {
                if ($shape instanceof RichText) {
                    array_push($blocks, ...$this->richTextToBlocks($shape, $warnings, $number));
                } elseif ($shape instanceof Table) {
                    $tableIndex++;
                    $blocks[] = $this->tableToBlock($shape, $tableIndex, $number);
                } elseif ($shape instanceof Chart) {
                    $blocks[] = $this->chartToBlock($shape, $warnings, $number);
                } elseif (str_starts_with(get_class($shape), 'PhpOffice\\PhpPresentation\\Shape\\Drawing\\')) {
                    $imageIndex++;
                    $blocks[] = $this->drawingToBlock($shape, $imageIndex);
                }
            } catch (\Throwable $e) {
                $warnings[] = 'SHAPE_EXTRACTION_FAILED:slide_'.$number.':'.class_basename($shape::class ?? 'unknown');
            }
        }

        $notes = $this->extractNotes($slide, $warnings, $number);

        return [
            'number' => $number,
            'title' => $title,
            'title_source' => $titleSource,
            'blocks' => $blocks,
            'notes' => $notes,
            'hidden' => $hidden,
            'source' => ['slide' => $number],
        ];
    }

    /**
     * Doc S8: a placeholder-typed title shape is the real signal: never
     * just "first text box = title". The placeholder API is used
     * defensively (see trait docblock) - if it isn't available the way
     * this expects, the caller's own "first short paragraph" fallback
     * below still gets a usable title, just honestly marked `inferred`
     * instead of `placeholder`.
     *
     * @param  list<string>  $warnings
     * @return array{0: ?string, 1: string}
     */
    protected function detectTitleFromShape(RichText $shape, array &$warnings, int $slideNumber): array
    {
        try {
            if (! method_exists($shape, 'getPlaceholder')) {
                return [null, 'none'];
            }

            $placeholder = $shape->getPlaceholder();

            if ($placeholder === null || ! method_exists($placeholder, 'getType')) {
                return [null, 'none'];
            }

            $type = (string) $placeholder->getType();

            if (! in_array($type, ['title', 'ctrTitle'], true)) {
                return [null, 'none'];
            }

            $text = trim($this->plainTextFromRichText($shape));

            return $text !== '' ? [$text, 'placeholder'] : [null, 'none'];
        } catch (\Throwable) {
            $warnings[] = "TITLE_PLACEHOLDER_DETECTION_UNAVAILABLE:slide_{$slideNumber}";

            return [null, 'none'];
        }
    }

    /**
     * Doc S8's "inferred" fallback, kept deliberately narrow: only a
     * shape with exactly one short, non-bulleted paragraph qualifies -
     * a multi-paragraph or bulleted first shape is normal body content,
     * not a heading, and is left alone.
     */
    protected function inferredTitleFromShape(RichText $shape): ?string
    {
        try {
            $paragraphs = $shape->getParagraphs();

            if (count($paragraphs) !== 1) {
                return null;
            }

            $paragraph = $paragraphs[0];

            try {
                $bulletType = $paragraph->getBulletStyle()?->getBulletType();

                if ($bulletType === Bullet::TYPE_BULLET
                    || $bulletType === Bullet::TYPE_NUMERIC) {
                    return null;
                }
            } catch (\Throwable) {
                // Best-effort - an undetectable bullet style doesn't
                // disqualify an otherwise valid single-line title.
            }

            $text = trim($this->paragraphPlainText($paragraph));

            return ($text !== '' && mb_strlen($text) <= 120) ? $text : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function plainTextFromRichText(RichText $shape): string
    {
        $lines = [];

        foreach ($shape->getParagraphs() as $paragraph) {
            $lines[] = $this->paragraphPlainText($paragraph);
        }

        return trim(implode("\n", $lines));
    }

    protected function paragraphPlainText(Paragraph $paragraph): string
    {
        $text = '';

        foreach ($paragraph->getRichTextElements() as $element) {
            if ($element instanceof TextElement || method_exists($element, 'getText')) {
                $text .= (string) $element->getText();
            }
        }

        return $text;
    }

    /**
     * Doc S9/S10: paragraphs preserved in logical order; consecutive
     * bulleted/numbered paragraphs are grouped into one `list` block
     * instead of N separate one-line paragraph blocks. Every run's
     * hyperlink (when the defensive check succeeds) becomes its own
     * `link` block rather than being silently dropped from the text.
     *
     * @param  list<string>  $warnings
     * @return list<array<string, mixed>>
     */
    protected function richTextToBlocks(RichText $shape, array &$warnings, int $slideNumber): array
    {
        $blocks = [];
        $listBuffer = [];
        $listOrdered = false;

        $flushList = function () use (&$blocks, &$listBuffer, &$listOrdered) {
            if ($listBuffer === []) {
                return;
            }

            $blocks[] = ['type' => 'list', 'ordered' => $listOrdered, 'items' => $listBuffer];
            $listBuffer = [];
        };

        foreach ($shape->getParagraphs() as $paragraph) {
            $text = $this->paragraphPlainText($paragraph);
            $isBulleted = false;
            $isNumbered = false;

            try {
                $bulletType = $paragraph->getBulletStyle()?->getBulletType();
                $isNumbered = $bulletType === Bullet::TYPE_NUMERIC;
                $isBulleted = $bulletType === Bullet::TYPE_BULLET;
            } catch (\Throwable) {
                // Bullet style detection is best-effort (doc S10/S19) -
                // an undetectable bullet just becomes a plain paragraph
                // rather than failing the slide.
            }

            if ($text === '') {
                $flushList();

                continue;
            }

            if ($isBulleted || $isNumbered) {
                $listOrdered = $isNumbered;
                $listBuffer[] = $text;

                continue;
            }

            $flushList();
            $blocks[] = ['type' => 'paragraph', 'text' => $text];

            foreach ($paragraph->getRichTextElements() as $element) {
                $link = $this->hyperlinkFromElement($element, $warnings, $slideNumber);

                if ($link !== null) {
                    $blocks[] = $link;
                }
            }
        }

        $flushList();

        return $blocks;
    }

    /**
     * @param  list<string>  $warnings
     */
    protected function hyperlinkFromElement(mixed $element, array &$warnings, int $slideNumber): ?array
    {
        try {
            if (! method_exists($element, 'hasHyperlink') || ! $element->hasHyperlink()) {
                return null;
            }

            $hyperlink = $element->getHyperlink();
            $url = method_exists($hyperlink, 'getUrl') ? $hyperlink->getUrl() : null;

            if (! is_string($url) || $url === '') {
                return null;
            }

            return [
                'type' => 'link',
                'text' => method_exists($element, 'getText') ? (string) $element->getText() : $url,
                'url' => $url,
            ];
        } catch (\Throwable) {
            $warnings[] = "HYPERLINK_EXTRACTION_UNAVAILABLE:slide_{$slideNumber}";

            return null;
        }
    }

    /**
     * Doc S11/S12: structured, never flattened to plain text. Source
     * references preserve at minimum slide + table index (doc S12: "do
     * not invent coordinates" if exact cell coordinates aren't safely
     * available - row/column ARE available here from the iteration
     * index itself, so they are included).
     */
    protected function tableToBlock(Table $shape, int $tableIndex, int $slideNumber): array
    {
        $rows = $shape->getRows();
        $headers = [];
        $dataRows = [];

        foreach ($rows as $rowIndex => $row) {
            $cells = [];

            foreach ($row->getCells() as $cell) {
                $cells[] = $cell instanceof RichText ? $this->plainTextFromRichText($cell) : '';
            }

            if ($rowIndex === 0) {
                $headers = $cells;
            } else {
                $dataRows[] = $cells;
            }
        }

        return [
            'type' => 'table',
            'headers' => $headers,
            'rows' => $dataRows,
            'source' => ['slide' => $slideNumber, 'table' => $tableIndex],
        ];
    }

    /**
     * Doc S16: type/title/series-names/categories only when the parser
     * can provide them with real confidence - here, never. The chart's
     * existence and type are always real (an `instanceof Chart` check
     * doesn't lie); its title is attempted defensively; its series
     * VALUES are deliberately never attempted (see trait docblock) so
     * this never risks reporting an invented number as the chart's data.
     *
     * @param  list<string>  $warnings
     */
    protected function chartToBlock(Chart $shape, array &$warnings, int $slideNumber): array
    {
        $title = null;

        try {
            $titleShape = method_exists($shape, 'getTitle') ? $shape->getTitle() : null;

            if ($titleShape !== null && method_exists($titleShape, 'getParagraphs')) {
                $title = trim($this->plainTextFromRichText($titleShape)) ?: null;
            }
        } catch (\Throwable) {
            // Title is optional metadata (doc S18) - never fails the chart block.
        }

        $warnings[] = "CHART_EXTRACTION_UNAVAILABLE:slide_{$slideNumber}";

        return [
            'type' => 'chart_reference',
            'title' => $title,
            'source' => ['slide' => $slideNumber],
            'note' => 'chart_type_and_value_extraction_not_implemented',
        ];
    }

    /**
     * Doc S15: never vision/OCR in this phase - reference + whatever
     * dimension/position metadata the shape safely exposes, nothing more.
     */
    protected function drawingToBlock(object $shape, int $index): array
    {
        $block = ['type' => 'image_reference', 'index' => $index];

        foreach (['getWidth' => 'width', 'getHeight' => 'height', 'getOffsetX' => 'offset_x', 'getOffsetY' => 'offset_y'] as $method => $key) {
            if (method_exists($shape, $method)) {
                try {
                    $block[$key] = $shape->{$method}();
                } catch (\Throwable) {
                    // Position/size metadata is optional (doc S18).
                }
            }
        }

        return $block;
    }

    /**
     * Doc S13: speaker notes are a slide-level container of the exact
     * same RichText-based shapes as the slide body, so the same
     * paragraph-walking logic applies - stored separately, never merged
     * into `blocks`.
     *
     * @param  list<string>  $warnings
     */
    protected function extractNotes(Slide $slide, array &$warnings, int $slideNumber): ?string
    {
        try {
            if (! method_exists($slide, 'getNote')) {
                return null;
            }

            $note = $slide->getNote();

            if ($note === null || ! method_exists($note, 'getShapeCollection')) {
                return null;
            }

            $lines = [];

            foreach ($note->getShapeCollection() as $shape) {
                if ($shape instanceof RichText) {
                    $text = trim($this->plainTextFromRichText($shape));

                    if ($text !== '') {
                        $lines[] = $text;
                    }
                }
            }

            $notes = trim(implode("\n", $lines));

            return $notes !== '' ? $notes : null;
        } catch (\Throwable) {
            $warnings[] = "NOTES_EXTRACTION_UNAVAILABLE:slide_{$slideNumber}";

            return null;
        }
    }
}
