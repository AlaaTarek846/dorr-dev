/**
 * Shared Quill / PrimeVue Editor configuration for Dorr admin & SPAs.
 * Import {@link CatalogRichTextEditor} to edit; wrap rendered HTML with `.dorr-rich-text-content`.
 */

/** @type {readonly string[]} */
export const RICH_TEXT_FORMATS = Object.freeze([
    'header',
    'font',
    'bold',
    'italic',
    'underline',
    'strike',
    'color',
    'background',
    'list',
    'indent',
    'align',
    'direction',
    'blockquote',
    'code-block',
    'link',
    'image',
    'video',
]);

/** @type {readonly string[]} */
export const RICH_TEXT_FORMATS_NO_MEDIA = Object.freeze(
    RICH_TEXT_FORMATS.filter((format) => format !== 'image' && format !== 'video'),
);

export const RICH_TEXT_DEFAULT_MIN_HEIGHT = '160px';
