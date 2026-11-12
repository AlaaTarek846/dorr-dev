<template>
    <div class="mk-head">
        <router-link v-if="back" :to="back" class="btn btn-light mk-back" :aria-label="t('marketing.back')">
            <i class="ri-arrow-left-line fs-18 mk-flip"></i>
        </router-link>
        <div class="mk-head__text">
            <h1 class="mk-head__title">{{ title }}</h1>
            <span v-if="subtitle" class="text-muted fs-13">{{ subtitle }}</span>
        </div>
        <div v-if="$slots.default" class="d-flex flex-wrap gap-2">
            <slot />
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    back: { type: Object, default: null },
});

const { t } = useI18n();
</script>
