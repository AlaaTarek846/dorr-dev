/**
 * Root-cause fix - real, observed gap: every AI admin screen (subscriptions,
 * usage, requests, safety events, files, ...) renders a subscriber as
 * `owner?.name ?? '-'`, so any account created without a filled-in name
 * (phone/OTP signup with no profile step yet - common on this platform)
 * shows as a bare, unidentifiable "-" in every single one of these
 * screens. AiOwnerResource (the shared backend shape every one of these
 * screens consumes) now also exposes `phone`, which - unlike name - is
 * guaranteed to exist for a real account. One shared helper here instead
 * of repeating the same `name || phone || '-'` fallback in a dozen Vue
 * files that would inevitably drift out of sync with each other.
 *
 * @param {{ name?: string|null, phone?: string|null } | null | undefined} owner
 * @returns {string}
 */
export function ownerDisplayName(owner) {
    return owner?.name || owner?.phone || '-';
}
