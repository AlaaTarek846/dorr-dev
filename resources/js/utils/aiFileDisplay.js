/**
 * Phase 11 (doc S26/S27): shared, presentation-only helpers for the AI
 * file-attachment UI - natural file-size formatting and a mime-type ->
 * existing-icon-library mapping. Deliberately tiny: a handful of
 * buckets reusing the project's own Remix Icon set already loaded
 * everywhere else in the app, not a new icon set or a per-extension
 * SVG library (doc S27: "don't create dozens of custom icons").
 */

/**
 * @param {number|null|undefined} bytes
 * @returns {string}
 */
export function formatFileSize(bytes) {
    if (bytes === null || bytes === undefined || Number.isNaN(bytes)) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    let value = Number(bytes);
    let unitIndex = 0;

    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024;
        unitIndex += 1;
    }

    const precision = unitIndex === 0 ? 0 : 1;

    return `${value.toFixed(precision)} ${units[unitIndex]}`;
}

/**
 * @param {string|null|undefined} mimeType
 * @returns {string} a `ri-*` class name
 */
const EXTENSION_ICONS = {
    pdf: 'ri-file-pdf-2-line',
    doc: 'ri-file-word-2-line',
    docx: 'ri-file-word-2-line',
    md: 'ri-file-word-2-line',
    xls: 'ri-file-excel-2-line',
    xlsx: 'ri-file-excel-2-line',
    csv: 'ri-file-excel-2-line',
    ppt: 'ri-file-ppt-2-line',
    pptx: 'ri-file-ppt-2-line',
    json: 'ri-file-code-line',
    xml: 'ri-file-code-line',
    html: 'ri-file-code-line',
    txt: 'ri-file-text-line',
    jpg: 'ri-image-2-line',
    jpeg: 'ri-image-2-line',
    png: 'ri-image-2-line',
    webp: 'ri-image-2-line',
    gif: 'ri-image-2-line',
    mp3: 'ri-mic-line',
    wav: 'ri-mic-line',
    m4a: 'ri-mic-line',
    aac: 'ri-mic-line',
    ogg: 'ri-mic-line',
    opus: 'ri-mic-line',
    flac: 'ri-mic-line',
    mp4: 'ri-film-line',
    mov: 'ri-film-line',
    webm: 'ri-film-line',
    avi: 'ri-film-line',
};

/**
 * Accepts either a real mime type ("application/pdf") or, when only a
 * file name is available (e.g. a citation, which carries no mime type -
 * see AiFileCitationResource), falls back to the file's extension. Both
 * paths land on the same small icon set.
 *
 * @param {string|null|undefined} mimeTypeOrFileName
 */
export function fileIconFor(mimeTypeOrFileName) {
    const value = mimeTypeOrFileName || '';

    if (value.includes('/')) {
        if (value.startsWith('image/')) return 'ri-image-2-line';
        if (value.startsWith('audio/')) return 'ri-mic-line';
        if (value.startsWith('video/')) return 'ri-film-line';
        if (value === 'application/pdf') return 'ri-file-pdf-2-line';
        if (value.includes('word') || value === 'text/markdown') return 'ri-file-word-2-line';
        if (value.includes('sheet') || value.includes('excel') || value === 'text/csv') return 'ri-file-excel-2-line';
        if (value.includes('presentation') || value.includes('powerpoint')) return 'ri-file-ppt-2-line';
        if (value === 'application/json' || value === 'text/json' || value.includes('xml')) return 'ri-file-code-line';
        if (value === 'text/plain') return 'ri-file-text-line';

        return 'ri-file-line';
    }

    const extension = value.split('.').pop()?.toLowerCase();

    return EXTENSION_ICONS[extension] || 'ri-file-line';
}
