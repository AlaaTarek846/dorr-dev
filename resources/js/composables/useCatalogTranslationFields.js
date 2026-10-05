import { computed, ref } from 'vue';
import { helpers, maxLength, minLength, required } from '@vuelidate/validators';
import { useI18n } from 'vue-i18n';
import { useAvailableLanguagesStore } from '../stores/availableLanguages';
import {
    fillCatalogTranslationObjectFields,
    syncTranslationObjectKeys,
} from '../utils/catalog';
import useValidation from './useValidation';

/**
 * Translation tabs state for entities that translate more than a name
 * (FAQ question/answer, Privacy Policy content), so form.translations[locale]
 * holds an object of field => value instead of a plain string.
 *
 * A field can be optional with `required: false`.
 *
 * The single-field counterpart is useCatalogTranslations, left untouched.
 */
export default function useCatalogTranslationFields(options = {}) {
    const {
        form,
        serverErrors,
        fields = [],
        getV$ = () => null,
    } = options;

    const { t, locale } = useI18n();
    const languagesStore = useAvailableLanguagesStore();
    const { firstError, fieldFeedback } = useValidation();

    const activeLocale = ref('');

    const fieldNames = fields.map((field) => field.name);

    const storableLanguages = computed(() => languagesStore.items);
    const localeOrder = computed(() => storableLanguages.value.map((language) => language.code));
    const localeIndexMap = computed(() => Object.fromEntries(
        localeOrder.value.map((code, index) => [code, String(index)]),
    ));

    function fieldRule(field) {
        const label = t(field.labelKey);
        const max = field.max ?? 255;
        const min = field.min ?? 2;

        const rules = {
            maxLength: helpers.withMessage(
                () => t('validation.max.string', { field: label, max }),
                maxLength(max),
            ),
        };

        // "required: false" makes a translated field optional (e.g. a description).
        if (field.required !== false) {
            rules.required = helpers.withMessage(
                () => t('validation.required', { field: label }),
                required,
            );
        }

        if (field.required !== false && min > 0) {
            rules.minLength = helpers.withMessage(
                () => t('validation.min.string', { field: label, min }),
                minLength(min),
            );
        }

        return rules;
    }

    const translationRules = computed(() => {
        const minItems = Math.max(languagesStore.items.length, 1);

        return {
            required: helpers.withMessage(
                () => t('validation.required', { field: t('validation.attributes.translations') }),
                required,
            ),
            minLength: helpers.withMessage(
                () => t('validation.min.array', {
                    field: t('validation.attributes.translations'),
                    min: minItems,
                }),
                helpers.withParams({ type: 'minLength', min: minItems }, (value) => (
                    Object.keys(value ?? {}).length >= minItems
                )),
            ),
            ...Object.fromEntries(
                localeOrder.value.map((code) => [
                    code,
                    Object.fromEntries(fields.map((field) => [field.name, fieldRule(field)])),
                ]),
            ),
        };
    });

    function defaultLocale() {
        return localeOrder.value.includes(locale.value)
            ? locale.value
            : localeOrder.value[0] ?? '';
    }

    async function ensureLanguagesLoaded() {
        await languagesStore.fetch();
        syncTranslationObjectKeys(form.translations, localeOrder.value, fieldNames);

        if (! activeLocale.value || ! localeOrder.value.includes(activeLocale.value)) {
            activeLocale.value = defaultLocale();
        }
    }

    function fieldServerError(localeKey, field) {
        const index = localeIndexMap.value[localeKey];

        const keys = [
            `translations.${index}.${field}`,
            `translations.${localeKey}.${field}`,
        ].filter((key) => ! key.includes('undefined'));

        return firstError(serverErrors, keys);
    }

    function fieldFeedbackFor(localeKey, field) {
        return fieldFeedback(
            getV$()?.translations?.[localeKey]?.[field],
            fieldServerError(localeKey, field),
            form.translations[localeKey]?.[field],
        );
    }

    function fieldInputClass(localeKey, field) {
        const feedback = fieldFeedbackFor(localeKey, field);

        return {
            'is-invalid': feedback.show && feedback.invalid,
            'is-valid': feedback.show && feedback.valid,
        };
    }

    function fieldMessage(localeKey, field) {
        const feedback = fieldFeedbackFor(localeKey, field);

        if (! feedback.invalid) {
            return null;
        }

        return getV$()?.translations?.[localeKey]?.[field]?.$errors[0]?.$message
            || fieldServerError(localeKey, field);
    }

    function localeFeedback(localeKey) {
        const feedback = fieldNames.map((field) => fieldFeedbackFor(localeKey, field));

        return {
            show: feedback.some((item) => item.show),
            valid: feedback.every((item) => ! item.invalid),
            invalid: feedback.some((item) => item.invalid),
        };
    }

    function translationTabClass(localeKey) {
        const feedback = localeFeedback(localeKey);

        return {
            active: activeLocale.value === localeKey,
            'catalog-lang-tab--error': feedback.show && feedback.invalid,
            'catalog-lang-tab--valid': feedback.show && feedback.valid,
        };
    }

    const translationsGroupMessage = computed(() => {
        const serverMessage = firstError(serverErrors, ['translations']);

        if (serverMessage) {
            return serverMessage;
        }

        const state = getV$()?.translations;

        const parentError = state?.$errors?.find((error) => (
            (error.$validator === 'required' || error.$validator === 'minLength')
            && ! localeOrder.value.includes(error.$property)
        ));

        return parentError?.$message || null;
    });

    function clearTranslationError(localeKey, field) {
        const index = localeIndexMap.value[localeKey];

        if (index !== undefined) {
            delete serverErrors[`translations.${index}.${field}`];
        }

        delete serverErrors[`translations.${localeKey}.${field}`];
        delete serverErrors.translations;
    }

    function onTranslationInput(localeKey, field) {
        clearTranslationError(localeKey, field);
        getV$()?.$touch();
    }

    function resetTranslations() {
        syncTranslationObjectKeys(form.translations, localeOrder.value, fieldNames);

        for (const code of localeOrder.value) {
            form.translations[code] = Object.fromEntries(fieldNames.map((field) => [field, '']));
        }

        activeLocale.value = defaultLocale();
    }

    function fillTranslations(record) {
        syncTranslationObjectKeys(form.translations, localeOrder.value, fieldNames);
        fillCatalogTranslationObjectFields(form.translations, record, localeOrder.value, fieldNames);
        activeLocale.value = defaultLocale();
    }

    function buildTranslationsPayload() {
        return localeOrder.value.map((code) => ({
            locale: code,
            ...Object.fromEntries(
                fieldNames.map((field) => [field, String(form.translations[code]?.[field] ?? '').trim()]),
            ),
        }));
    }

    function focusInvalidTranslationTab() {
        for (const locale of localeOrder.value) {
            if (localeFeedback(locale).invalid) {
                activeLocale.value = locale;

                return;
            }
        }
    }

    return {
        activeLocale,
        storableLanguages,
        localeOrder,
        fieldNames,
        translationRules,
        ensureLanguagesLoaded,
        fieldFeedbackFor,
        fieldInputClass,
        fieldMessage,
        translationTabFeedback: localeFeedback,
        translationTabClass,
        translationsGroupMessage,
        onTranslationInput,
        resetTranslations,
        fillTranslations,
        buildTranslationsPayload,
        focusInvalidTranslationTab,
    };
}
