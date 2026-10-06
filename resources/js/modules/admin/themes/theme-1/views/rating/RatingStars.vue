<template>
    <span class="rating-stars" dir="ltr" :style="{ fontSize: size }" :aria-label="`${value} / 5`">
        <span v-for="n in 5" :key="n" class="rating-star">
            <i class="ri-star-line rating-star__empty"></i>
            <i class="ri-star-fill rating-star__fill" :style="{ width: fillWidth(n) }"></i>
        </span>
    </span>
</template>

<script setup>
/** Five stars where each can be partly filled, so quarter and half ratings (3.25, 4.5) show exactly. */
const props = defineProps({
    value: { type: Number, default: 0 },
    size: { type: String, default: '1rem' },
});

function fillWidth(n) {
    const part = Math.min(1, Math.max(0, props.value - (n - 1)));

    return `${part * 100}%`;
}
</script>

<style scoped>
.rating-stars {
    display: inline-flex;
    gap: 2px;
    line-height: 1;
}

.rating-star {
    position: relative;
    display: inline-block;
    width: 1em;
    height: 1em;
}

.rating-star i {
    position: absolute;
    inset: 0 auto 0 0;
    line-height: 1;
}

.rating-star__empty {
    color: var(--default-border, #d5d9e2);
}

.rating-star__fill {
    overflow: hidden;
    color: #f5b301;
}
</style>
