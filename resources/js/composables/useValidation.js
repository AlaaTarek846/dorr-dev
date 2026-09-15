import { helpers, maxLength, required } from '@vuelidate/validators';
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

    function translationNameRule(nameKey, localeKey, max = 255) {
        const fieldLabel = `${t(nameKey)} (${t(localeKey)})`;

        return {
            required: helpers.withMessage(
                () => t('validation.required', { field: fieldLabel }),
                required,
            ),
            maxLength: helpers.withMessage(
                () => t('validation.max.string', { field: fieldLabel, max }),
                maxLength(max),
            ),
        };
    }

    function translationNameRuleWithLabel(nameKey, localeLabel, max = 255) {
        const fieldLabel = `${t(nameKey)} (${localeLabel})`;

        return {
            required: helpers.withMessage(
                () => t('validation.required', { field: fieldLabel }),
                required,
            ),
            maxLength: helpers.withMessage(
                () => t('validation.max.string', { field: fieldLabel, max }),
                maxLength(max),
            ),
        };
    }

    function catalogTranslationRulesFromLanguages(nameKey, languages = [], max = 255) {
        return Object.fromEntries(
            languages.map((language) => [
                language.code,
                translationNameRuleWithLabel(nameKey, language.name, max),
            ]),
        );
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

    function stringFieldRules(fieldKey, max) {
        return {
            required: requiredField(fieldKey),
            maxLength: maxString(fieldKey, max),
        };
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
            if (errors[key]?.[0]) {
                return errors[key][0];
            }
        }

        return null;
    }

    function fieldFeedback(vuelidateField, serverError, value) {
        const trimmed = String(value ?? '').trim();
        const interacted = Boolean(vuelidateField?.$dirty) || trimmed.length > 0;

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
                `translations.${locale}.name`,
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
