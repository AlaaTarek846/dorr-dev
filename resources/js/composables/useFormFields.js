import useValidation from './useValidation';

/**
 * Template helpers for forms that use Vuelidate + server errors, so a page does not repeat the
 * feedback / input-class / message computed trio for every field (see the catalog modals).
 *
 *   const { feedbackOf, classOf, invalidOf, messageOf, onInput } = useFormFields({
 *       getV$: () => v$.value, form, serverErrors, serverKeys: { amount: 'amount_minor' },
 *   });
 *
 * `serverKeys` maps a form key to the API error key when they differ.
 */
export default function useFormFields({
    getV$,
    form,
    serverErrors,
    serverKeys = {},
}) {
    const { fieldFeedback } = useValidation();

    const serverKey = (key) => serverKeys[key] ?? key;
    const serverMessage = (key) => serverErrors[serverKey(key)]?.[0] ?? null;

    function feedbackOf(key) {
        return fieldFeedback(getV$()?.[key], serverMessage(key), form[key]);
    }

    function invalidOf(key) {
        const feedback = feedbackOf(key);

        return feedback.show && feedback.invalid;
    }

    function classOf(key) {
        const feedback = feedbackOf(key);

        return {
            'is-invalid': feedback.show && feedback.invalid,
            'is-valid': feedback.show && feedback.valid,
        };
    }

    function messageOf(key) {
        if (! feedbackOf(key).invalid) {
            return null;
        }

        return getV$()?.[key]?.$errors?.[0]?.$message || serverMessage(key) || null;
    }

    function onInput(key) {
        delete serverErrors[serverKey(key)];
        getV$()?.[key]?.$touch?.();
    }

    return { feedbackOf, invalidOf, classOf, messageOf, onInput };
}
