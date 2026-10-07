<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Picks the right AiFileProcessorInterface for a MIME type - the only
 * place that needs to know the full list of processors (doc S6: adding a
 * new format later means adding one processor here, not rewriting the
 * engine or branching in a controller). Returns null for an unsupported
 * type rather than throwing - the caller decides how to degrade (doc
 * S18).
 *
 * Phase 2 adds MarkdownFileProcessor (split out of TextFileProcessor,
 * which now only claims text/plain), HtmlFileProcessor, JsonFileProcessor,
 * and XmlFileProcessor. Resolution order matters only in that each
 * processor's own `supports()` is exact-MIME-type matching (doc S31:
 * "resolution should use MIME... not trust extension alone"), so there is
 * no overlap between any two processors in this list.
 *
 * Phase 3 moves `text/csv`/`text/tab-separated-values` out of
 * ExcelFileProcessor (which now only claims XLS/XLSX) into their own
 * dedicated CsvFileProcessor/TsvFileProcessor (doc S4).
 *
 * Phase 4 adds PptxFileProcessor (PPTX) and PptFileProcessor (legacy
 * PPT, extends PptxFileProcessor - same object model, different reader).
 *
 * Phase 5 adds RasterImageFileProcessor (JPG/JPEG/PNG/WEBP/GIF/BMP, plus
 * TIFF when Imagick happens to be available) and SvgImageFileProcessor
 * (SVG, handled as safety-scanned XML rather than a raster format - see
 * its own docblock).
 *
 * Phase 6 adds AudioFileProcessor (MP3/WAV/M4A/AAC/OGG/OPUS/FLAC, plus
 * audio-only WebM - see its own docblock for why `video/webm` is in its
 * supported-MIME list).
 *
 * Phase 7 adds VideoFileProcessor (MP4/MOV/WEBM/AVI). It is registered
 * BEFORE AudioFileProcessor deliberately: `video/webm` is claimed by
 * BOTH (the same genuine container ambiguity Phase 6 already found -
 * see AudioFileProcessor's docblock), and resolution order is the
 * mechanism that routes it correctly - VideoFileProcessor inspects the
 * real stream content first and internally DELEGATES to the injected
 * AudioFileProcessor for the audio-only case (see its own docblock),
 * so AudioFileProcessor's own `supports('video/webm')` staying true is
 * required for that delegation call to succeed, even though the
 * manager itself now never reaches AudioFileProcessor for that MIME.
 */
class AiFileProcessorManager
{
    /** @var list<AiFileProcessorInterface> */
    protected array $processors;

    public function __construct(
        PdfFileProcessor $pdf,
        WordFileProcessor $word,
        ExcelFileProcessor $excel,
        CsvFileProcessor $csv,
        TsvFileProcessor $tsv,
        TextFileProcessor $text,
        MarkdownFileProcessor $markdown,
        HtmlFileProcessor $html,
        JsonFileProcessor $json,
        XmlFileProcessor $xml,
        PptxFileProcessor $pptx,
        PptFileProcessor $ppt,
        RasterImageFileProcessor $rasterImage,
        SvgImageFileProcessor $svgImage,
        VideoFileProcessor $video,
        AudioFileProcessor $audio,
    ) {
        $this->processors = [$pdf, $word, $excel, $csv, $tsv, $text, $markdown, $html, $json, $xml, $pptx, $ppt, $rasterImage, $svgImage, $video, $audio];
    }

    public function for(string $mimeType): ?AiFileProcessorInterface
    {
        foreach ($this->processors as $processor) {
            if ($processor->supports($mimeType)) {
                return $processor;
            }
        }

        return null;
    }

    public function supports(string $mimeType): bool
    {
        return $this->for($mimeType) !== null;
    }
}
