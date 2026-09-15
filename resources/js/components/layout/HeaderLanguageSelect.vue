<template>
    <div ref="rootElement" class="dropdown header-language-dropdown">
        <a
            href="javascript:void(0);"
            class="header-link dropdown-toggle header-language-dropdown__toggle"
            :class="{ disabled: loading || !languages.length }"
            data-bs-toggle="dropdown"
            data-bs-auto-close="true"
            aria-expanded="false"
        >
            <FlagImage
                v-if="selectedFlagCode"
                :key="selectedFlagCode"
                :code="selectedFlagCode"
                :width="28"
                :height="28"
                :size="28"
                class="header-language-dropdown__flag"
            />
            <span v-else class="avatar avatar-sm avatar-rounded bg-primary-transparent header-link-icon">
                <i class="ri-translate-2 fs-16 text-primary"></i>
            </span>
            <span class="fw-semibold mb-0 lh-1 d-none d-sm-inline">
                {{ selectedLanguage?.name ?? t('select_language') }}
            </span>
        </a>

        <ul class="dropdown-menu dropdown-menu-end header-language-dropdown__menu">
            <template v-if="loading">
                <li>
                    <span class="dropdown-item-text text-muted py-2">
                        {{ t('languages.loading') }}
                    </span>
                </li>
            </template>

            <template v-else-if="! languages.length">
                <li>
                    <span class="dropdown-item-text text-muted py-2">
                        {{ t('languages.empty') }}
                    </span>
                </li>
            </template>

            <template v-else>
                <li v-for="language in languages" :key="language.code">
                    <button
                        type="button"
                        class="dropdown-item d-flex align-items-center gap-2 py-2"
                        :class="{ active: language.code === locale }"
                        @click="selectLanguage(language.code)"
                    >
                        <FlagImage
                            :key="`${language.code}-${languageFlagCode(language)}`"
                            :code="languageFlagCode(language)"
                            :width="20"
                            :height="20"
                            :size="20"
                            class="header-language-dropdown__option-flag"
                        />
                        <span class="flex-grow-1 text-start">{{ language.name }}</span>
                        <i
                            v-if="language.code === locale"
                            class="ri-check-line text-success fs-16"
                        ></i>
                    </button>
                </li>
            </template>
        </ul>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import FlagImage from '../ui/FlagImage.vue';
import { useAvailableLanguagesStore } from '../../stores/availableLanguages';
import { useLocaleStore } from '../../stores/locale';
import { resolveLanguageFlagCode } from '../../utils/catalog';

const { t } = useI18n();
const localeStore = useLocaleStore();
const languagesStore = useAvailableLanguagesStore();
const { items: languages, loading } = storeToRefs(languagesStore);
const { locale } = storeToRefs(localeStore);

const rootElement = ref(null);

const selectedLanguage = computed(() => languagesStore.findByCode(locale.value));

const selectedFlagCode = computed(() => languageFlagCode(selectedLanguage.value));

function languageFlagCode(language) {
    return resolveLanguageFlagCode(language);
}

onMounted(async () => {
    await languagesStore.fetch();
    await localeStore.ensureValidLocale();
});

function selectLanguage(code) {
    localeStore.setLocale(code);
    closeDropdown();
}

function closeDropdown() {
    const toggle = rootElement.value?.querySelector('[data-bs-toggle="dropdown"]');

    if (! toggle || ! window.bootstrap?.Dropdown) {
        return;
    }

    window.bootstrap.Dropdown.getInstance(toggle)?.hide();
}
</script>

<style scoped>
.header-language-dropdown__toggle.disabled {
    pointer-events: none;
    opacity: 0.65;
}

.header-language-dropdown__flag :deep(.flag-img) {
    width: 1.75rem;
    height: 1.75rem;
    border-radius: 50%;
    object-fit: cover;
}

.header-language-dropdown__menu {
    min-width: 11rem;
    padding-block: 0.35rem;
}

.header-language-dropdown__option-flag :deep(.flag-img) {
    width: 1.25rem;
    height: 1.25rem;
    border-radius: 2px;
}

.header-language-dropdown__menu .dropdown-item.active,
.header-language-dropdown__menu .dropdown-item:active {
    background-color: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: inherit;
}
</style>
