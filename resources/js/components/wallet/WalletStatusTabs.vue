<template>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <!-- Like the currencies page: "All" only shows while Active or Inactive is chosen (it is how you go back). -->
        <button
            v-if="modelValue !== 'all'"
            type="button"
            class="btn btn-sm catalog-filter-btn"
            :class="modelValue === 'all' ? 'catalog-filter-btn--all' : 'catalog-filter-btn--all-idle'"
            @click="emit('update:modelValue', 'all')"
        >
            {{ t('currencies.filter_all') }} ({{ counts.total ?? '…' }})
        </button>
        <button
            type="button"
            class="btn btn-sm catalog-filter-btn"
            :class="modelValue === 'active' ? 'catalog-filter-btn--active' : 'catalog-filter-btn--active-idle'"
            @click="emit('update:modelValue', 'active')"
        >
            {{ t('currencies.filter_active') }} ({{ counts.active ?? '…' }})
        </button>
        <button
            type="button"
            class="btn btn-sm catalog-filter-btn"
            :class="modelValue === 'inactive' ? 'catalog-filter-btn--inactive' : 'catalog-filter-btn--inactive-idle'"
            @click="emit('update:modelValue', 'inactive')"
        >
            {{ t('currencies.filter_inactive') }} ({{ counts.inactive ?? '…' }})
        </button>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

/** The currencies page's Active / Inactive buttons (and All, while one of them is chosen), with counts, for the wallet lists that have an on/off status. */
defineProps({
    modelValue: { type: String, default: 'all' },
    counts: { type: Object, default: () => ({}) },
});

const emit = defineEmits(['update:modelValue']);
const { t } = useI18n();
</script>
