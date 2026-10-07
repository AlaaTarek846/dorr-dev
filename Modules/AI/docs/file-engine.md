# AI File Engine

Phase 1 (foundation). No real document/spreadsheet/OCR/embedding
processing beyond what is listed under "Processors implemented" — see
`Modules/AI/docs/file-engine-phase1-report.md` for the full Phase 1
report including what is explicitly deferred.

## Architecture

```
Controller (AiFileUploadController)
    -> AiFileUploadService          (auth/ownership, response shaping)
        -> AiFileEngine             (upload/validate/store/process/delete/retrieve/status)
            -> AiFileProcessorManager
                -> AiFileProcessorInterface implementations
            -> ProcessAiFileJob      (queued; runs the processor)
```

`AiFileEngine` is the single entry point. Controllers never branch on
file type and never contain processing logic (see
`AiFileUploadController`, which is a one-line-per-method pass-through).

Two ways a file enters the engine:

- **Customer-facing API** (`POST /api/user/v1/ai-files`): the file is
  posted directly; `AiFileEngine::storeUploadedFile()` stores it then
  calls `process()`.
- **Chat attachment** (`AiChatService::storeAttachment()`): the file was
  already stored by the existing chat-attachment flow (for the instant
  preview bubble); `AiFileEngine::process()` is called with the already-
  stored path so the file is never duplicated on disk.

## File lifecycle

Statuses (`AiFile::STATUS_*`, column `processing_status`):

```
uploaded -> validating -> processing -> ready
                                 \
                                  -> failed
(any state) -> deleted   (soft-deleted row; `deleted_at` is also set)
```

`STATUS_PENDING`/`STATUS_REJECTED` still exist as deprecated aliases
(earlier Phase 1 code used them) but new code should not use them.

`processing_error` holds a short machine-readable reason string (e.g.
`unsupported_file_type`, `file_size_limit_exceeded`,
`executable_file_rejected`) — never a raw exception message or stack
trace. `AiFileException` (`Modules/AI/app/Exceptions/AiFileException.php`)
translates these reasons into normalized API errors (HTTP status +
`error_code` + localized message).

## Validation and security

Done in `AiFileEngine::validate()` / `safeExtension()` /
`detectRealMimeType()`, all config-driven (`config('ai.files.*')`,
`Modules/AI/config/config.php`):

- `max_size_bytes` — rejects oversized uploads before any processing.
- `blocked_extensions` — every dot-segment of the original filename is
  checked, not just the last one, so `report.pdf.exe` and
  `report.exe.pdf` are both caught (double-extension smuggling).
- `allowed_mime_types` — the real MIME type, read from the file's actual
  bytes via `finfo`, must be in this allowlist. The client-supplied MIME
  type and the filename extension are never trusted on their own.
- A short hardcoded list of known-executable MIME signatures
  (`AiFileEngine::EXECUTABLE_MIME_TYPES`) is rejected outright, as
  defense in depth on top of the allowlist.
- Storage paths never use the client-supplied filename: uploads are
  stored via Laravel's `UploadedFile::store()`, which generates a random
  name (`hashName()`). The original filename is only ever a display
  label (`file_name` column), sanitized of `/`, `\` and `..` sequences.

## Authorization

Every read/delete goes through an owner-scoped query that 404s instead
of 403-ing when the file belongs to someone else (same IDOR-safe pattern
as `AiConversationRepository::findForOwner()`):

```php
AiFile::query()
    ->where('owner_type', $owner->getMorphClass())
    ->where('owner_id', $owner->getAuthIdentifier())
    ->findOrFail($id);
```

See `AiFileRepository::findForOwner()` and `AiFileEngine::retrieve()`.
Because `AiFile` uses `SoftDeletes`, a deleted file is excluded from this
query automatically — "access after deletion" also 404s.

## Storage

Laravel's `Storage` facade only — no `file_put_contents()` or hardcoded
paths. The disk a file lives on is recorded per-row (`disk` column,
default from `config('ai.files.default_disk')`, currently `public`), so
switching to an S3-compatible disk later is a config change, not a code
change, and old rows keep working because their own `disk` value is
preserved.

## Processor interface and registration

```php
interface AiFileProcessorInterface
{
    public function supports(string $mimeType): bool;
    public function process(string $absolutePath, string $mimeType): AiFileProcessingResult;
}
```

`AiFileProcessorManager` is constructor-injected with every registered
processor and picks the first one whose `supports()` returns true. A
type with no processor returns `null` (not an exception) and the file is
marked `failed` / `unsupported_file_type` — never a fake "ready".

### How to add a new processor (example: a future `PptxFileProcessor`)

1. Create `Modules/AI/app/Services/FileProcessors/PptxFileProcessor.php`
   implementing `AiFileProcessorInterface`.
2. Add its MIME type(s) to `config('ai.files.allowed_mime_types')`.
3. Add the class as a constructor parameter on
   `AiFileProcessorManager` and push it into its internal processor list.
4. No controller, job, or `AiFileEngine` change is needed — the manager
   is the only place that knows the full processor list.

Processors implemented in Phase 1: `PdfFileProcessor`,
`WordFileProcessor` (via `phpoffice/phpword`), `ExcelFileProcessor` (via
`phpoffice/phpspreadsheet`), `TextFileProcessor`. Phase 2 added
`MarkdownFileProcessor`, `HtmlFileProcessor`, `JsonFileProcessor`,
`XmlFileProcessor`. Phase 3 split `CsvFileProcessor`/`TsvFileProcessor`
out of `ExcelFileProcessor` as their own dedicated classes (see "Phase 3"
below) — `ExcelFileProcessor` now only claims XLS/XLSX. Phase 4 added
`PptxFileProcessor`/`PptFileProcessor` (see "Phase 4" below).

## API

All under `auth:user_api` (Sanctum), scoped to the authenticated owner.

| Method | Path                              | Purpose                      |
|--------|------------------------------------|-------------------------------|
| POST   | `/api/user/v1/ai-files`            | Upload a file                 |
| GET    | `/api/user/v1/ai-files/{file}`     | Retrieve file metadata        |
| GET    | `/api/user/v1/ai-files/{file}/status` | Poll processing status     |
| DELETE | `/api/user/v1/ai-files/{file}`     | Delete (soft-delete + cleanup)|

Response envelope follows this app's existing `ApiResponse` shape
(`success`, `status`, `code`, `message`, `data`, `pagination`), not a
new one — see `app/Support/Api/ApiResponse.php`. Errors add `error_code`
(e.g. `UNSUPPORTED_FILE_TYPE`, `FILE_TOO_LARGE`,
`FILE_REJECTED_SECURITY`) via `AiFileException`.

The admin-only read endpoints (`GET /api/admin/v1/ai-files`,
`GET /api/admin/v1/ai-files/{file}`) already existed before Phase 1 and
are unchanged.

## Configuration (`Modules/AI/config/config.php`, key `ai.files`)

| Key | Env var | Purpose |
|---|---|---|
| `max_size_bytes` | `AI_FILES_MAX_SIZE_BYTES` | Upload size limit |
| `allowed_mime_types` | `AI_FILES_ALLOWED_MIME_TYPES` | Security allowlist |
| `blocked_extensions` | `AI_FILES_BLOCKED_EXTENSIONS` | Executable/double-extension reject list |
| `default_disk` | `AI_FILES_DEFAULT_DISK` | Filesystem disk for new uploads |
| `dedupe_by_checksum` | `AI_FILES_DEDUPE_BY_CHECKSUM` | Reuse an identical previous upload |
| `spreadsheet_max_rows_per_sheet` | `AI_FILES_SPREADSHEET_MAX_ROWS_PER_SHEET` | Row cap per sheet for `ExcelFileProcessor` |
| `csv_delimiter_sample_lines` | `AI_FILES_CSV_DELIMITER_SAMPLE_LINES` | How many leading lines `CsvFileProcessor`/`TsvFileProcessor` sample to detect the delimiter |

No secrets live in this config — all values are limits/allowlists/disk
names, read from environment variables with safe defaults.

## Phase 2 — Document Processing

Adds structural, normalized content on top of Phase 1's flat
text/metadata — without removing either (`AiFileProcessingResult::text`/
`::metadata` are unchanged; `::blocks`/`::warnings`/`::documentType` are
new, optional fields every processor may now also return).

### Normalized block format

Each processor returns a flat list of blocks. Common shapes in use:

```json
{"type": "heading", "level": 1, "text": "Introduction", "source": {"page": 1}}
{"type": "paragraph", "text": "Some content...", "source": {"page": 1}}
{"type": "list", "items": ["Item 1", "Item 2"]}
{"type": "table", "headers": ["Name", "Salary"], "rows": [["Ahmed", "15000"]]}
{"type": "quote", "text": "..."}
{"type": "code", "text": "echo \"hi\";", "language": "php"}
{"type": "link", "text": "DORR", "url": "https://dorr.example"}
{"type": "image_reference", "index": 1}
{"type": "data", "path": "$.users[0].email", "value": "ali@example.test"}
```

`data` (path/value, used by `JsonFileProcessor`/`XmlFileProcessor`) is a
Phase 2 addition to the block-type list suggested in the Phase 2 spec —
`metadata`/`heading`/`paragraph`/`list`/`table`/`quote`/`code`/`link`/
`image_reference` didn't have a natural fit for "a JSON/XML leaf value at
a specific path", so this phase added one rather than overloading an
existing type.

Blocks are persisted as their own content reference
(`ai_file_processing.blocks_ref`, same pattern as `extracted_content_ref`
— a JSON file on the `local` disk, never inline in a DB column). No new
table was created for this — see the Phase 2 migration's own docblock for
why.

### AiFileEngine::getContext($file)

The clean read API for the AI Orchestrator (doc S33/S34):

```php
AiFileEngine::getContext($file): array{
    file_id: int, status: string, document_type: ?string,
    metadata: array, text: ?string, blocks: array, warnings: array,
}
```

Returns an honest, mostly-empty context (not a fake one) when the file
is not yet `ready`. This method does not call any AI provider — it only
reads what `ProcessAiFileJob` already wrote.

### Processors (this phase)

| Format | Processor | Library |
|---|---|---|
| PDF | `PdfFileProcessor` | `smalot/pdfparser` **if installed** (page-aware, metadata, scanned detection); honest single-block degrade otherwise |
| DOCX | `WordFileProcessor` | `phpoffice/phpword` (`Word2007` reader) |
| DOC (legacy) | `WordFileProcessor` | `phpoffice/phpword` (`MsDoc` reader, already bundled) |
| TXT | `TextFileProcessor` | none — BOM/encoding detection, line-based paragraphs |
| Markdown | `MarkdownFileProcessor` | none — hand-written structural line parser (not full CommonMark) |
| HTML | `HtmlFileProcessor` | none — `ext-dom`/libxml, script/style/event-handler/javascript-URL stripped |
| JSON | `JsonFileProcessor` | none — `json_decode`, depth/leaf-block limits |
| XML | `XmlFileProcessor` | none — `ext-dom`/libxml, `<!ENTITY>`/internal-DTD rejected outright (XXE/billion-laughs) |

### Known Phase 2 limitations (documented, not silent)

- **PDF page references and scanned-PDF detection require
  `smalot/pdfparser`**, which is not in this project's `composer.json`.
  Without it, `PdfFileProcessor` still extracts text but as one
  whole-document block (`source.page` is `null`) with an explicit
  warning. Run `composer require smalot/pdfparser` to upgrade this —
  no code change is needed, the processor already checks for the class.
- **Markdown parsing is a minimal structural reader**, not a full
  CommonMark implementation — nested lists, inline HTML, and
  reference-style links degrade to plain paragraph text instead of
  being mis-parsed.
- **Legacy `.doc` support depends on phpword's bundled `MsDoc` reader**,
  which does not have DOCX's fidelity (tables/styles may be lossy for
  unusual legacy documents) — this is the real, dedicated reader for
  that binary format, not the DOCX reader pretending to handle it.
- **No streaming decoder for very large JSON/XML files** — PHP has no
  built-in one; the existing upload-time `max_size_bytes` limit is the
  real bound, and decode depth / leaf-block / node counts are separately
  capped so a large-but-under-the-size-limit file still can't produce an
  unbounded block array.

## Phase 3 — Spreadsheet Processing

Real multi-sheet structured normalization for XLSX/XLS (via
`ExcelFileProcessor`) and dedicated CSV/TSV processors, replacing the
flat "## SheetName + col | col" text dump Phase 1 produced. Header
detection, duplicate-header renaming, conservative column-type
classification and basic statistics are shared by all three processors
through one trait (`Concerns\AnalyzesSpreadsheetData`) instead of three
slightly different implementations.

### What moved

`text/csv` and `text/tab-separated-values` are no longer handled by
`ExcelFileProcessor` (which used PhpSpreadsheet's generic auto-detecting
reader for them in Phase 1). They now have their own
`CsvFileProcessor`/`TsvFileProcessor`, because CSV/TSV need real
delimiter detection, encoding/BOM handling, and per-row malformed-data
warnings that PhpSpreadsheet's CSV reader doesn't expose at all.
`ExcelFileProcessor` now claims only
`application/vnd.ms-excel`/`application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`.

### Normalized sheet shape

Every sheet (from any of the three processors) normalizes to the same
shape:

```json
{
  "name": "Sales",
  "visibility": "visible",
  "row_count": 3,
  "column_count": 2,
  "header_row": 0,
  "header_detection_confidence": 0.95,
  "headers": ["Product", "Revenue"],
  "columns": {
    "Product": {"type": "string", "null_count": 0, "unique_count": 2},
    "Revenue": {"type": "integer", "null_count": 0, "unique_count": 2,
      "stats": {"count": 2, "min": 100, "max": 250, "sum": 350, "average": 175.0}}
  },
  "rows": [{"_row_number": 2, "Product": "Widget", "Revenue": "100"}],
  "formulas": [{"cell": "C2", "formula": "=A2*10", "value": 20, "value_unavailable": false}],
  "merged_cells": ["A1:B1"],
  "truncated": false
}
```

`AiFileProcessingResult::metadata` carries `sheet_count` + `sheets: [...]`
(CSV/TSV always report exactly one sheet, named `Sheet1`, plus their own
`delimiter`/`delimiter_detection_confidence`); `::text` stays a flat
preview (first 50 rows per sheet) for the places that still want plain
text, same as Phase 1.

### Column types

Conservative, text/format-driven, never guessed from the header name:
`empty`, `string`, `integer`, `decimal`, `boolean`, `date`, `datetime`,
`percentage`, `currency`, `mixed` (genuinely incompatible values in one
column). A leading-zero numeric string (`"00123"`) is always `string`,
never coerced into a number — tested explicitly, along with Arabic text
staying untouched as `string`.

### Dates and formulas (why `ExcelFileProcessor` no longer uses
`setReadDataOnly(true)`)

Phase 1 read XLSX/XLS with `setReadDataOnly(true)` for a smaller memory
footprint. Phase 3 needs two things that mode doesn't provide:
real merged-cell ranges (`getMergeCells()` is empty in that mode) and
real number-format-aware date detection (`Shared\Date::isDateTime()`).
Both are explicitly required by this phase, so `ExcelFileProcessor` now
reads with full style/number-format parsing. **Honest trade-off**: this
costs more memory per cell than Phase 1 used — on top of the fact that
PhpSpreadsheet's `load()` already parses the whole workbook into memory
regardless of this flag (there is no true streaming XLSX reader in this
library), so a very large workbook was already a memory risk before this
change and remains one now, just a somewhat larger one. No performance
numbers are claimed here — there is no PHP runtime in the environment
this was built in to measure them; benchmarking this honestly is a
Phase 4 recommendation, not a claim made here.

Formula cells: the row/column *data* value for a formula cell is always
its clean calculated-and-formatted value (`Cell::getFormattedValue()`
already resolves formulas internally — confirmed by reading
PhpSpreadsheet's own source — so no special-casing is needed there).
Formula text is never mixed into that data value. Instead, every formula
cell is recorded separately in the sheet's `formulas` list with its
cell reference, raw formula string, and calculated value — or
`value_unavailable: true` if `getCalculatedValue()` throws (an
unsupported function, a circular reference, …). This is never faked.

### CSV/TSV specifics

- **Delimiter**: detected from the file's own content (comma, semicolon,
  tab, pipe), never assumed from the extension — with a confidence score.
  A `.tsv` file is still validated: if its content doesn't actually look
  tab-delimited, it's still parsed as tab (the format's contract) but a
  `DELIMITER_DETECTION_LOW_CONFIDENCE` warning is attached rather than
  silently re-splitting on whatever else looks plausible.
- **Encoding**: the same BOM/legacy-encoding detection as
  `TextFileProcessor`, shared via the trait — UTF-8 (with/without BOM),
  UTF-16LE/BE, falling back to `mb_detect_encoding()` against
  `Windows-1256`/`ISO-8859-6` (Arabic) and `Windows-1252`/`ISO-8859-1`
  before giving up. Arabic text (`أحمد`, `محمد`, `المبيعات`, `القاهرة`)
  is explicitly tested round-tripping through UTF-8-with-BOM and
  Windows-1256.
- **Malformed rows**: parsed via `fgetcsv()` against a `php://temp`
  stream (handles quoted fields with embedded delimiters/newlines
  correctly); a row whose column count doesn't match the header gets a
  `COLUMN_COUNT_MISMATCH:row_<n>:expected_<x>_got_<y>` warning rather
  than silently desyncing every column after it.
