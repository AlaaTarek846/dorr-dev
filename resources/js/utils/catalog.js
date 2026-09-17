import { nextTick, watch } from 'vue';
import adminAxios from '../api/adminAxios';

function flagCdnWidth(size) {
    const parsed = Number(size);

    if (! Number.isFinite(parsed) || parsed <= 20) {
        return 20;
    }

    if (parsed <= 40) {
        return 40;
    }

    if (parsed <= 80) {
        return 80;
    }

    return 160;
}

export function flagImageSources(code, size = 32) {
    if (! code) {
        return [];
    }

    const normalized = String(code).toLowerCase();
    const width = flagCdnWidth(size);

    return [
        `https://flagcdn.com/w${width}/${normalized}.png`,
        `https://flagcdn.com/w${width}/${normalized}.webp`,
    ];
}

export function flagImageUrl(code, size = 32) {
    return flagImageSources(code, size)[0] ?? '';
}

export function flagImageFallback(event) {
    const code = String(event.target.dataset.flagCode ?? '').toLowerCase();
    const size = Number(event.target.dataset.flagSize ?? 32);
    const sources = flagImageSources(code, size);
    const currentIndex = Number(event.target.dataset.sourceIndex ?? 0);
    const nextIndex = currentIndex + 1;

    if (nextIndex < sources.length) {
        event.target.dataset.sourceIndex = String(nextIndex);
        event.target.src = sources[nextIndex];

        return;
    }

    event.target.style.visibility = 'hidden';
}

export function resolveLanguageFlagCode(language) {
    return language?.flag?.code ?? null;
}

export function displayTranslatedName(record, locale) {
    const translation = record?.translations?.find((item) => item.locale === locale);

    return translation?.name || record?.name || '-';
}

export function formatCatalogDate(value, locale) {
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

export function resolveRecordFlagCode(record) {
    return record?.flag?.code || record?.code || null;
}

export function normalizeDialCode(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }

    return String(value).replace(/^\+/, '');
}

export function formatDialCodeForPayload(value) {
    const trimmed = normalizeDialCode(value);

    return trimmed ? `+${trimmed}` : '';
}

export function resolveCountryFlagCode(country) {
    return country?.flag?.code ?? country?.code ?? null;
}

export function splitPhoneNumber(fullPhone, dialCode) {
    const localFallback = String(fullPhone ?? '').trim();
    const dial = normalizeDialCode(dialCode);
    const normalized = normalizeDialCode(fullPhone);

    if (! dial) {
        return localFallback;
    }

    if (normalized.startsWith(dial)) {
        return normalized.slice(dial.length);
    }

    return localFallback.replace(/^\+/, '');
}

export function combinePhoneNumber(dialCode, localPhone) {
    const local = String(localPhone ?? '').trim().replace(/\s+/g, '');

    if (! local) {
        return '';
    }

    const dial = formatDialCodeForPayload(dialCode);

    return `${dial}${local}`;
}

export function formatPhoneForDisplay(fullPhone, dialCode) {
    const raw = String(fullPhone ?? '').trim();

    if (! raw) {
        return '';
    }

    const local = splitPhoneNumber(raw, dialCode);
    const dial = formatDialCodeForPayload(dialCode);

    if (dial && local && local !== raw) {
        return `${dial} ${local}`;
    }

    return raw;
}

export function filterStorableTranslations(translations, storableLocales) {
    if (! Array.isArray(translations) || ! storableLocales?.length) {
        return [];
    }

    const allowed = storableLocales.map((locale) => String(locale).toLowerCase());

    return translations.filter((item) => allowed.includes(String(item.locale).toLowerCase()));
}

export function syncTranslationFormKeys(target, localeCodes = []) {
    for (const code of localeCodes) {
        if (!(code in target)) {
            target[code] = '';
        }
    }

    for (const key of Object.keys(target)) {
        if (! localeCodes.includes(key)) {
            delete target[key];
        }
    }
}

export function fillCatalogTranslationFields(target, record, localeCodes = null) {
    const codes = localeCodes ?? Object.keys(target);
    const translations = Array.isArray(record?.translations) ? record.translations : [];

    for (const code of codes) {
        target[code] = translations.find((item) => item.locale === code)?.name ?? '';
    }

    if (record?.name) {
        for (const code of codes) {
            if (! target[code]) {
                target[code] = record.name;
            }
        }
    }
}

export async function fetchCatalogRecord(resourceUri, id) {
    const { data } = await adminAxios.get(`${resourceUri}/${id}`);

    return data.data ?? null;
}

export function setupCatalogModalWatcher({
    props,
    fillForm,
    resetForm,
    openModal,
    closeModal,
    resourceUri,
    onOpen,
}) {
    watch(
        () => [props.show, props.type, props.record?.id],
        async ([visible, type, recordId]) => {
            if (! visible) {
                closeModal();
                return;
            }

            if (onOpen) {
                await onOpen();
            }

            if (type === 'edit' && recordId) {
                try {
                    const record = await fetchCatalogRecord(resourceUri, recordId);

                    fillForm(record ?? props.record);
                } catch {
                    fillForm(props.record);
                }
            } else {
                resetForm();
            }

            await nextTick();
            openModal();
        },
    );
}
