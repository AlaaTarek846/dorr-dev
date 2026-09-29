<template>
    <span
        class="badge"
        :class="badge.class"
        :title="testError || ''"
    >
        {{ badge.label }}
    </span>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

const props = defineProps({
    testStatus: {
        type: String,
        default: 'never_tested',
    },
    testError: {
        type: String,
        default: null,
    },
});

const { t } = useI18n();

const badge = computed(() => {
    if (props.testStatus === 'passed') {
        return {
            class: 'bg-success-transparent',
            label: t('sms.test_status_passed'),
        };
    }

    if (props.testStatus === 'failed') {
        return {
            class: 'bg-danger-transparent',
            label: t('sms.test_status_failed'),
        };
    }

    return {
        class: 'bg-secondary-transparent',
        label: t('sms.test_status_never'),
    };
});
</script>