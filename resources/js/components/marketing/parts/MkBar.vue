<template>
    <div class="mk-bar" role="progressbar" :aria-valuenow="pct" aria-valuemin="0" aria-valuemax="100">
        <span :style="{ width: `${pct}%`, background: color || undefined }"></span>
    </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
    value: { type: Number, default: 0 },
    color: { type: String, default: '' },
});

const pct = computed(() => Math.max(0, Math.min(100, Math.round(props.value || 0))));
</script>
