<template>
    <div>
        <CatalogTranslationTabs
            :languages="languages"
            :active-locale="locale"
            :translation-tab-class="tabClass"
            :translation-tab-feedback="tabFeedback"
            @update:active-locale="locale = $event"
        />
        <slot :locale="locale" :dir="locale === 'ar' ? 'rtl' : 'ltr'" />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import CatalogTranslationTabs from '../../../../../../components/catalog/CatalogTranslationTabs.vue';
import { useAvailableLanguagesStore } from '../../../../../../stores/availableLanguages';

/**
 * O-05: the Rides cards' names in Arabic and English, behind the same language tabs as every other card
 * in the dashboard (a tick once a language is filled, a warning after a failed save). The Rides API keeps
 * `{ar, en}` objects, so the fields bind to `obj[locale]` through the slot.
 */
const props = defineProps({
    /** The `{ar, en}` objects that must be filled for a language's tab to show its tick. */
    required: { type: Array, default: () => [] },
    /** True after a refused save: an empty language shows its warning. */
    invalid: { type: Boolean, default: false },
});

const CODES = ['ar', 'en'];
const store = useAvailableLanguagesStore();
const languages = computed(() => {
    const fromStore = (store.items ?? []).filter((l) => CODES.includes(l.code));
    return fromStore.length ? fromStore : [{ code: 'ar', name: 'العربية' }, { code: 'en', name: 'English' }];
});
const locale = ref('ar');

const filled = (code) => props.required.every((obj) => !!String(obj?.[code] ?? '').trim());

function tabClass(code) {
    return { active: locale.value === code, 'catalog-lang-tab--error': props.invalid && !filled(code), 'catalog-lang-tab--valid': filled(code) };
}

function tabFeedback(code) {
    return { show: filled(code) || props.invalid, valid: filled(code) };
}

onMounted(() => {
    if (!(store.items ?? []).length) store.fetch?.();
});
</script>
