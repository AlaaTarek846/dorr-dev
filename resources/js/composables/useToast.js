import { useI18n } from 'vue-i18n';
import { useToastStore } from '../stores/toast';

export function extractApiMessage(response, fallback = '') {
    return response?.data?.message || fallback;
}

export function extractApiErrorMessage(error, fallback = '') {
    return error?.response?.data?.message || fallback;
}

/**
 * Pick toast type for bulk delete responses.
 *
 * @returns {{ type: 'success' | 'warning' | 'danger', message: string }}
 */
export function resolveBulkDeleteFeedback(response, fallback = '') {
    const data = response?.data?.data ?? {};
    const deleted = Number(data.deleted ?? 0);
    const skipped = Number(data.skipped ?? 0);
    const message = extractApiMessage(response, fallback);

    if (deleted > 0 && skipped > 0) {
        return { type: 'warning', message };
    }

    if (deleted > 0) {
        return { type: 'success', message };
    }

    return { type: 'danger', message };
}

export default function useToast() {
    const store = useToastStore();
    const { t } = useI18n();

    function show(message, type = 'success', duration = 4000) {
        store.push({ message, type, duration });
    }

    function showSuccess(message) {
        show(message || t('toast.success'), 'success');
    }

    function showError(message) {
        show(message || t('toast.error'), 'danger');
    }

    function showInfo(message) {
        show(message || t('toast.info'), 'info');
    }

    function showWarning(message) {
        show(message || t('toast.warning'), 'warning');
    }

    return {
        show,
        showSuccess,
        showError,
        showInfo,
        showWarning,
    };
}
