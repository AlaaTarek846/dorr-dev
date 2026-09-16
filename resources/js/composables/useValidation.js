import { helpers, maxLength, minLength, required } from '@vuelidate/validators';
import { useI18n } from 'vue-i18n';

const DEFAULT_LOCALES = {
    en: 'flags.lang_en',
    ar: 'flags.lang_ar',
};

/**
 * Reusable validation helpers with vue-i18n messages.
 */
export default function useValidation() {
    const { t } = useI18n();

    function requiredField(fieldKey, labelParams = {}) {
        return helpers.withMessage(
            () => t('validation.required', { field: t(fieldKey, labelParams) }),
            required,
        );
    }

    function maxString(fieldKey, max, labelParams = {}) {
        return helpers.withMessage(
            () => t('validation.max.string', { field: t(fieldKey, labelParams), max }),
            maxLength(max),
        );
    }

    function attributeLabel(attributeKey) {
        return t(`validation.attributes.${attributeKey}`);
    }

    function minArray(fieldKey, min, labelParams = {}) {
        return helpers.withMessage(
            () => t('validation.min.array', { field: t(fieldKey, labelParams), min }),
            helpers.withParams({ type: 'minLength', min }, (value) => {
                const count = Array.isArray(value)
                    ? value.length
                    : Object.keys(value ?? {}).length;

                return count >= min;
            }),
        );
    }

    function minString(fieldKey, min, labelParams = {}) {
        return helpers.withMessage(
            () => t('validation.min.string', { field: t(fieldKey, labelParams), min }),
            minLength(min),
        );
    }

    function digitsBetween(fieldKey, min, max, { optional = false } = {}) {
        return helpers.withMessage(
            () => t('validation.digits_between', { field: t(fieldKey), min, max }),
            (value) => {
                const trimmed = String(value ?? '').trim();

                if (! trimmed) {
                    return optional;
                }

                return new RegExp(`^\\d{${min},${max}}$`).test(trimmed);
            },
        );
    }

    function translationNameRule(nameKey, localeKey, max = 255, min = 0) {
        return translationNameBackendRules(max, min);
    }

    function translationNameRuleWithLabel(nameKey, localeLabel, max = 255, min = 0) {
        return translationNameBackendRules(max, min);
    }

    function translationNameBackendRules(max = 255, min = 0) {
        const fieldLabel = attributeLabel('name');
        const rules = {
            required: helpers.withMessage(
                () => t('validation.required', { field: fieldLabel }),
                required,
            ),
            maxLength: helpers.withMessage(
                () => t('validation.max.string', { field: fieldLabel, max }),
                maxLength(max),
            ),
        };

        if (min > 0) {
            rules.minLength = helpers.withMessage(
                () => t('validation.min.string', { field: fieldLabel, min }),
                minLength(min),
            );
        }

        return rules;
    }

    function catalogTranslationRulesFromLanguages(nameKey, languages = [], max = 255, min = 0) {
        const minItems = Math.max(languages.length, 1);

        return {
            required: requiredField('validation.attributes.translations'),
            minLength: minArray('validation.attributes.translations', minItems),
            ...Object.fromEntries(
                languages.map((language) => [
                    language.code,
                    translationNameRuleWithLabel(nameKey, language.name, max, min),
                ]),
            ),
        };
    }

    function catalogTranslationRules(nameKey, options = {}) {
        const {
            max = 255,
            locales = DEFAULT_LOCALES,
        } = options;

        return Object.fromEntries(
            Object.entries(locales).map(([locale, localeKey]) => [
                locale,
                translationNameRule(nameKey, localeKey, max),
            ]),
        );
    }

    function stringFieldRules(fieldKey, max, min = 0) {
        const rules = {
            required: requiredField(fieldKey),
            maxLength: maxString(fieldKey, max),
        };

        if (min > 0) {
            rules.minLength = minString(fieldKey, min);
        }

        return rules;
    }

    /** @deprecated Use stringFieldRules('flags.code', 3) */
    function flagCodeRules() {
        return stringFieldRules('flags.code', 3);
    }

    /** @deprecated Use catalogTranslationRules('flags.name', { max: 50 }) */
    function flagTranslationRules() {
        return catalogTranslationRules('flags.name', {
            max: 50,
            locales: DEFAULT_LOCALES,
        });
    }

    function applyApiErrors(target, apiErrors = {}) {
        Object.keys(target).forEach((key) => delete target[key]);
        Object.assign(target, apiErrors);
    }

    function firstError(errors, keys) {
        for (const key of keys) {
            const value = errors[key];

            if (typeof value === 'string' && value) {
                return value;
            }

            if (Array.isArray(value) && typeof value[0] === 'string' && value[0]) {
                return value[0];
            }
        }

        return null;
    }

    function fieldFeedback(vuelidateField, serverError, value) {
        const trimmed = String(value ?? '').trim();
        const formDirty = Boolean(vuelidateField?.$dirty) || Boolean(vuelidateField?.$anyDirty);
        const interacted = formDirty || trimmed.length > 0;

        if (! interacted) {
            return { show: false, valid: false, invalid: false };
        }

        const invalid = Boolean(serverError) || Boolean(vuelidateField?.$error);
        const valid = ! invalid && trimmed.length > 0;

        return { show: true, valid, invalid };
    }

    function focusFirstInvalidTab(
        v$,
        serverErrors,
        setActiveLocale,
        localeOrder = ['ar', 'en'],
        localeIndexMap = null,
    ) {
        for (const locale of localeOrder) {
            const hasClientError = v$?.translations?.[locale]?.$error;
            const index = localeIndexMap?.[locale] ?? (locale === 'en' ? '0' : '1');
            const hasServerError = firstError(serverErrors, [
                `translations.${index}.name`,
                `translations.${index}.locale`,
                `translations.${locale}.name`,
                `translations.${locale}.locale`,
            ]);

            if (hasClientError || hasServerError) {
                setActiveLocale(locale);
                return;
            }
        }
    }

    return {
        requiredField,
        maxString,
        minString,
        digitsBetween,
        minArray,
        translationNameRuleWithLabel,
        catalogTranslationRulesFromLanguages,
        translationNameRule,
        catalogTranslationRules,
        stringFieldRules,
        flagCodeRules,
        flagTranslationRules,
        applyApiErrors,
        firstError,
        fieldFeedback,
        focusFirstInvalidTab,
    };
}
