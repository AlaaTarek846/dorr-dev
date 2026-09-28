<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_language_preference.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_language_preference.subtitle') }}</span>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-xl-8">
                <div class="card custom-card">
                    <div class="card-header">
                        <div class="card-title">{{ t('ai_language_preference.title') }}</div>
                    </div>
                    <div class="card-body">
                        <div v-if="loading" class="text-center py-5">
                            <span class="spinner-border spinner-border-sm"></span>
                        </div>

                        <form v-else @submit.prevent="submit">
                            <div class="mb-4">
                                <label class="form-label fw-semibold d-block mb-2">
                                    {{ t('ai_language_preference.mode_label') }}
                                </label>

                                <div class="form-check mb-2">
                                    <input
                                        id="ai-lang-mode-follow"
                                        v-model="form.response_language_mode"
                                        class="form-check-input"
                                        type="radio"
                                        value="follow_input"
                                        @change="onModeChange"
                                    >
                                    <label class="form-check-label" for="ai-lang-mode-follow">
                                        {{ t('ai_language_preference.mode_follow_input') }}
                                    </label>
                                    <div class="fs-12 text-muted">{{ t('ai_language_preference.mode_follow_input_hint') }}</div>
                                </div>

                                <div class="form-check">
                                    <input
                                        id="ai-lang-mode-fixed"
                                        v-model="form.response_language_mode"
                                        class="form-check-input"
                                        type="radio"
                                        value="fixed"
                                        @change="onModeChange"
                                    >
                                    <label class="form-check-label" for="ai-lang-mode-fixed">
                                        {{ t('ai_language_preference.mode_fixed') }}
                                    </label>
                                    <div class="fs-12 text-muted">{{ t('ai_language_preference.mode_fixed_hint') }}</div>
                                </div>
                            </div>

                            <div v-if="form.response_language_mode === 'fixed'" class="row gy-3 mb-4">
                                <div class="col-md-6">
                                    <label for="ai-lang-language" class="form-label">
                                        {{ t('ai_language_preference.language') }}
                                        <span class="text-danger">*</span>
                                    </label>
                                    <select
                                        id="ai-lang-language"
                                        v-model="form.language_id"
                                        class="form-select"
                                        :class="{ 'is-invalid': errors.language_id?.[0] }"
                                        @change="onLanguageChange"
                                    >
                                        <option :value="null">{{ t('ai_language_preference.select_language') }}</option>
                                        <option v-for="language in languages" :key="language.id" :value="language.id">
                                            {{ language.name }}
                                        </option>
                                    </select>
                                    <div v-if="errors.language_id?.[0]" class="invalid-feedback d-block">
                                        {{ errors.language_id[0] }}
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <label for="ai-lang-variant" class="form-label">
                                        {{ t('ai_language_preference.variant') }}
                                    </label>
                                    <select
                                        id="ai-lang-variant"
                                        v-model="form.variant_id"
                                        class="form-select"
                                        :disabled="! form.language_id || variantsForSelectedLanguage.length === 0"
                                    >
                                        <option :value="null">{{ t('ai_language_preference.no_variant') }}</option>
                                        <option v-for="variant in variantsForSelectedLanguage" :key="variant.id" :value="variant.id">
                                            {{ variant.name }}
                                        </option>
                                    </select>
                                    <div v-if="form.language_id && variantsForSelectedLanguage.length === 0" class="fs-12 text-muted mt-1">
                                        {{ t('ai_language_preference.no_variants_available') }}
                                    </div>
                                </div>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-primary" :disabled="submitting">
                                    <span v-if="submitting" class="spinner-border spinner-border-sm me-1"></span>
                                    {{ t('save_changes') }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import userAxios from '../../../../../../api/userAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();

const loading = ref(true);
const submitting = ref(false);
const errors = reactive({});
const languages = ref([]);
const variants = ref([]);

const form = reactive({
    response_language_mode: 'follow_input',
    language_id: null,
    variant_id: null,
});

const variantsForSelectedLanguage = computed(() => {
    if (! form.language_id) {
        return [];
    }

    return variants.value.filter((variant) => variant.language_id === form.language_id);
});

function onModeChange() {
    if (form.response_language_mode === 'follow_input') {
        form.language_id = null;
        form.variant_id = null;
    }
}

function onLanguageChange() {
    // A previously picked variant almost certainly belongs to the old
    // language, so it is cleared whenever the language changes rather
    // than silently keeping a mismatched variant selected.
    form.variant_id = null;
}

function fillForm(preference) {
    form.response_language_mode = preference?.response_language_mode ?? 'follow_input';
    form.language_id = preference?.language?.id ?? null;
    form.variant_id = preference?.variant?.id ?? null;
}

onMounted(async () => {
    try {
        const { data } = await userAxios.get('/api/user/v1/ai-language-preference');
        fillForm(data.data.preference);
        languages.value = data.data.languages ?? [];
        variants.value = data.data.variants ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
});

async function submit() {
    submitting.value = true;
    Object.keys(errors).forEach((key) => delete errors[key]);

    try {
        const response = await userAxios.put('/api/user/v1/ai-language-preference', {
            response_language_mode: form.response_language_mode,
            language_id: form.language_id,
            variant_id: form.variant_id,
        });

        fillForm(response.data.data);
        showSuccess(extractApiMessage(response, t('save_changes')));
    } catch (error) {
        if (error.response?.status === 422) {
            Object.assign(errors, error.response.data.errors ?? {});
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error));
        }
    } finally {
        submitting.value = false;
    }
}
</script>
