<template>
    <span class="badge mk-pill" :class="`bg-${tone}-transparent`">{{ t(statusKey(status)) }}</span>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { statusKey, statusTone } from '../marketingContext';

const props = defineProps({
    status: { type: String, default: 'DRAFT' },
});

const { t } = useI18n();
const tone = computed(() => statusTone(props.status));
</script>