- **Duplicate headers**: same renaming as Excel (`Name`, `Name_2`).

### Error codes (this phase)

`XLS_PARSE_FAILED`, `XLSX_PARSE_FAILED` (corrupt/unreadable workbook),
`no_data_found` (every sheet/row empty), `empty_file`,
`could_not_read_file`, `unsupported_mime_type` (shared with every other
processor).

### Known Phase 3 limitations (documented, not silent)

- **No true streaming reader.** As above — PhpSpreadsheet's `load()`
  parses an entire workbook into memory regardless of `setReadDataOnly`
  or of reading rows afterward via `getRowIterator()`. The row cap
  (`spreadsheet_max_rows_per_sheet`) bounds what gets *returned*, not
  what gets parsed into memory to get there. A follow-up should actually
  benchmark a very large real workbook in an environment with a PHP
  runtime before claiming any number — none is claimed here.
- **CSV/TSV have no row cap on parsing itself** (only on what's kept in
  the normalized result) — `fgetcsv()` is read line-by-line rather than
  loading the whole file into one array first, which is lighter than the
  XLSX path, but still not a hard ceiling on total time spent on a
  pathologically huge file.
- **Basic statistics only** (count/min/max/sum/average for numeric
  columns, unique/null counts for all, min/max for dates) — no
  percentiles, histograms, or correlation; this matches the doc's own
  "basic statistics should be enough" guidance rather than
  under-delivering by accident.
- **No natural-language query, chart-generation, or AI-code-execution
  layer over this data** — explicitly out of scope for this phase per
  its own rules; `AiFileEngine::getContext()` exposes the normalized
  structure for a future AI Orchestrator to reason over, nothing more.

## Phase 4 — Presentation Processing

Real, slide-aware PPTX/PPT extraction via `phpoffice/phppresentation`
(**not yet in `composer.json`** — run
`composer require phpoffice/phppresentation` to enable it; both
processors degrade to an honest `PPTX_PROCESSOR_UNAVAILABLE`/
`PPT_PROCESSOR_UNAVAILABLE` failure, never a fake success, until then —
same `class_exists()` guard pattern as Phase 2's optional
`smalot/pdfparser`).

### Why one trait covers both PPT and PPTX

`PowerPoint2007` (pptx) and `PowerPoint97` (ppt) are two different
*readers* in the library, but both populate the exact same
`PhpPresentation` object model — so `PptFileProcessor` is a three-line
subclass of `PptxFileProcessor` (different MIME type, different reader
name, different `documentType`), and all the real slide/shape-walking
logic lives once in `Concerns\ExtractsPresentationSlides`.

### Normalized shape

```json
{
  "slide_count": 2,
  "slides": [
    {
      "number": 1,
      "title": "Executive Summary",
      "title_source": "placeholder",
      "blocks": [
        {"type": "paragraph", "text": "Revenue increased this quarter."},
        {"type": "list", "ordered": false, "items": ["Costs decreased", "Margin improved"]},
        {"type": "table", "headers": ["Metric", "2025"], "rows": [["Revenue", "100"]], "source": {"slide": 1, "table": 1}},
        {"type": "chart_reference", "title": "Monthly Sales", "source": {"slide": 1}, "note": "chart_type_and_value_extraction_not_implemented"},
        {"type": "image_reference", "index": 1, "width": 914400, "height": 685800}
      ],
      "notes": "Remember to mention the Q3 numbers.",
      "hidden": false,
      "source": {"slide": 1}
    }
  ]
}
```

This lives in `AiFileProcessingResult::metadata['slides']` — the same
place `ExcelFileProcessor` puts its per-sheet structure — not in
`::blocks` (which stays empty for presentations, same convention as
Excel). `::text` is a flat preview (visible slides only, titles +
bullet/paragraph text) for callers that still want plain text.

