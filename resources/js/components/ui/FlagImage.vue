<template>
    <img
        v-if="code && currentSrc"
        :key="`${code}-${sourceIndex}`"
        :src="currentSrc"
        :alt="alt || code"
        class="flag-img"
        :width="width"
        :height="height"
        :data-flag-code="code"
        :data-flag-size="size"
        :data-source-index="sourceIndex"
        loading="lazy"
        decoding="async"
        @error="onError"
    >
    <span
        v-else-if="code"
        class="flag-img flag-img--placeholder"
        :style="placeholderStyle"
    >
        {{ displayCode }}
    </span>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { flagImageSources } from '../../utils/catalog';

const props = defineProps({
    code: {
        type: String,
        default: '',
    },
    size: {
        type: Number,
        default: 32,
    },
    width: {
        type: Number,
        default: 32,
    },
    height: {
        type: Number,
        default: 24,
    },
    alt: {
        type: String,
        default: '',
    },
});

const sourceIndex = ref(0);

const sources = computed(() => flagImageSources(props.code, props.size));
const currentSrc = computed(() => sources.value[sourceIndex.value] ?? '');
const displayCode = computed(() => String(props.code ?? '').slice(0, 2).toUpperCase());
const placeholderStyle = computed(() => ({
    width: `${props.width}px`,
    height: `${props.height}px`,
}));

watch(
    () => [props.code, props.size],
    () => {
        sourceIndex.value = 0;
    },
);

function onError() {
    if (sourceIndex.value < sources.value.length - 1) {
        sourceIndex.value += 1;

        return;
    }

    sourceIndex.value = sources.value.length;
}
</script>

<style scoped>
.flag-img {
    display: block;
    object-fit: cover;
    border-radius: 2px;
    flex-shrink: 0;
}

.flag-img--placeholder {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 2px;
    background: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: rgb(var(--primary-rgb, 132, 90, 223));
    font-size: 0.625rem;
    font-weight: 700;
    line-height: 1;
    letter-spacing: 0.02em;
}
</style>
