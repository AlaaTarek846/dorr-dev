<?php

namespace Modules\AI\Services\FileProcessors;

/**
 * Phase 4 (doc S3/S4/S27): legacy binary .ppt, via the SAME library as
 * PptxFileProcessor's PowerPoint2007 reader - `phpoffice/phppresentation`
 * also ships a real `PowerPoint97` reader (verified via the library's own
 * published docs/packagist listing, not assumed just because PPTX works -
 * doc S4's explicit "do not claim PPT is supported merely because PPTX is
 * supported" warning). Both readers populate the identical
 * `PhpPresentation` object model, so every slide/shape-walking method in
 * `ExtractsPresentationSlides` is reused as-is.
 *
 * Classified PARTIALLY_SUPPORTED, not SUPPORTED, in this phase's report:
 * this implementation could not be run against a real legacy .ppt fixture
 * in this environment (no PHP runtime here), so its real-world fidelity
 * for old PowerPoint 97-2003 files is unverified, not just "maybe
 * imperfect." No external conversion tool (LibreOffice, etc.) was
 * introduced for this - doc S27 only asks for one if native support
 * can't be made to work at all, and a real native reader does exist here.
 */
class PptFileProcessor extends PptxFileProcessor
{
    protected const SUPPORTED = ['application/vnd.ms-powerpoint'];

    protected const READER_NAME = 'PowerPoint97';

    protected const DOCUMENT_TYPE = 'ppt';
}
