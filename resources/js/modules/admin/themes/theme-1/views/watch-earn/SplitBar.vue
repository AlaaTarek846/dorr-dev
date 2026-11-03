<template>
    <!-- The price of a view, split: the platform, the viewer for watching, the viewer for interacting -->
    <div>
        <div class="we-split">
            <span v-for="part in parts" :key="part.key" :class="`we-split__${part.key}`" :style="{ width: part.width + '%' }" :title="`${t(`watch_earn.split.${part.key}`)}: ${part.label}`"></span>
        </div>
        <div class="d-flex flex-wrap gap-3 mt-2 fs-12">
            <span v-for="part in parts" :key="part.key"><i :class="`we-dot we-split__${part.key}`"></i>{{ t(`watch_earn.split.${part.key}`) }}: <b>{{ part.label }}</b></span>
            <span class="text-muted ms-auto">{{ t('watch_earn.split.price') }}: <b>{{ money(price, currency, locale) }}</b></span>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { money } from './money';

const props = defineProps({
    split: { type: Object, default: () => ({}) },
    price: { type: Number, default: 0 },
    currency: { type: Object, default: () => ({ decimals: 2 }) },
});
const { t, locale } = useI18n();

const parts = computed(() => [
    ['platform', props.split?.platform_share_minor],
    ['view', props.split?.view_reward_minor],
    ['interaction', props.split?.interaction_reward_minor],
].map(([key, minor]) => ({
    key,
    width: props.price > 0 ? Math.round(((minor || 0) * 100) / props.price) : 0,
    label: money(minor || 0, props.currency, locale.value),
})));
</script>

<style scoped>
.we-split { display: flex; height: 14px; border-radius: 8px; overflow: hidden; background: #eee; }
.we-split span { transition: width .4s ease; }
.we-split__platform { background: #845adf; }
.we-split__view { background: #23b7e5; }
.we-split__interaction { background: #f5b849; }
.we-dot { display: inline-block; width: 10px; height: 10px; border-radius: 50%; margin-inline-end: 6px; vertical-align: middle; }
</style>
