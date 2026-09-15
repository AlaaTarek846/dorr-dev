import { useI18n } from 'vue-i18n';
import { useToastStore } from '../stores/toast';

export function extractApiMessage(response, fallback = '') {
    return response?.data?.message || fallback;
}

export function extractApiErrorMessage(error, fallback = '') {
    return error?.response?.data?.message || fallback;
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
