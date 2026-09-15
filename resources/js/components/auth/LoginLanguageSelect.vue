<template>
    <div
        ref="rootElement"
        class="login-language-select dropdown w-100 mb-3"
        :class="{ 'login-language-select--centered': centered }"
    >
        <label class="form-label text-default mb-1" :class="{ 'w-100 text-center': centered }">
            {{ t('select_language') }}
        </label>

        <button
            type="button"
            class="btn login-language-select__toggle w-100 d-flex align-items-center gap-2"
            :class="{ disabled: loading || ! languages.length }"
            data-bs-toggle="dropdown"
            data-bs-auto-close="true"
            aria-expanded="false"
        >
            <FlagImage
                v-if="selectedFlagCode"
                :key="selectedFlagCode"
                :code="selectedFlagCode"
                :width="24"
                :height="24"
                :size="24"
                class="login-language-select__flag"
            />
            <span v-else class="login-language-select__icon">
                <i class="ri-translate-2"></i>
            </span>

            <span class="flex-grow-1 text-start fw-medium">
                {{ selectedLanguage?.name ?? t('select_language') }}
            </span>

            <i class="ri-arrow-down-s-line login-language-select__caret"></i>
        </button>

        <ul class="dropdown-menu w-100 login-language-select__menu">
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
                            class="login-language-select__option-flag"
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

defineProps({
    centered: {
        type: Boolean,
        default: false,
    },
});

const rootElement = ref(null);

const selectedLanguage = computed(() => languagesStore.findByCode(locale.value));
const selectedFlagCode = computed(() => languageFlagCode(selectedLanguage.value));

function languageFlagCode(language) {
    return resolveLanguageFlagCode(language);
}

onMounted(async () => {
    try {
        if (! languagesStore.loaded) {
            await languagesStore.fetch();
        }

        await localeStore.ensureValidLocale();
    } catch {
        //
    }
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
.login-language-select__toggle {
    min-height: 3rem;
    padding: 0.65rem 0.9rem;
    border: 1px solid var(--input-border, #dee2e6);
    border-radius: 0.5rem;
    background-color: var(--form-control-bg, #fff);
    color: inherit;
}

.login-language-select__toggle.disabled {
    pointer-events: none;
    opacity: 0.65;
}

.login-language-select__flag :deep(.flag-img) {
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 50%;
    object-fit: cover;
}

.login-language-select__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 1.5rem;
    height: 1.5rem;
    color: var(--primary-color, #845adf);
}

.login-language-select__caret {
    color: var(--text-muted, #6c757d);
}

.login-language-select__menu {
    min-width: 100%;
    padding-block: 0.35rem;
}

.login-language-select__option-flag :deep(.flag-img) {
    width: 1.25rem;
    height: 1.25rem;
    border-radius: 2px;
    object-fit: cover;
}

.login-language-select__menu .dropdown-item.active,
.login-language-select__menu .dropdown-item:active {
    background-color: rgba(var(--primary-rgb, 132, 90, 223), 0.12);
    color: inherit;
}
</style>
