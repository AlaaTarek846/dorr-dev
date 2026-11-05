/** Minor units → "3.00 SAR" in the dashboard's language. */
export function money(minor, currency, locale) {
    const decimals = currency?.decimals ?? 2;
    const value = (Number(minor) || 0) / 10 ** decimals;

    return `${value.toLocaleString(locale, { minimumFractionDigits: decimals, maximumFractionDigits: decimals })} ${currency?.code ?? ''}`.trim();
}

/** "3.00" typed by the admin → 300 minor units. */
export function toMinor(value, decimals = 2) {
    return Math.round((Number(value) || 0) * 10 ** decimals);
}

export function fromMinor(minor, decimals = 2) {
    return ((Number(minor) || 0) / 10 ** decimals).toFixed(decimals);
}
