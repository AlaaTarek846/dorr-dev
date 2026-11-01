<template>
    <div class="d-flex flex-wrap align-items-center gap-1">
        <button
            v-for="o in options"
            :key="o.value"
            type="button"
            class="btn btn-sm catalog-filter-btn"
            :class="modelValue === o.value ? `catalog-filter-btn--${o.tone || 'all'}` : `catalog-filter-btn--${o.tone || 'all'}-idle`"
            @click="emit('update:modelValue', o.value)"
        >
            {{ o.label }}<template v-if="o.count != null"> ({{ o.count }})</template>
        </button>
    </div>
</template>

<script setup>
/**
 * The filter buttons of the countries / users lists: one button per choice, with its count, in the colour of its tone
 * (all · active · inactive · deleted · pending). The Freelance desks use it for statuses and for the tabs of a list.
 */
defineProps({
    modelValue: { type: String, default: 'all' },
    /** [{ value, label, count?, tone? }] */
    options: { type: Array, required: true },
});

const emit = defineEmits(['update:modelValue']);
</script>
