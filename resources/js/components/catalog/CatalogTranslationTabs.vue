<template>
    <div
        v-if="languages.length"
        class="catalog-lang-tabs d-flex gap-2 mb-4 flex-wrap"
    >
        <button
            v-for="language in languages"
            :key="language.code"
            type="button"
            class="catalog-lang-tab"
            :class="translationTabClass(language.code)"
            @click="emit('update:activeLocale', language.code)"
        >
            <FlagImage
                :code="resolveLanguageFlagCode(language)"
                :size="16"
                :width="20"
                :height="15"
                class="catalog-lang-tab__icon"
                :alt="language.name"
            />
            {{ language.name }}
            <i
                v-if="translationTabFeedback(language.code).show"
                :class="translationTabFeedback(language.code).valid ? 'ri-check-line' : 'ri-error-warning-line'"
            ></i>
        </button>
    </div>
</template>

<script setup>
import FlagImage from '../ui/FlagImage.vue';
import { resolveLanguageFlagCode } from '../../utils/catalog';

defineProps({
    languages: {
        type: Array,
        default: () => [],
    },
    activeLocale: {
        type: String,
        default: '',
    },
    translationTabClass: {
        type: Function,
        required: true,
    },
    translationTabFeedback: {
        type: Function,
        required: true,
    },
});

const emit = defineEmits(['update:activeLocale']);
</script>
