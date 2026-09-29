/**
 * Money in the wallet system is an integer in minor units (halalas/piastres).
 * Nothing here ever does float maths on it: display is integer division and
 * user input is parsed with a strict regex that rejects a 3rd decimal instead
 * of silently rounding it.
 */

export function fmtMinor(minor, currency = '') {
    if (minor === null || minor === undefined || minor === '') {
        return '-';
    }

    const value = Number(minor);
    const abs = Math.abs(value);
    const whole = String(Math.floor(abs / 100)).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const text = `${value < 0 ? '-' : ''}${whole}.${String(abs % 100).padStart(2, '0')}`;

    return currency ? `${text} ${currency}` : text;
}

/** "12.5" → 1250, "" → null, "12.555" → NaN (invalid on purpose). */
export function parseMajor(text) {
    const trimmed = String(text ?? '').trim().replace(',', '.');

    if (trimmed === '') {
        return null;
    }

    if (! /^\d+(\.\d{1,2})?$/.test(trimmed)) {
        return Number.NaN;
    }

    const [whole, frac = ''] = trimmed.split('.');
    const minor = Number(whole) * 100 + Number(frac.padEnd(2, '0'));

    return Number.isSafeInteger(minor) ? minor : Number.NaN;
}

/** 1250 → "12.50" (for pre-filling form fields). */
export function majorFromMinor(minor) {
    if (minor === null || minor === undefined || minor === '') {
        return '';
    }

    return fmtMinor(minor).replace(/,/g, '');
}

export function formatDateTime(value, locale = 'en') {
    if (! value) {
        return '-';
    }

    return new Date(value).toLocaleString(locale === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
