<template>
    <div
        v-if="hasContent"
        class="dorr-rich-text-content"
        :class="contentClass"
        v-html="modelValue"
    ></div>
    <span v-else-if="showEmptyPlaceholder" class="text-muted">{{ emptyText }}</span>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    /** Trusted HTML from CatalogRichTextEditor (admin-authored). */
    modelValue: { type: String, default: '' },
    contentClass: { type: [String, Array, Object], default: null },
    showEmptyPlaceholder: { type: Boolean, default: false },
    emptyText: { type: String, default: '—' },
});

const hasContent = computed(() => {
    const html = String(props.modelValue ?? '').trim();

    return html !== '' && html !== '<p><br></p>';
});
</script>
