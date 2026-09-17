import { computed, ref } from 'vue';
import { useAvailableLanguagesStore } from '../stores/availableLanguages';
import {
    fillCatalogTranslationFields,
    syncTranslationFormKeys,
} from '../utils/catalog';
import useValidation from './useValidation';

/**
 * Shared translation tabs state/helpers for catalog create/edit modals.
 */
export default function useCatalogTranslations(options = {}) {
    const {
        form,
        serverErrors,
        nameKey,
        maxLength = 255,
        minLength = 0,
        getV$ = () => null,
    } = options;

    const languagesStore = useAvailableLanguagesStore();
    const {
        catalogTranslationRulesFromLanguages,
        firstError,
        fieldFeedback,
        focusFirstInvalidTab,
    } = useValidation();

    const activeLocale = ref('');

    const storableLanguages = computed(() => languagesStore.items);
    const localeOrder = computed(() => storableLanguages.value.map((language) => language.code));
    const localeIndexMap = computed(() => Object.fromEntries(
        localeOrder.value.map((code, index) => [code, String(index)]),
    ));

    const translationRules = computed(() => catalogTranslationRulesFromLanguages(
        nameKey,
        storableLanguages.value,
        maxLength,
        minLength,
    ));

    async function ensureLanguagesLoaded() {
        await languagesStore.fetch();
        syncTranslationFormKeys(form.translations, localeOrder.value);

        if (! activeLocale.value || ! localeOrder.value.includes(activeLocale.value)) {
            activeLocale.value = localeOrder.value[0] ?? '';
        }
    }

    function translationServerError(localeKey) {
        const index = localeIndexMap.value[localeKey];
        const keys = [
            `translations.${localeKey}.name`,
            `translations.${localeKey}.locale`,
        ];

        if (index !== undefined) {
            keys.unshift(
                `translations.${index}.name`,
                `translations.${index}.locale`,
            );
        }

        return firstError(serverErrors, keys);
    }

    const translationsGroupMessage = computed(() => {
        const serverMessage = firstError(serverErrors, ['translations']);

        if (serverMessage) {
            return serverMessage;
        }

        const translationsState = getV$()?.translations;
        const parentError = translationsState?.$errors?.find((error) => (
            (error.$validator === 'required' || error.$validator === 'minLength')
            && ! localeOrder.value.includes(error.$property)
        ));

        return parentError?.$message || null;
    });

    function translationTabFeedback(localeKey) {
        const v$ = getV$();

        return fieldFeedback(
            v$?.translations?.[localeKey],
            translationServerError(localeKey),
            form.translations[localeKey],
        );
    }

    function translationTabClass(localeKey) {
        const feedback = translationTabFeedback(localeKey);

        return {
            active: activeLocale.value === localeKey,
            'catalog-lang-tab--error': feedback.show && feedback.invalid,
            'catalog-lang-tab--valid': feedback.show && feedback.valid,
        };
    }

    const activeTranslationFeedback = computed(() => {
        const v$ = getV$();

        return fieldFeedback(
            v$?.translations?.[activeLocale.value],
            translationServerError(activeLocale.value),
            form.translations[activeLocale.value],
        );
    });

    const activeTranslationInputClass = computed(() => ({
        'is-invalid': activeTranslationFeedback.value.show && activeTranslationFeedback.value.invalid,
        'is-valid': activeTranslationFeedback.value.show && activeTranslationFeedback.value.valid,
    }));

    const activeTranslationMessage = computed(() => {
        if (! activeTranslationFeedback.value.invalid) {
            return null;
        }

        const v$ = getV$();
        const clientError = v$?.translations?.[activeLocale.value]?.$errors[0]?.$message;

        return clientError || translationServerError(activeLocale.value);
    });

    function onTranslationInput(localeKey) {
        clearTranslationError(localeKey);
        getV$()?.$touch();
    }

    function clearTranslationError(localeKey) {
        const index = localeIndexMap.value[localeKey];

        if (index !== undefined) {
            delete serverErrors[`translations.${index}.name`];
            delete serverErrors[`translations.${index}.locale`];
        }

        delete serverErrors[`translations.${localeKey}.name`];
        delete serverErrors[`translations.${localeKey}.locale`];
        delete serverErrors.translations;
    }

    function resetTranslations() {
        syncTranslationFormKeys(form.translations, localeOrder.value);

        for (const code of localeOrder.value) {
            form.translations[code] = '';
        }

        activeLocale.value = localeOrder.value[0] ?? '';
    }

    function fillTranslations(record) {
        syncTranslationFormKeys(form.translations, localeOrder.value);
        fillCatalogTranslationFields(form.translations, record, localeOrder.value);
        activeLocale.value = localeOrder.value[0] ?? '';
    }

    function buildTranslationsPayload() {
        return localeOrder.value.map((code) => ({
            locale: code,
            name: String(form.translations[code] ?? '').trim(),
        }));
    }

    function focusInvalidTranslationTab() {
        focusFirstInvalidTab(
            getV$(),
            serverErrors,
            (value) => {
                activeLocale.value = value;
            },
            localeOrder.value,
            localeIndexMap.value,
        );
    }

    return {
        activeLocale,
        storableLanguages,
        localeOrder,
        translationRules,
        ensureLanguagesLoaded,
        translationTabFeedback,
        translationTabClass,
        activeTranslationFeedback,
        activeTranslationInputClass,
        activeTranslationMessage,
        translationsGroupMessage,
        onTranslationInput,
        resetTranslations,
        fillTranslations,
        buildTranslationsPayload,
        focusInvalidTranslationTab,
    };
}
