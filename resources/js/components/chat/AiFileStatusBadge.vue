<template>
    <span class="badge fs-11 fw-medium d-inline-flex align-items-center gap-1" :class="badge.class" :title="title">
        <span v-if="status === 'uploading' || status === 'processing'" class="spinner-border spinner-border-sm" style="width: 0.65rem; height: 0.65rem;"></span>
        <i v-else :class="badge.icon"></i>
        {{ badge.label }}
    </span>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

// Doc S8/S36: reflects the real backend lifecycle (never invents a
// fake extra stage), and never relies on color alone - every state
// pairs an icon + text label.
const props = defineProps({
    status: {
        type: String,
        default: 'processing',
    },
    errorMessage: {
        type: String,
        default: null,
    },
});

const { t } = useI18n();

const badge = computed(() => {
    switch (props.status) {
        case 'uploading':
            return { class: 'bg-secondary-transparent', icon: 'ri-upload-2-line', label: t('ai_chat.status_uploading') };
        case 'processing':
            return { class: 'bg-warning-transparent', icon: 'ri-loader-4-line', label: t('ai_chat.status_processing') };
        case 'ready':
            return { class: 'bg-success-transparent', icon: 'ri-checkbox-circle-line', label: t('ai_chat.status_ready') };
        case 'failed':
            return { class: 'bg-danger-transparent', icon: 'ri-error-warning-line', label: t('ai_chat.status_failed') };
        default:
            return { class: 'bg-secondary-transparent', icon: 'ri-question-line', label: props.status };
    }
});

const title = computed(() => (props.status === 'failed' && props.errorMessage) ? props.errorMessage : null);
</script>