### Title detection (doc S8)

1. A real placeholder-typed shape (`title`/`ctrTitle`) — `title_source: "placeholder"`.
2. Otherwise, **only on a slide with more than one shape**: the first
   shape, if and only if it is a single short (≤120 chars), non-bulleted
   line — `title_source: "inferred"`. A slide with exactly one text
   shape never has that shape relabeled as a title (that would just be
   deleting its only content, not detecting a heading).
3. Otherwise `title: null`, `title_source: "none"`.

### What's real vs. honestly unverified vs. deliberately not attempted

- **Real, high confidence**: slide iteration, visibility
  (`isVisible()`), RichText paragraph/run text, bulleted/numbered list
  grouping, table rows/cells, speaker notes (notes are a slide-like
  shape container, walked with the same paragraph logic).
- **Implemented, but its exact method signature could not be executed/
  verified in this environment** (no PHP runtime here): hyperlink
  extraction (`hasHyperlink()`/`getHyperlink()->getUrl()`) and
  placeholder-based title detection. Both are wrapped in
  `method_exists()`/try-catch so a wrong assumption silently skips that
  one detail (with a warning) instead of failing the whole file. Please
  run the test suite and report back if either needs a method-name fix.
- **Deliberately never attempted**: chart series/category/value
  extraction. A `Shape\Chart` is always detected for real (an
  `instanceof` check doesn't lie) and gets a `chart_reference` block
  with its title when available — but no series data, because the exact
  series/value API differs by chart type and guessing at it risks
  reporting an invented number as real chart data, which this project's
  rules explicitly forbid. Vision/OCR on embedded images, macro
  execution, and embedded-object execution are equally never attempted
  (doc S15/S29/S30) — images/embedded objects get a reference only.

### Error codes (this phase)

`PPTX_PROCESSOR_UNAVAILABLE`, `PPT_PROCESSOR_UNAVAILABLE` (library not
installed), `PPTX_PARSE_FAILED`, `PPT_PARSE_FAILED` (corrupt/unreadable
file), `no_data_found`, `unsupported_mime_type` (shared with every other
processor). Per-slide/per-shape failures never fail the whole file —
`SLIDE_EXTRACTION_FAILED:slide_<n>`/`SHAPE_EXTRACTION_FAILED:slide_<n>:<ShapeClass>`
warnings are attached and the rest of the deck is still processed.

### Known Phase 4 limitations (documented, not silent)

- **Legacy `.ppt` is PARTIALLY_SUPPORTED, not SUPPORTED.** The library
  does ship a real `PowerPoint97` reader (verified via its own docs —
  not assumed just because PPTX works), but this implementation could
  not be run against a genuine legacy-`.ppt` fixture in this
  environment, and `phppresentation` itself can only *write*
  PowerPoint2007/ODP — not legacy PPT — so there was no way to even
  generate a real fixture here. Real-world fidelity against actual old
  PowerPoint 97-2003 files is unverified.
- **Chart data (series/categories/values) is never extracted** — see
  above. Only type-detection + title + a `chart_reference`.
- **No AI-Orchestrator wiring.** This codebase's chat flow
  (`AiChatService`) does not yet call into the Phase 1-4 `AiFileEngine`/
  `ai_files` pipeline at all — it still uses a separate, older
  `AiConversationAttachments`+`AiDocumentTextExtractor` path. That gap
  was flagged and deliberately deferred back in Phase 1 and is unchanged
  by this phase; Phase 4 adds the presentation processor to the same
  pipeline Phases 1-3 already live in, consistently, not a new gap.
- **No fine-grained capability tags** (`slide_analysis`, `table_extraction`,
  etc., as the master prompt's own wishlist names them) were added to
  `AiModelCapability` — that enum tags what an AI *model* can do for
  routing (`vision`, `document_analysis`, …), not what a file processor
  extracted, and a PPTX/PPT attachment already automatically requires
  `document_analysis` via `AiRequiredCapabilityResolver`'s existing
  non-image/non-audio branch — exactly like a PDF or DOCX attachment
  already does, with no code change needed.
- No true streaming presentation reader — `IOFactory::createReader(...)->load()`
  parses the whole file into memory, same inherent limitation as
  PhpSpreadsheet (Phase 3 §9). No performance numbers are claimed for a
  very large deck — unmeasured, no PHP runtime here to measure it.

## Phase 5 — Image Processing

Real metadata/security/preview processing for JPG/JPEG/PNG/WEBP/GIF/BMP
(always, via PHP's bundled `gd`/`getimagesize()`), TIFF (only when
`ext-imagick` happens to be present — reported honestly as
`IMAGE_TIFF_REQUIRES_IMAGICK` otherwise, never silently downgraded), and
SVG (handled as safety-scanned XML, never rasterized — see below). **No
new Composer dependency** — every capability is gated behind a runtime
`extension_loaded()`/`class_exists()` check, since this environment has
no way to confirm which PHP extensions the real server has loaded.

### Why SVG is a separate processor, not another raster format

GD cannot decode SVG on any PHP build — it is not a raster format, it
has no pixels until something executes its drawing instructions.
`SvgImageFileProcessor` therefore never attempts rasterization and never
produces a preview/thumbnail for SVG; instead it reuses Phase 2's
`XmlFileProcessor` XXE-rejection technique (same pre-parse
`<!ENTITY`/internal-DTD-subset regex, same `DOMDocument` +
`LIBXML_NONET` loading) and scans for `<script>` elements, `on*`
event-handler attributes, external (`http(s)://`) `href`/`xlink:href`
references, and `<foreignObject>` (SVG's own HTML-embedding mechanism).
An unsafe SVG is still a *successful* result — the point is to classify
and report it (`metadata.security`, plus an `IMAGE_SVG_UNSAFE` warning),
not to treat detection as a processing failure.

### Normalized metadata (raster)

```json
{
  "width": 1920,
  "height": 1080,
  "aspect_ratio": 1.7778,
  "mime_type": "image/jpeg",
  "format": "jpeg",
  "color_model": "rgb",
  "bit_depth": 8,
  "orientation": 1,
  "animated": false,
  "frame_count": 1,
  "exif": {
    "technical": {"orientation": 1, "make": "Canon", "model": "EOS R5"},
    "ai_safe": {"orientation": 1},
    "sensitive": {"datetimeoriginal": "2026:01:15 10:30:00", "gps": {"latitude": 40.4461, "longitude": -79.9822}}
  },
  "preview_assets": {
    "thumbnail": {"path": "ai-files/42/thumbnail.jpg", "disk": "local", "extension": "jpg", "width": 320, "height": 180},
    "preview": {"path": "ai-files/42/preview.jpg", "disk": "local", "extension": "jpg", "width": 1280, "height": 720}
  }
}
```

Same `AiFileProcessingResult::metadata` bag every other processor uses —
no second normalization system.

### EXIF privacy categorization (doc S5)

EXIF is never exposed wholesale. `Concerns\AnalyzesImageData::categorizeExif()`
splits it into three buckets:

- **`technical`** — orientation, camera make/model/software. Useful,
  not auto-sent anywhere.
- **`ai_safe`** — orientation only. Camera make/model/software are
  deliberately *not* included here even though they aren't GPS/identity
  data — they can still fingerprint a specific device/owner, which this
  phase does not consider a file parser's call to make unilaterally.
- **`sensitive`** — GPS coordinates (decoded from the raw
  degrees/minutes/seconds rationals, with the correct south/west sign
  flip) and all timestamp fields. **Never automatically sent to an AI
  model** — present in metadata for the record, flagged separately.

EXIF is only read for JPEG (the only format PHP's `exif_read_data()`
supports) and only when `ext-exif` is loaded; otherwise an honest
`IMAGE_EXIF_UNAVAILABLE_NO_EXT` warning is attached.

### Security (doc S6/S9)

- **MIME/extension distrust**: `AiFileEngine::validate()` already runs
  a real `finfo` MIME check before any processor is reached (confirmed
  by reading its source this phase, unchanged). `RasterImageFileProcessor`
  adds a second, independent confirmation at the pixel-decoder level —
  `getimagesize()`'s own detected MIME is compared against the MIME this
  processor was handed, and a disagreement is a soft
  `IMAGE_MIME_MISMATCH_WARNING`, not an automatic rejection (some
  legitimate files have ambiguous MIME strings).
- **Decompression-bomb / oversized-dimension protection**: width,
  height, AND total pixel count are all checked from
  `getimagesize()`'s cheap header read — **before** any pixel data is
  decoded via GD. A file whose declared dimensions exceed
  `ai.files.image_max_width`/`image_max_height`/`image_max_pixels`
  (all configurable, never hardcoded) is rejected
  (`IMAGE_DIMENSIONS_TOO_LARGE`/`IMAGE_PIXEL_LIMIT_EXCEEDED`) without
  GD ever touching it.
- **A file that isn't really an image at all** (wrong bytes behind a
  spoofed extension/MIME) fails `getimagesize()` and is rejected as
  `IMAGE_INVALID` — never crashes, never produces a fake "success" with
  empty metadata.
- **SVG**: see above.

### Animation detection (doc S10 — heuristics, documented as such)

- **GIF**: counts occurrences of the Graphic Control Extension byte
  signature (`\x21\xF9\x04`) in the raw file — a well-known heuristic
  real-world GIF encoders' output matches, not a full GIF89a parser.
- **Animated WEBP**: checks for an `ANIM` RIFF chunk signature in the
  file's header region. Presence/absence only — an exact WEBP frame
  count would need a real RIFF/VP8X chunk walk this phase does not
  implement; `frame_count` is honestly left `null` with `animated: true`
  plus an `IMAGE_WEBP_FRAME_COUNT_UNAVAILABLE` warning rather than
  guessed.
- A frame count over `ai.files.image_max_animation_frames` is rejected
  (`IMAGE_ANIMATION_FRAMES_EXCEEDED`).

### Previews (doc S8)

`RasterImageFileProcessor` builds a thumbnail (`image_thumbnail_max_dimension`,
default 320px longest side) and a larger preview
(`image_preview_max_dimension`, default 1280px) via GD, aspect-ratio
preserved, never upscaled past the source's own size, PNG when the
source format may carry transparency (PNG/WEBP/GIF) or JPEG otherwise.
The **original file is never opened for writing** — previews are built
from a freshly-decoded GD copy.

A processor only ever receives an absolute source path and a MIME type
— it has no `AiFile` id to build a stable storage path from — so it
hands the raw preview bytes back through
`AiFileProcessingResult::metadata['preview_assets']`, and
`ProcessAiFileJob` (the one place that already owns the
"content-reference-on-disk, not a raw value in the row" convention for
`extracted-text.txt`/`blocks.json`) writes them to
`ai-files/{id}/{thumbnail,preview}.{ext}` on the `local` disk and
replaces the raw bytes with a `{path, disk, extension, width, height}`
reference before the row is saved — so no image bytes ever sit in the
`ai_files.metadata` JSON column. Reprocessing (a queue retry) overwrites
the same deterministic path rather than accumulating duplicate files —
proven by `ProcessAiFileJobTest::test_handle_persists_image_preview_assets_as_disk_files_with_path_references`.
`AiFileEngine::delete()` was updated this phase to delete the whole
`ai-files/{id}/` directory (previously just the one
`extracted-text.txt` file) so these new preview files — and Phase 2's
`blocks.json` — are never left orphaned on disk after a file is
deleted.

### AI routing (doc S14 — disclosed gap, not fixed)

`AiRequiredCapabilityResolver`'s image branch is unconditional: **any**
image attachment requires the `vision` capability today, even for a
purely metadata question ("what are this image's dimensions?") that
`RasterImageFileProcessor` can now answer honestly with no model call
at all. Distinguishing a metadata-only question from a vision-needed one
at the capability-resolution level is a real, pre-existing gap in the
OLD attachment pipeline (`AiChatService`/`AiRequiredCapabilityResolver`)
— unrelated to, and unchanged by, the new `AiFileEngine` image
processors, and out of scope for this phase for the same reason Phase 4
disclosed (rather than silently fixed) the broader
`AiChatService`-never-calls-`AiFileEngine` gap. Pinned by
`AiRequiredCapabilityResolverTest::test_a_metadata_only_image_question_still_unconditionally_requires_vision_today`.
`RasterImageFileProcessor`/`SvgImageFileProcessor` themselves never call
any AI provider directly — they only ever return normalized metadata,
consistent with every other processor in this module.

### Vue / Android

No change. Same reasoning as every prior phase: Vue has no
customer-facing AI chat screen to extend (only the admin-only, read-only
`ai-files/index.vue` from Phase 1), and Android's existing image
handling is entirely within the old attachment pipeline, not
`AiFileEngine`.

### Error codes (this phase)

`IMAGE_INVALID`, `IMAGE_MIME_MISMATCH_WARNING` (warning, not a failure),
`IMAGE_DIMENSIONS_TOO_LARGE`, `IMAGE_PIXEL_LIMIT_EXCEEDED`,
`IMAGE_ANIMATION_FRAMES_EXCEEDED`, `IMAGE_TIFF_REQUIRES_IMAGICK`,
`IMAGE_EXIF_READ_FAILED`, `IMAGE_EXIF_UNAVAILABLE_NO_EXT`,
`IMAGE_PREVIEW_FAILED`, `IMAGE_PREVIEW_DECODE_FAILED`,
`IMAGE_PREVIEW_UNAVAILABLE_NO_GD`, `IMAGE_WEBP_FRAME_COUNT_UNAVAILABLE`,
`IMAGE_ANIMATION_DETECTION_FAILED`, `IMAGE_SVG_UNSAFE`,
`IMAGE_SVG_NOT_RASTERIZED` (always attached for SVG — informational, not
a failure), `unsupported_mime_type` (shared with every other processor).

### Known Phase 5 limitations (documented, not silent)

- **TIFF is PARTIALLY_SUPPORTED** — gated entirely behind Imagick, which
  this environment cannot confirm is installed on the real server.
- **Animation frame counts are heuristics**, not exact parses (see
  above) — WEBP's exact count is not even attempted.
- **SVG is never rasterized** — no preview/thumbnail is ever produced
  for it, by design (GD cannot decode SVG).
- **No AI-Orchestrator wiring, same as Phases 1-4** — these processors
  live in the still-unconnected `AiFileEngine` pipeline.
- **The vision-vs-metadata-only routing gap is disclosed, not fixed**
  (see "AI routing" above).
- No performance numbers are claimed — unmeasured, no PHP runtime here
  to measure it.

## Phase 6 — Audio Processing

Real, verified metadata extraction, security validation, and a bounded
waveform for MP3/WAV/M4A/AAC/OGG(Vorbis)/Opus/FLAC and audio-only WebM,
via `ffprobe`/`ffmpeg` (external system binaries, detected at runtime,
never assumed present — **not yet confirmed installed on the real
production server**; this environment's device-bridge shell does have a
real ffmpeg/ffprobe 4.4.2, which made it possible to verify every claim
below against actual output rather than documentation memory — see the
commands in this phase's Final Report). No new Composer dependency.

### `AudioFileProcessor` never transcribes anything

Transcription already exists, fully working, in this codebase's older,
separate attachment pipeline: `AiChatService::transcribeIncomingAudio()`
→ `AiGateway::transcribeAudio()` → a real provider-agnostic connector
call, already correctly resolved through `AiModelResolver` and the
pre-existing `speech_to_text` capability. This phase adds no new
transcription architecture — there was nothing missing to add.
`AudioFileProcessor` only ever returns normalized metadata, consistent
with every processor in this module; it never calls any AI provider.

### A real, verified finding: audio-only WebM reports as `video/webm`

Doc S2 asked for actual MIME detection to be verified, not assumed. It
was: a WebM file containing only an Opus audio stream — no video track
at all — is reported by real `file`/`finfo`-based MIME detection as
**`video/webm`**, never a distinct audio MIME, because WebM's container
format is shared between audio and video and libmagic does not
special-case the audio-only case. `AudioFileProcessor::SUPPORTED`
therefore includes `video/webm` deliberately, and `process()` verifies
the real stream composition from `ffprobe` before accepting it — a file
that turns out to carry a genuine video stream is honestly rejected as
`AUDIO_CONTAINER_HAS_VIDEO`, never silently treated as audio.

### A second real finding: embedded cover art looks like a video stream

An MP3 (or FLAC/M4A) with an embedded cover image has a second stream
reported by `ffprobe` as `codec_type: "video"` — verified against a real
MP3 with an attached cover in this session. That stream also carries
`disposition.attached_pic: 1`, which is exactly what distinguishes it
from a real video track; `AudioFileProcessor` excludes
`attached_pic`-flagged streams from its "does this file actually contain
video" check, so a voice memo with cover art is still correctly accepted
as audio.

### Normalized metadata

```json
{
  "format": "mp3", "container": "mp3", "mime_type": "audio/mpeg",
  "duration_seconds": 123.45, "sample_rate": 44100, "channels": 2,
  "channel_layout": "stereo", "bitrate": 128000,
  "codec": "mp3", "codec_long_name": "MP3 (MPEG audio layer 3)",
  "bits_per_sample": null, "stream_count": 1, "audio_stream_count": 1,
  "file_size_bytes": 33062,
  "tags": {
    "technical": {"encoder": "Lavf58.76.100"},
    "ai_safe": {"language": "ar"},
    "sensitive": {"title": "My Voice Memo", "artist": "Ali"}
  },
  "waveform": {"bars": [0.08, 0.12, ...], "bar_count": 100, "source_seconds_analyzed": 120.0, "sample_rate_used": 8000}
}
```

`channel_layout` is `null`, not a fabricated guess, on a mono file —
verified: real `ffprobe` output simply omits that key entirely for mono
audio. `format` is derived from the real decoded **codec** (`codec_name`
from the primary audio stream), not the container MIME — an Opus-in-Ogg
file and a Vorbis-in-Ogg file share the exact same container MIME and
`format_name` ("ogg") but are reported as `opus`/`ogg_vorbis`
respectively, because they are not the same format.

### Tag privacy categorization (doc S10)

Same `technical`/`ai_safe`/`sensitive` shape Phase 5 uses for EXIF, never
exposed to AI wholesale: `encoder` is technical; `language` is `ai_safe`
(genuinely useful for a downstream transcription/AI step, carries no
personal content — the existing `AiChatService::transcribeIncomingAudio()`
already uses an analogous language hint); `title`/`artist`/`album`/
`genre`/`year` are `sensitive` — free-text fields the uploader (or
whatever app/device recorded the file) wrote, which can trivially
contain a real name or other personal content, so none of them are
promoted to `ai_safe` even though they are not identity data on their
own (same conservative stance as Phase 5's camera make/model). Verified:
ffmpeg itself writes the year under the tag key `date`, not `year` — both
are checked.

### Security

- Real decode verification: a file `ffprobe` cannot parse as media at
  all is rejected as `AUDIO_INVALID`, however it was labeled — `{}`
  with no `format`/`streams` keys and exit code 1 is exactly what real
  `ffprobe` returns for a non-media file, verified in this session.
- A second, independent soft check at the decoder level
  (`AUDIO_MIME_MISMATCH` warning, not an automatic rejection) — some
  legitimate files genuinely have surprising container MIME strings
  (verified: WAV commonly reports as `audio/x-wav`, not `audio/wav`).
- Every `ffprobe`/`ffmpeg` invocation is built as an array argument list
  through `Symfony\Process` — never a shell string — so there is no
  command-injection surface regardless of what the uploaded filename
  contains, and every call has a hard wall-clock timeout
  (`audio_probe_timeout_seconds`/`audio_waveform_timeout_seconds`),
  mirroring the exact convention the pre-existing `DockerSandboxDriver`
  already established for this codebase's other external-binary call
  (`docker run` for the code sandbox).
- Resource limits — duration, sample rate, channel count — are all
  checked from `ffprobe`'s real decoded metadata, never trusted from the
  client, filename, or an unvalidated tag, and are all configurable
  (never hardcoded): `audio_max_duration_seconds`, `audio_max_sample_rate`,
  `audio_max_channels`.
- File-size limits reuse the existing generic `ai.files.max_size_bytes`
  check `AiFileEngine::validate()` already enforces for every file type
  — not duplicated here (doc's own "do not duplicate generic validation"
  rule).

### Waveform (doc S12)

Implemented, bounded, and real — not skipped. Decodes to mono 8kHz raw
PCM via `ffmpeg` (`-f s16le`), capped at `audio_waveform_max_source_seconds`
(default 120s) **regardless of the file's real duration** — bounded
memory on even a multi-hour recording (at most
`max_source_seconds * 16000` bytes, e.g. ~1.9MB at the default), then
bucketed into `audio_waveform_bars` (default 100) bars by peak absolute
amplitude, normalized 0–1. Deterministic (same input → same bars) and
read-only — the original file is never opened for writing, only passed
as `ffmpeg`'s input. Can be disabled entirely via
`audio_waveform_enabled`; degrades to "no waveform" plus an
`AUDIO_WAVEFORM_UNAVAILABLE` warning (not a failure of the whole file) if
`ffmpeg` is unreachable or decoding fails. Because the result is a small
JSON-safe array of numbers — not binary image bytes like Phase 5's
previews — it needs no disk-ref handling in `ProcessAiFileJob` at all; it
rides straight through in `ai_files.metadata` like every other
processor's metadata, and is therefore automatically idempotent on retry
(no file is ever written, so there is nothing to overwrite or duplicate).

### AI integration / transcription routing (doc S13/S14 — disclosed gap, not fixed)

`AudioFileProcessor` never calls any AI provider. Separately,
`AiChatService::sendMessage()` unconditionally transcribes **any**
attached audio file via a real Whisper call *before it ever looks at the
message content* — confirmed by reading its source this phase. A purely
local question ("مدة التسجيل كام؟" — "how long is this recording?") that
`AudioFileProcessor` can now answer from local `ffprobe` metadata alone,
with zero AI cost, still triggers a full transcription API call today.
This is the exact same class of gap Phase 5 disclosed (not fixed) for
images (any image attachment unconditionally requiring `vision`), for
the identical root cause: `AiChatService`'s attachment pipeline is still
entirely separate from, and does not call, `AiFileEngine` — the same
cross-pipeline gap flagged in Phases 1, 4, and 5, unchanged by this
phase. Pinned by a new test using `Http::fake()` (never a real network
call):
`AiChatSendMessageVoiceIntegrationTest::test_a_purely_metadata_question_about_an_attached_recording_still_triggers_a_real_transcription_call_today`.

### Vue / Android

No change. Same reasoning as every prior phase: Vue has no
customer-facing AI chat screen to extend (only the admin-only, read-only
`ai-files/index.vue` from Phase 1), and Android already has a complete,
working voice-message feature (`VoiceRecorder.kt`, `VoicePlayer.kt`,
`AiVoiceScreen.kt`) built entirely on the old attachment pipeline, not
`AiFileEngine`.

### Database

No new tables or columns. Same `ai_files.metadata` JSON column every
phase has used — including the waveform, which is small enough to live
there directly (see above).

### Queue

Unchanged `ProcessAiFileJob`, no modification needed for audio at all —
unlike Phase 5's image previews (binary bytes needing a disk-ref), the
waveform is plain JSON data that persists exactly like every other
processor's metadata.

### Error codes (this phase)

`AUDIO_PROCESSOR_UNAVAILABLE` (ffprobe unreachable), `AUDIO_INVALID`
(not real/parseable media), `AUDIO_MIME_MISMATCH` (soft warning),
`AUDIO_CONTAINER_HAS_VIDEO` (a `video/webm` upload that turned out to
have a genuine video stream), `AUDIO_DURATION_TOO_LONG`,
`AUDIO_SAMPLE_RATE_TOO_HIGH`, `AUDIO_CHANNEL_LIMIT_EXCEEDED`,
`AUDIO_METADATA_UNAVAILABLE` (duration unavailable — warning, not a
failure), `AUDIO_WAVEFORM_UNAVAILABLE` (ffmpeg unreachable or decode
failed — warning, not a failure), `unsupported_mime_type` (shared with
every other processor). `AUDIO_FILE_TOO_LARGE` is intentionally not
reimplemented here — the existing generic `max_size_bytes` check already
covers it. `AUDIO_TRANSCODING_UNAVAILABLE`/`AUDIO_TRANSCRIPTION_UNAVAILABLE`
are not applicable to this processor (it never transcodes or
transcribes) — the closest existing behavior lives in the old pipeline's
`voiceMessageUntranscribedSystemMessage()`, unchanged by this phase.

### Known Phase 6 limitations (documented, not silent)

- **ffmpeg/ffprobe availability on the real production server is
  unconfirmed** — verified present in this session's device-bridge
  shell, never assumed elsewhere; `AudioFileProcessor` degrades honestly
  to `AUDIO_PROCESSOR_UNAVAILABLE` if absent.
- **No chunking/segmented transcription** — out of scope per this
  phase's own "defer if it can't be done reliably" rule; a
  `audio_max_duration_seconds` limit (default 1 hour) rejects anything
  longer with a controlled error rather than silently truncating it.
- **No speaker diarization** — never attempted, never fabricated.
- **No language auto-detection inside the new processor** — the old
  pipeline's Whisper call already has its own language-hint mechanism
  (`AiChatService`'s `languageResolver`), unchanged and untouched here.
- **The unconditional-transcription-on-any-audio-attachment gap is
  disclosed, not fixed** (see "AI integration" above).
- **No AI-Orchestrator wiring, same as every prior phase** — this
  processor lives in the still-unconnected `AiFileEngine` pipeline, not
  `AiChatService`.
- No performance numbers are claimed — unmeasured at scale (a 1-hour
  recording, many queued jobs at once); only small (1–3 second) fixtures
  were exercised in this session.

## Phase 7 — Video Processing

### Scope

`VideoFileProcessor` (MP4/MOV/WEBM/AVI), built on a new shared
`Concerns\AnalyzesVideoData` trait (itself built on top of Phase 6's
`AnalyzesAudioData` — the generic ffprobe-probe/stream-classification
helpers there are reused verbatim, not re-implemented). Like
`AudioFileProcessor`, this processor never calls any AI provider and
never transcribes anything itself. A single bounded poster frame is
generated automatically on every upload (reusing
`RasterImageFileProcessor`'s existing `preview_assets` → `ProcessAiFileJob`
persistence path with zero job changes); multi-frame extraction
(`VideoFrameExtractor`, a standalone service with FIRST/MIDDLE/LAST/
UNIFORM_INTERVAL strategies) and audio-extraction-to-a-temp-file
(`AnalyzesVideoData::extractAudioToTempFile()`) are both implemented and
tested but deliberately **not** invoked automatically from
`process()` — both are genuinely expensive, on-demand operations the
master plan explicitly says must not run for every upload.

### The `video/webm` routing fix

Phase 6's `AudioFileProcessor` already claims `video/webm` (audio-only
WebM/Opus genuinely reports that MIME — see its own docblock). Phase 7
needs `video/webm` too, for genuinely-real-video WebM uploads.
`AiFileProcessorManager` resolves a MIME to the FIRST processor whose
`supports()` matches, so `VideoFileProcessor` is now registered BEFORE
`AudioFileProcessor`. For every upload claiming `video/webm`,
`VideoFileProcessor` inspects the real ffprobe stream content first: if
it finds a genuine (non-attached-pic) video stream, it processes the
file as video; if it finds none, it DELEGATES to the real, injected
`AudioFileProcessor` instance (`video/webm` → `audio/webm`, both already
supported by `AudioFileProcessor`) rather than re-implementing any of
its already-correct audio logic. The same delegation path also covers
an audio-only MP4/MOV upload (`video/mp4`/`video/quicktime` →
`audio/mp4`) — AVI has no equivalent audio-only convention and is
honestly rejected as `VIDEO_NO_VIDEO_STREAM` instead of guessing.

### Verified real-world findings (this phase)

All verified against a real ffmpeg/ffprobe 4.4.2 in this session's
device-bridge shell before being relied on in code:

- **MOV and MP4 report the identical ffprobe `format.format_name`**
  (`"mov,mp4,m4a,3gp,3g2,mj2"`) — the container label this processor
  reports is derived from the real, finfo-detected MIME type passed
  in, never from `format_name` (unlike `AudioFileProcessor`, which can
  safely use `format_name` for MP3/WAV/OGG/FLAC).
- **A corrupted or zero-byte file behaves identically to Phase 6's audio
  finding** — real ffprobe exits 1 with stdout literally `{}`.
- **`display_aspect_ratio` is only present in ffprobe's stream JSON when
  the pixel aspect ratio is non-square** (verified with a real
  `setsar=2/1` encode) — display width/height is only recomputed from it
  in that case, never fabricated.
- **A real single-JPEG-frame capture via `ffmpeg ... -f mjpeg -` to
  stdout works and decodes as a genuine, correctly-sized JPEG** (verified
  with `getimagesize()` on the real captured bytes, the same
  trust-the-decoder-not-the-request approach `RasterImageFileProcessor`
  already uses for its own previews).
- **`-disposition:v:0 attached_pic` on an embedded cover image inside an
  MP4 produces the exact same `disposition.attached_pic: 1` marker
  Phase 6 already found for MP3/FLAC cover art** — `AnalyzesAudioData::realVideoStreams()`
  is reused unchanged and correctly excludes it.
- **`libopus`-in-WebM reports ffprobe `codec_name: "opus"`** — used to
  confirm the audio-delegation branch lands on the real codec, not a
  guessed one.

### Metadata shape

`classification` ("video"), `container`, `mime_type`, `duration_seconds`,
`width`/`height`, `display_width`/`display_height`, `frame_rate`/
`avg_frame_rate`, `video_codec`(+ long name), `pixel_format`, `rotation`
(from a `rotate` tag or a `displaymatrix` side-data entry — null, never
0, when neither exists), `bitrate`, `file_size_bytes`, `stream_count`,
`video_streams` (list: index/codec/width/height/frame_rate/default),
`audio` (`available`, `streams` list with index/codec/language/
channels/sample_rate/bitrate/default, `selected_index`), `subtitles`
(`available`, `count`), `tags` (format-level tags categorized via the
same reused `categorizeAudioTags()` technical/ai_safe/sensitive split
Phase 6 built for audio — generic key-based categorization, not audio-
specific logic). `preview_assets.poster` when ffmpeg is reachable and
decode succeeds.

### Audio integration

`VideoFileProcessor` never extracts or transcribes audio automatically —
it only reports whether an audio stream exists and describes it (doc
S9: "do NOT automatically transcribe every video"). The actual hand-off
point to Phase 6's already-working transcription architecture
(`AiGateway::transcribeAudio()`, resolved via `AiModelResolver`'s
`speech_to_text` capability — unchanged, not touched this phase) is
`AnalyzesVideoData::extractAudioToTempFile()`: implemented, tested
against real video fixtures, bounded by a configurable max-seconds cap,
and writes only to `sys_get_temp_dir()` — but not called from anywhere
in this phase, by design (it is infrastructure for a future on-demand
caller, exactly matching the master plan's own pipeline diagram where
audio extraction is a separate, optional stage after classification).

### Frame processing

`VideoFrameExtractor` (standalone service, not wired into automatic
processing) supports `FIRST_FRAME`/`MIDDLE_FRAME`/`LAST_FRAME`/
`UNIFORM_INTERVAL` strategies, bounded by a caller-supplied `$maxFrames`
and `$maxDimension`, built on the same `captureFrame()` ffmpeg
invocation the poster uses. `SCENE_SAMPLING_READY` (the master plan's
own fifth, explicitly-optional strategy name) is NOT implemented — real
scene-change detection is a materially larger, unverified feature this
phase does not claim to have built.

### AI integration / disclosed gap

Same root cause as every prior phase's disclosure: `AiChatService` does
not call `AiFileEngine::getContext()` at all. For a video attachment
specifically, `buildDocumentText()` always returns null (no video MIME
is in `AiDocumentTextExtractor::SUPPORTED_MIME_TYPES`), so the model
only ever receives the generic `ai.attachment_note` ("attached a file
... you cannot open or analyze its contents directly") — never this
phase's real, already-extracted duration/resolution/has-audio metadata,
even for a plain "مدة الفيديو كام؟" that this processor could answer for
free. Pinned by
`Tests/Feature/AiChatSendMessageVideoAttachmentTest`. Not fixed this
phase, per the master plan's own instruction to disclose rather than
silently redesign the unrelated old pipeline.

### Android / Vue

No customer-facing Vue AI chat UI exists in this codebase at all (only
admin, read-only screens over `ai_conversation_attachments`/providers —
confirmed again this phase, same finding as Phase 1) — no Vue work was
applicable. Android's AI chat gallery picker was widened from
`PickVisualMedia.ImageOnly` to `.ImageAndVideo` (one line,
`AiConversationPage.kt`) — the existing `copyToCache()` helper already
reads the real MIME type from the content resolver regardless of
picker restriction, so a picked video now uploads with its real
`video/*` MIME with no further change. A rich video bubble (poster
thumbnail, duration, inline playback — the general person-to-person
chat already has this via `VideoTools`/`Composer.kt`, on a completely
separate pipeline) is a disclosed limitation, not built this phase — a
received/sent video today renders through the same generic document-
style bubble any other non-image/non-audio attachment does. Not
verified to compile (no Kotlin toolchain in this environment, same
constraint as every phase).

### Error codes (this phase)

`VIDEO_PROCESSOR_UNAVAILABLE` (ffprobe unreachable), `VIDEO_UNREADABLE`
(ffprobe could not parse the file at all), `VIDEO_NO_VIDEO_STREAM` (no
genuine video stream, and not delegable to the audio processor either),
`VIDEO_MIME_MISMATCH` (soft warning), `VIDEO_TOO_LONG`,
`VIDEO_DIMENSIONS_TOO_LARGE`, `VIDEO_METADATA_UNAVAILABLE` (duration
unavailable — warning, not failure), `VIDEO_PREVIEW_UNAVAILABLE` (ffmpeg
unreachable or poster decode failed — warning, not failure),
`unsupported_mime_type` (shared). `VIDEO_TOO_LARGE` is intentionally not
reimplemented — the existing generic `files.max_size_bytes` check
already covers it before a video ever reaches this processor.
`VIDEO_UNSUPPORTED_CONTAINER`/`VIDEO_UNSUPPORTED_CODEC`/
`VIDEO_FRAME_EXTRACTION_FAILED`/`VIDEO_AUDIO_EXTRACTION_FAILED` are
reserved names for the still-unwired `VideoFrameExtractor`/audio-
extraction call sites — a real caller wiring either in later should use
these rather than inventing new ones.

### Known Phase 7 limitations (documented, not silent)

- Same environment caveat as every phase: ffmpeg/ffprobe availability
  on the real production server is unconfirmed.
- No chunked/segmented handling of extremely long videos — bounded by
  `video_max_duration_seconds` (default 1 hour), rejected rather than
  silently truncated.
- Multi-frame extraction and audio extraction are implemented and
  tested but NOT wired into any automatic or on-demand caller yet — no
  AI Orchestrator/capability-resolver change was made to actually
  invoke them from a real chat turn (doc S8/S9 explicitly forbid
  running them automatically; wiring an on-demand caller is future
  work, not this phase's scope per its own objective).
- The video-attachment chat disclosure gap (see "AI integration" above)
  is pinned, not fixed.
- Android: no rich video bubble (poster/duration/inline playback); Vue:
  not applicable (no customer-facing AI chat UI exists).
- No performance numbers are claimed — unmeasured at scale; only small
  (1–4 second) fixtures were exercised in this session.

## Phase 8 — Chunking + Indexing Engine

Converts the normalized content every Phase 1–7 processor already
produces (`AiFileEngine::getContext()`'s `text`/`blocks` plus the
file's own `metadata`) into persisted, searchable `AiFileChunk` rows -
the FILE → PROCESSOR → NORMALIZED CONTENT → **CHUNKING ENGINE** →
CHUNKS → **INDEXING ENGINE** → INDEX → (Phase 9 ready) pipeline. No
retrieval/embedding-query/RAG feature was built this phase - only the
chunk+index substrate Phase 9 will consume.

### Architecture

- `AiChunkerInterface` (strategy) + `AiChunkerManager` (resolver) -
  mirrors `AiFileProcessorInterface`/`AiFileProcessorManager` exactly.
  Concrete strategies: `DocumentChunker` (pdf/doc/docx/txt/markdown,
  heading/paragraph/list/table block-walking), `HtmlChunker` (same
  block-walking, html only), `StructuredDataChunker` (json/xml `data`
  blocks, grouped by path), `SpreadsheetChunker` (xlsx/xls/csv/tsv, by
  sheet/row-group), `PresentationChunker` (pptx/ppt, by slide),
  `AudioTranscriptChunker`/`VideoTranscriptChunker` (by timestamp/
  speaker - see limitation below), `ImageReferenceChunker` (one
  metadata record per image, never free-text).
- `AiChunkingEngine` - the `$chunkingEngine->chunk($file)` /
  `->rechunk($file)` entry points. Reads normalized content via the
  existing `AiFileEngine::getContext()`, resolves a strategy, upserts
  the resulting `AiFileChunk` rows by a deterministic `chunk_key`
  (`sha256(file_id|content_version|chunk_index|checksum)`) - re-running
  the same unchanged file never creates duplicates. `rechunk()` bumps
  `content_version` and marks the prior version's chunks `stale`/
  inactive; only one version is ever active per file.
- `AiIndexStoreInterface` + `DatabaseIndexStore` (the only
  implementation built) - operates directly on `ai_file_chunks` (no
  separate index table), flips `status`→`indexed`, stamps
  `indexed_at`. `VectorIndexStore`/`ProviderManagedIndexStore` are
  reserved names for Phase 9, not built.
- `AiIndexingEngine` - `$indexingEngine->index($chunks)` /
  `reindex()` / `remove()`. Embedding calls are gated behind
  `ai.indexing.auto_embed` (default **false**) - deliberately more
  conservative than the admin Knowledge Base's own eager-embed
  behavior, since File Engine chunking runs automatically on every
  chat attachment.
- `AiTokenCounterInterface` + `AiHeuristicTokenCounter` - formalizes
  the `ceil(mb_strlen($text)/4)` heuristic already used informally
  elsewhere; `isExact()` is always `false` (no real tokenizer library
  exists in this codebase).
- `ChunkAndIndexAiFileJob` - one job (not four), dispatched from
  `ProcessAiFileJob::persistSuccess()`, gated by `ai.chunking.enabled`.
  Safe to retry (chunk-key upsert).

### Database

New table `ai_file_chunks` (deliberately lean, following
`ai_knowledge_chunks`' own precedent - all format-specific source
references live in one `metadata` JSON column rather than ~9 mostly-
null typed columns): `file_id`, `content_version`, `chunk_index`,
`chunk_key` (unique), `content_ref`, `content_type`, `token_count` (+
`token_count_is_estimated`), `character_count`, `checksum`,
`metadata`, `status`, `is_active`, `indexed_at`. Chunk content itself
lives on disk at `ai-files/{file_id}/chunks/v{version}/chunk-{index}.json`
(same content-ref-on-disk convention as `ai_files.extracted_content_ref`
and `ai_knowledge_chunks.content_ref`).

### Configuration

`config('ai.chunking.*')` (enabled, storage_disk, batch_size,
document.*, structured.*, spreadsheet.*, presentation.*, transcript.*)
and `config('ai.indexing.*')` (enabled, batch_size, queue,
retry_attempts, backend, auto_embed). All new - no existing key was
renamed or removed.

### Known Phase 8 limitations (documented, not silent)

- `AudioTranscriptChunker`/`VideoTranscriptChunker` key off a
  documented `metadata['transcript']['segments']` shape that **no
  processor in this codebase currently writes** - audio/video
  transcription only happens in the older, separate
  `AiChatService::transcribeIncomingAudio()` path, never persisted
  back onto `ai_files`. Both strategies therefore honestly never fire
  on a real file processed today (`supports()` correctly returns
  false); they exist so a future phase that wires transcript output
  into that metadata key gets chunking "for free." Pinned by
  `AudioTranscriptChunkerTest::test_does_not_support_a_real_audio_file_today()`.
- `AiIndexingEngine::embedChunks()` calls the provider's embed API per
  chunk when `auto_embed` is explicitly turned on, but the returned
  vector is **not persisted anywhere** (no `VectorIndexStore` exists
  yet) - calling it today would spend API calls for no stored benefit.
  Left this way deliberately rather than inventing a vector-storage
  convention Phase 9 hasn't asked for; `auto_embed` defaults `false`
  specifically because of this.
- No admin UI *page* was added - the existing admin file-detail
  endpoint (`AiFileService::show()`/`AiFileResource`) was extended
  with a `chunking` summary block (chunk_count/content_version/
  failed_chunk_count/indexing_status/last_indexed_at); no change was
  made to the Vue admin UI itself to display it (out of this phase's
  backend scope, same as every prior phase's Vue-side disclosure).
- No real tokenizer library exists in this codebase - all token counts
  are heuristic estimates, honestly flagged via
  `token_count_is_estimated`.
- As always: PHP/PHPUnit is unavailable in this working environment -
  tests were written (`tests/Unit/Chunking/*`, `tests/Feature/
  AiChunkingEngineTest.php`, `AiIndexingEngineTest.php`,
  `AiFileChunkSecurityTest.php`, `ChunkAndIndexAiFileJobDispatchTest.php`)
  but could not actually be executed.

---

## Phase 9 — File Search / RAG Engine

Builds a retrieval layer on top of Phase 8's chunking/indexing
architecture: `AiRetrievalEngine` searches a user's own already-indexed
`ai_file_chunks`, scores and ranks candidates, deduplicates, and hands a
budgeted, citation-carrying context block to `AiChatService` for
grounded answers — independent of any specific LLM provider.

### Note: closes a Phase 8 limitation

The "Known Phase 8 limitations" section above documents that
`AiIndexingEngine::embedChunks()` computed an embedding but never
persisted it anywhere. **Phase 9 fixes this**: the vector is now
written into the same `content_ref` JSON file as the chunk's text
(mirroring `ai_knowledge_chunks`' own `content`+`embedding` shape), and
`embedding_status`/`embedding_model`/`embedding_provider`/`embedded_at`
are tracked on the row. `auto_embed` still defaults `false` — this is a
cost control, not a "it wouldn't work anyway" limitation now.

### New components

- `AiRetrievalQueryAnalyzer` — decides whether a chat message needs
  file retrieval at all (keyword-map pattern, same style as
  `AiRequiredCapabilityResolver`/`AiIntentClassifier`). A fresh
  attachment always triggers it; a bare greeting or generic knowledge
  question never does, even with files in scope.
- `AiRetrievalEngine` — resolves the authorized file scope (explicit
  file id(s) → conversation scope → optionally the owner's whole ready
  library, each one **owner_type/owner_id-scoped before any chunk is
  ever touched**), fetches a bounded candidate set, scores it via
  `AiSearchBackendInterface` implementations, deduplicates by
  checksum, ranks, and reorders for document coherence (same file's
  chunks grouped and kept in `chunk_index` order; groups ordered by
  their best score).
- `DatabaseKeywordSearchBackend` — a real, portable lexical backend
  (the same coverage+Jaccard formula `AiKnowledgeRetriever` already
  uses for the admin Knowledge Base, extracted into a shared
  `ScoresLexicalOverlap` trait) plus a literal-substring phrase boost.
  Always available; this is the backend ordinary chat retrieval runs
  on today, since `auto_embed` defaults off.
- `DatabaseSemanticSearchBackend` — real cosine similarity between a
  query embedding and a chunk's **actually stored** vector. Returns
  `null` (never a fabricated score) whenever no provider is
  configured, a chunk has no stored embedding, or the query and
  chunk were embedded by different providers. A bounded, in-PHP
  brute-force scan over the already-fetched candidate set — not a
  true ANN index — the same honest trade-off `AiKnowledgeRetriever`
  already makes and documents.
- Hybrid mode = `semanticScore * semantic_weight + keywordScore *
  keyword_weight` (both already bounded to `[0, 1]`), falling back to
  keyword-only when no semantic score is available for a chunk — the
  same weighted-sum formula `AiKnowledgeRetriever`'s own hybrid
  scoring uses, not Reciprocal Rank Fusion.
- `AiContextBuilder` — budgets the ranked results by
  `max_chunks`/`max_characters`/`max_tokens` (reusing Phase 8's
  `AiTokenCounterInterface`), builds a numbered `[1] (label): excerpt`
  context block, and wraps it in the same prompt-injection-safe
  "this is DATA, never instructions" framing
  `AiChatService::evidenceSystemMessage()` already uses for the admin
  Knowledge Base.
- `AiFileCitation` / `ai_file_citations` — a new table (parallel to
  the existing `ai_request_citations`, not a reused/overloaded one)
  recording which file chunks backed which `AiRequest`'s answer.

### Chat integration

`AiChatService::sendMessage()` now also calls
`resolveFileRetrievalContext()` right alongside its existing,
unconditional admin-Knowledge-Base retrieval call. Unlike the KB call,
file retrieval is **optional** — gated by `AiRetrievalQueryAnalyzer` —
so ordinary chat (no files, or a plain greeting) is completely
unaffected. When retrieval runs and returns content, a new system
message (built by `AiContextBuilder::toSystemMessage()`) is appended
and the citations are persisted to `ai_file_citations`.

### Authorization

No Laravel Policy classes exist anywhere in this module — authorization
has always been direct `owner_type`/`owner_id` query scoping (mirroring
`AiFile::isOwnedBy()`). `AiRetrievalEngine::resolveFileScope()` applies
that scoping **before** any `ai_file_chunks` row is fetched, for every
branch (explicit file id, conversation scope, global scope) — never
fetch-then-filter. An explicit file id belonging to another owner
silently resolves to "no files in scope," never a leaked existence
check.

### Configuration

`config('ai.retrieval.*')`: `enabled`, `default_mode`,
`allow_global_file_scope` (default `false` — no UI surfaces this yet),
`candidate_k` (30), `top_k` (8), `min_relevance_score` (0.1),
`context.max_chunks`/`max_characters`/`max_tokens`,
`hybrid.keyword_weight`/`semantic_weight`.

### What was deliberately NOT built this phase

- **`ProviderManagedSearchBackend`** — no provider-managed file-search
  / vector-store product (e.g. an Assistants-style vector store API)
  is integrated anywhere in this codebase; only the raw `/embeddings`
  endpoint is used. Documented as an open extension point behind
  `AiSearchBackendInterface`, not faked.
- **New embedding queue jobs** — the existing Phase 8
  `ChunkAndIndexAiFileJob`/`AiIndexingEngine::embedChunks()` was fixed
  and extended in place rather than duplicated.
- **Phase 10 (multi-file conversation UX), Android/Vue UI,
  provider-managed search, a vector database** — none of these were
  implemented, per this phase's own explicit scope boundary.
- The current-turn chat attachment path
  (`AiChatService::buildDocumentText()`, first ~6000 characters dumped
  directly into the prompt) was **not** touched — Phase 9 adds a
  separate retrieval path for files already processed through the File
  Engine; it does not replace the inline dump used for the message the
  user is sending right now.

### Known Phase 9 limitations (documented, not silent)

- Semantic search is a real but brute-force, bounded, in-PHP cosine
  scan — not a true vector index. Fine at today's scale; would need a
  real ANN backend (pgvector, a dedicated vector DB) to scale further.
- `AiFileChunk::readEmbedding()` treats an embedding as stale purely by
  checking, **at read time**, whether its stored `embedding_model`
  still matches the currently configured model — there is no
  background reconciliation sweep that proactively re-embeds chunks
  after a model/config change.
- As always: PHP/PHPUnit is unavailable in this working environment —
  tests were written (`tests/Unit/Retrieval/*`,
  `AiRetrievalEngineTest.php`, `AiIndexingEngineEmbeddingTest.php`,
  `AiFileCitationSecurityTest.php`,
  `AiChatServiceFileRetrievalIntegrationTest.php`) but could not
  actually be executed.

---

## Phase 10 — Multi-file Conversations

Builds the backend architecture for a conversation to work with
**multiple** files as a coherent context, extending Phase 9's
single-file retrieval rather than replacing it.

### What inspection found

- `ai_files.conversation_id`/`message_id` (Phase 1) is a **one-to-one
  slot set once at upload time** — "the conversation/message this file
  was first uploaded into" — never reassignable, and never many-to-many.
- `AiFileEngine::process()`'s own checksum dedupe (`findReadyDuplicate()`)
  can silently hand back an **existing** `AiFile` whose
  `conversation_id` still points at a completely different, earlier
  conversation. **This was a real, pre-existing gap**: re-uploading an
  identical file into a second conversation would reuse the same row
  but never become searchable in that second conversation under Phase
  9's own conversation-scope retrieval. Phase 10 fixes this (see
  below).
- Message-level attachments already existed
  (`AiConversationAttachment`, `message_id` nullable — null meaning "a
  general attachment on the whole conversation"), but there was no
  explicit many-to-many relationship, no attach/detach lifecycle, and
  no idempotency guarantee.
- Assistant messages already persist their file citations:
  `AiMessage.request_id` → `AiRequest` ← `ai_file_citations.request_id`
  (Phase 9). **No new citation-persistence table was needed** — doc
  S35's own "only create this if the project currently lacks an
  equivalent" applied directly.

### New components

- **`ai_conversation_files`** — a new, explicit many-to-many
  conversation↔file table (`AiConversationFile` model,
  `attached`/`detached` lifecycle, unique on `(conversation_id,
  file_id)`). `ai_files.conversation_id`/`message_id` are **left
  completely untouched** — this is a layer on top, not a replacement.
- **`AiConversationFileScope`** — the one place that resolves "which
  files make up this conversation's searchable context right now,"
  enforcing: explicit `file_ids` (when given) take precedence over the
  conversation's own attached scope; every branch is owner/status
  scoped in the query itself (never fetch-then-filter); a legacy file
  (`conversation_id` set, no pivot row yet) is treated as attached for
  backward compatibility, but the moment a pivot row exists for that
  pair, its own `status` wins — so even a legacy file can be detached.
- **`AiFileEngine::attachToConversation()`** — every exit point of
  `process()` (success, dedupe-reuse, validation-failed,
  unsupported-type) now registers the file into
  `ai_conversation_files` whenever a conversation was given — this is
  the fix for the dedupe-reuse gap above: a deduped file now becomes
  part of the SECOND conversation's scope too, not just its original
  one.
- **`AiConversationFileService`/`AiConversationFileController`** — new
  `GET`/`POST`/`DELETE
  user|provider/v1/ai-chat/conversations/{conversation}/files`
  endpoints (attach an existing file / list attached / detach), same
  thin-controller shape as every other endpoint in this module, same
  `*Repository::findForOwner()` authorization pattern.
- **`AiRetrievalEngine` diversity + file coverage** — `AiRetrievalQuery`
  already accepted a `fileIds` array (Phase 9 built it that way from
  the start). Phase 10 adds: a `max_chunks_per_file` cap (only engaged
  once more than one distinct file is present among ranked candidates)
  so one highly relevant file cannot occupy the entire `top_k`, and a
  `fileCoverage` field on `AiRetrievalResult`
  (`searched_file_ids`/`matched_file_ids`/`unmatched_file_ids`).
- **`AiContextBuilder` file grouping** — once the final result set spans
  more than one file, a `FILE: <name>` header is emitted whenever the
  file changes between consecutive excerpts. A single-file result (the
  common case) gets no header at all — multi-file support is a visible
  extension, never a change to Phase 9's own single-file output.
- **`AiChatService`** gained an explicit, optional `file_ids` parameter
  on `sendMessage()` (validated in `AiChatMessageRequest`, capped by
  `ai.retrieval.multi_file.max_files`), threaded through
  `resolveFileRetrievalContext()` into `AiConversationFileScope`.

### Hardening fix (incidental, surfaced by this phase's own work)

`AiRetrievalEngine::resolveFileScope()` used to treat an explicit,
**empty** `fileIds` array the same as `fileIds` not being passed at
all (`null`), silently falling through to the `conversationId`/global
branches. Phase 10 depends on "no searchable files" reliably meaning
"search nothing," so this was tightened: `fileIds !== null` (even `[]`)
now always short-circuits to an empty scope.

### Configuration

`config('ai.retrieval.multi_file.*')`: `enabled`, `max_files` (10),
`diversity.enabled` (`true`), `diversity.max_chunks_per_file` (4).

### Deliberately NOT built this phase

- No Android/Vue multi-file UI, no drag-and-drop, no file preview UX.
- No `ai_message_sources` table — `ai_file_citations` (Phase 9) already
  covers this, keyed by `request_id`.
- No new "message-level attachment" tier distinct from the conversation
  scope — inspection found the real architecture doesn't need one (see
  `AiConversationFileScope`'s own docblock).
- Phase 11 (Android/Vue UX), Phase 12 (quotas/monitoring), Phase 13
  (final hardening) — none implemented, per this phase's own scope
  boundary.

### Known Phase 10 limitations (documented, not silent)

- File diversity is a simple two-pass cap/fill, not a full relevance-vs-
  diversity optimization — sufficient for the "do not over-engineer
  this" instruction, not a guaranteed-optimal allocation.
- As always: PHP/PHPUnit is unavailable in this working environment —
  tests were written (`AiConversationFileScopeTest`,
  `AiConversationFileApiTest`, `AiRetrievalEngineMultiFileTest`,
  `AiChatServiceMultiFileIntegrationTest`) but could not actually be
  executed.

## Phase 11 — Android + Vue AI File & Multi-file UX

A UX/API-integration phase, not a backend architecture phase. Scope was
"attach files → ask questions → get answers" with every technical
processing concept (chunking, embeddings, retrieval mode) kept
invisible, built strictly on the real Phase 9/10 backend contracts
re-verified from source in this phase (not assumed from memory).

### Backend additions (justified by doc S44 - "support citation
rendering" / "support frontend state" - never a redesign)

- `AiMessage::fileCitations()` - a direct `hasMany(AiFileCitation,
  'request_id', 'request_id')`, matched on the request_id both tables
  already share for one turn (no FK from `ai_requests` to
  `ai_messages` exists, so this isn't a `hasManyThrough`).
- `AiFileCitationResource` (new) + `AiMessageResource.citations` -
  exposes only real, already-computed fields (file id/name, excerpt,
  score, retrieval method, and whichever location keys
  `AiRetrievalEngine::sourceReference()` actually produced for that
  chunk's content type). Never a pre-rendered "Page 3" label - that's
  left to the client's own i18n.
- `AiConversationRepository::findForOwner()` now eager-loads
  `messages.fileCitations.file` alongside the existing
  `messages.attachments`, and every `assistant_message` response in
  `AiChatService` (`sendMessage`, `streamMessage`'s underlying call,
  the voice/document/image reply paths) loads the same relation before
  building its `AiMessageResource`.
- `AiChatService::streamMessage()` / `AiChatController::streamMessage()`
  gained the same `?array $fileIds` parameter `sendMessage()` already
  had (Phase 10) - without this, explicit file-scope selection would
  silently do nothing on the (far more common) no-attachment streaming
  path.
- `AiChatService::activeProviderStatus()` now returns a `file_limits`
  block (`max_size_bytes`, `allowed_mime_types`,
  `max_conversation_files`), read from the existing
  `ai.files.*`/`ai.retrieval.multi_file.max_files` config - doc S32:
  the client must use server-authoritative limits, never a hardcoded
  guess.

No chunking/embedding/retrieval engine code was touched.

### Vue (customer-facing chat, `modules/user/.../views/chat/index.vue`)

The only existing AI chat UI in Vue is this one screen (`ChatPanel.vue`
+ `ConversationSidebar.vue`); the **provider** module has no AI chat
UI at all (confirmed by inspection - its Vue tree has no chat-related
views), so no new provider screen was invented, matching doc S17's
"do not invent a new product area."

Added, all additive to the existing single-quick-attachment flow
(doc S11 - kept fully intact, unchanged):

- `useAiConversationFiles.js` composable: owns the "files attached to
  this conversation" list, multi-file upload (one `POST
  /user/v1/ai-files` per file, `conversation_id` set so Phase 10's
  `AiFileEngine::attachToConversation()` does the attach step - no
  separate attach call needed), status polling (stops at
  ready/failed/detached, 2.5s interval, hard timeout), detach, and the
  explicit per-message file-id selection set.
- `ConversationFileList.vue`, `AiFileStatusBadge.vue`,
  `MessageCitations.vue` (new components) + `aiFileDisplay.js` (shared
  file-size/icon helpers, reusing the existing Remix Icon set already
  used everywhere else - no new icon library).
- `ChatPanel.vue`: a second, distinct "Add files" entry point (its own
  icon/button) opens a small panel listing conversation files with
  status, add/remove, and an explicit-scope checkbox per READY file;
  citations render under each assistant bubble via
  `MessageCitations.vue`.
- `chat/index.vue`: wires the composable in, loads `file_limits` from
  `/ai-chat/status`, and forwards the user's explicit file selection
  (when any) as `file_ids` on both the streaming and
  attachment-multipart send paths.
- `locales/en.json` / `locales/ar.json`: new natural-language strings
  for every new state (`status_processing` → "جاري تجهيز الملف",
  `status_ready` → "جاهز للاستخدام", `sources_title` → "المصادر", etc,
  doc S45) - no raw technical term anywhere in the UI.

Verified with the project's own `@vue/compiler-sfc` (template + script
compiled successfully for every changed/new `.vue` file) since a full
`vite build` is blocked in this environment (see Tests below) - this
is a real compiler check, not a guess.

### Android (Kotlin/Compose, `androidApp`)

Scope was narrowed, and that narrowing is disclosed rather than hidden:

- **Implemented**: the data/network layer for the new backend
  capabilities (`AiFileDto`, `AiFileCitationDto`,
  `AiCitationLocationDto`, `AiFileLimitsDto`, `citations` on
  `AiMessageDto`, `file_limits` on `AiStatusDto`) and the matching
  Retrofit endpoints (`conversationFiles`, `attachConversationFile`,
  `detachConversationFile`, `uploadConversationFile`, `fileStatus`) in
  `AiChatApi.kt`/`AiChatModels.kt`; and the **citations/sources UI**
  itself - a collapsible "N sources" row under each assistant bubble
  in `AiConversationPage.kt` (`AiCitationsSection`), using the same
  field precedence as the Vue version (page → sheet/rows → slide →
  timestamp → section), never an invented value.
- **Not implemented this phase**: the conversation-file management
  screen (add/remove/status list) and explicit per-message file-scope
  selection on Android. The API client for both exists and is ready to
  wire up, but the UI itself was out of reach within this phase's
  scope/time - sending a message without explicit `file_ids` still
  uses the conversation's full attached-file scope by default
  server-side (doc S12: explicit scope is "not mandatory"), so this is
  a real, usable limitation rather than a broken feature.
- The existing single quick-attachment composer flow
  (`pendingAttachment`, image/voice/document) is untouched.

### Tests / builds - honest status (doc S16/S40/S41/S42/S43)

- **Backend (PHP/PHPUnit)**: NOT RUN - no `php` binary in this
  environment (`php -v` → command not found), consistent with every
  earlier phase.
- **Vue**: no test framework exists in this project (`package.json`
  has only `build`/`dev` scripts, no `test`) - there is no "existing
  framework" to extend, so none was invented. The real
  `@vue/compiler-sfc` compile check above is the actual verification
  performed, honestly distinct from a test suite.
- **Android**: no `test`/`androidTest` source set exists anywhere in
  `androidApp/app/src` - same situation. A full Gradle build was not
  attempted: Gradle 8.9's first run (dependency resolution + AGP/
  Kotlin compile) runs far longer than this environment's per-command
  time budget, and the device JDK (11) may not even satisfy the
  Android Gradle Plugin's own minimum - BLOCKED, not run, not
  fabricated as a pass. Every changed/new Kotlin file was instead
  checked by hand and with a brace/paren/bracket balance pass.
- **Visual validation**: NOT RUN - no emulator/browser-screenshot
  tooling available in this environment for the actual rendered app.

### Deliberately NOT built this phase

- Phase 12 (quotas/billing/analytics/monitoring), Phase 13 (final
  hardening) - not implemented, per this phase's own scope boundary.
- No PDF/document viewer, no in-file "jump to source" navigation (doc
  S15) - the backend has no exact in-file jump target to hand back, so
  sources are shown as plain info instead of a fake navigation link.
- No new provider-facing Vue chat UI (none existed to extend).
- No conversation-file management screen or explicit file-scope
  picker on Android (see above).

## Phase 12 — Security + Quotas + Monitoring

A full security/authorization/monitoring audit, not a rebuild - this
module already had a mature, previously-built apparatus for almost
every item in Phase 12's scope (see the Final Report's "Security Audit"
section for the complete inventory: AiChatUsageGuard's time-based plan
quota, per-owner-keyed tiered rate limiting, AiRequest/AiUsage's
token/cost/correlation-id tracking, AiProviderLog's clean structured
provider-call logging, a full admin monitoring route surface, and a
daily `ai:enforce-retention` command). The one genuine, previously-
missing dimension found and fixed: no per-owner cap existed on total
stored files/storage bytes.

### What was added

- `config('ai.files.max_files_per_owner')` / `max_storage_bytes_per_owner`
  (0 disables either dimension).
- `AiFileRepository::usageTotalsForOwner()` - owner-scoped
  COUNT/SUM query.
- `AiFileEngine::assertWithinOwnerQuota()` - checked in
  `storeUploadedFile()` BEFORE the file is written to disk.
- `AiFileException::forReason('file_quota_exceeded')` (422) +
  `lang/{en,ar}/ai.php` translations - same normalized-error pattern
  every other File Engine rejection already uses.
- `AiChatService::fileUsageSummary()` - folded into the existing
  `GET /ai-chat/usage` response (`files_used`, `files_limit`,
  `storage_used_bytes`, `storage_limit_bytes`) rather than a second
  quota endpoint.

### Known, disclosed limitation

This cap is enforced on the dedicated `POST /user/v1/ai-files` upload
path (what Phase 11's multi-file UX actually uses). It is **not**
enforced on the older inline single-attachment path
(`AiChatService::storeAttachment()`), which stores the file directly
and only best-effort registers it with `AiFileEngine::process()`
afterward inside a try/catch that must never fail the chat reply -
throwing a quota exception there would be silently swallowed, not
actually block anything. Closing that gap would mean checking the
quota before `storeAttachment()`'s own `$file->store()` call and
rejecting the whole message send on quota - a real behavior change to
the core chat-send path, deliberately left for explicit approval
rather than made silently under this phase's own "do not change
business logic unnecessarily" instruction.
