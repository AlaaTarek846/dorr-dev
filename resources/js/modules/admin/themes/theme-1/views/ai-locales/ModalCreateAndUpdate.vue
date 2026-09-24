<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">{{ modalTitle }}</h6>
                        <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>

                <form @submit.prevent="submit">
                    <div class="modal-body px-4 pb-2">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="locale-language" class="form-label">
                                    {{ t('ai_locales.language') }} <span class="text-danger">*</span>
                                </label>
                                <select
                                    id="locale-language"
                                    v-model="form.language_id"
                                    class="form-select"
                                    :class="fieldClass('language_id')"
                                    @change="onFieldInput('language_id')"
                                >
                                    <option :value="null">{{ t('ai_locales.select_language') }}</option>
                                    <option v-for="language in languages" :key="language.id" :value="language.id">{{ language.name }}</option>
                                </select>
                                <div v-if="fieldMessage('language_id')" class="invalid-feedback d-block">{{ fieldMessage('language_id') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="locale-code" class="form-label">
                                    {{ t('ai_locales.code') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="locale-code"
                                    v-model="form.code"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('code')"
                                    :placeholder="t('ai_locales.code_placeholder')"
                                    @input="onFieldInput('code')"
                                >
                                <div v-if="fieldMessage('code')" class="invalid-feedback d-block">{{ fieldMessage('code') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="locale-name" class="form-label">
                                    {{ t('ai_locales.name') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="locale-name"
                                    v-model="form.name"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('name')"
                                    :placeholder="t('ai_locales.name_placeholder')"
                                    @input="onFieldInput('name')"
                                >
                                <div v-if="fieldMessage('name')" class="invalid-feedback d-block">{{ fieldMessage('name') }}</div>
                            </div>

                            <div class="col-12">
                                <label for="locale-settings" class="form-label">{{ t('ai_locales.settings') }}</label>
                                <textarea
                                    id="locale-settings"
                                    v-model="settingsText"
                                    class="form-control font-monospace"
                                    rows="3"
                                    :class="{ 'is-invalid': settingsError }"
                                    :placeholder="t('ai_locales.settings_placeholder')"
                                ></textarea>
                                <div v-if="settingsError" class="invalid-feedback d-block">{{ t('ai_locales.settings_invalid_json') }}</div>
                                <small class="text-muted">{{ t('ai_locales.settings_hint') }}</small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_locales.is_active') }}</label>
                                <div
                                    class="toggle toggle-success mb-0"
                                    :class="{ on: form.is_active }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_active = !form.is_active"
                                    @keydown.enter.space.prevent="form.is_active = !form.is_active"
                                >
                                    <span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_locales.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { helpers, required } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import useValidation from '../../../../../../composables/useValidation';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { stringFieldRules, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
const languages = ref([]);
const settingsText = ref('');
const settingsError = ref(false);
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    language_id: null,
    code: '',
    name: '',
    is_active: true,
});

const rules = computed(() => ({
    language_id: {
        required: helpers.withMessage(
            () => t('validation.required', { field: t('ai_locales.language') }),
            required,
        ),
    },
    code: stringFieldRules('ai_locales.code', 20),
    name: stringFieldRules('ai_locales.name', 100),
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_locales.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_locales.create_title')
));

async function loadLanguages() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-languages');
        languages.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function fieldClass(field) {
    const feedback = fieldFeedback(v$.value[field], serverErrors[field]?.[0], form[field]);
    return { 'is-invalid': feedback.show && feedback.invalid, 'is-valid': feedback.show && feedback.valid };
}

function fieldMessage(field) {
    return v$.value[field]?.$errors?.[0]?.$message || serverErrors[field]?.[0] || null;
}

function onFieldInput(field) {
    delete serverErrors[field];
    v$.value.$touch();
}

function resetForm() {
    form.language_id = null;
    form.code = '';
    form.name = '';
    form.is_active = true;
    settingsText.value = '';
    settingsError.value = false;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.language_id = record?.language_id ?? record?.language?.id ?? null;
    form.code = record?.code ?? '';
    form.name = record?.name ?? '';
    form.is_active = Boolean(record?.is_active ?? true);
    settingsText.value = record?.settings ? JSON.stringify(record.settings, null, 2) : '';
    settingsError.value = false;
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function openModal() {
    if (! modalElement.value) return;
    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function close() {
    closeModal();
    emit('close');
}

function parseSettings() {
    const trimmed = settingsText.value.trim();

    if (! trimmed) {
        settingsError.value = false;
        return null;
    }

    try {
        const parsed = JSON.parse(trimmed);
        settingsError.value = false;
        return parsed;
    } catch (error) {
        settingsError.value = true;
        return undefined;
    }
}

async function submit() {
    v$.value.$touch();
    const settings = parseSettings();

    if (v$.value.$invalid || settings === undefined) {
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    const payload = { ...form, settings };

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-locales/${props.record.id}`, payload);
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-locales', payload);
            showSuccess(extractApiMessage(response, t('toast.created')));
        }

        closeModal();
        emit('saved');
    } catch (error) {
        if (error.response?.status === 422) {
            applyApiErrors(serverErrors, error.response.data.errors ?? {});
            showWarning(t('toast.validation_error'));
        } else {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    } finally {
        submitting.value = false;
    }
}

watch(() => props.show, (show) => {
    if (show) {
        if (isEdit.value && props.record) {
            fillForm(props.record);
        } else {
            resetForm();
        }
        openModal();
    } else {
        closeModal();
    }
});

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', () => emit('close'));
    loadLanguages();
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
