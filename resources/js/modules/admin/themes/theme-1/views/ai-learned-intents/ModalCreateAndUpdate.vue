<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
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
                            <div class="col-12">
                                <label for="li-phrase" class="form-label">
                                    {{ t('ai_learned_intents.phrase') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="li-phrase"
                                    v-model="form.phrase"
                                    type="text"
                                    dir="auto"
                                    maxlength="120"
                                    class="form-control"
                                    :class="{ 'is-invalid': errors.phrase }"
                                    :disabled="isEdit"
                                    :placeholder="t('ai_learned_intents.phrase_placeholder')"
                                    @input="delete errors.phrase"
                                >
                                <div v-if="errors.phrase" class="invalid-feedback d-block">{{ errors.phrase }}</div>
                                <div v-else class="form-text">{{ t('ai_learned_intents.phrase_help') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="li-mode" class="form-label">{{ t('ai_learned_intents.match_mode') }}</label>
                                <select id="li-mode" v-model="form.match_mode" class="form-select" :disabled="isEdit">
                                    <option value="phrase">{{ t('ai_learned_intents.mode_phrase') }}</option>
                                    <option value="exact">{{ t('ai_learned_intents.mode_exact') }}</option>
                                </select>
                                <div class="form-text">{{ t(`ai_learned_intents.mode_help_${form.match_mode}`) }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="li-intent" class="form-label">
                                    {{ t('ai_learned_intents.intent') }} <span class="text-danger">*</span>
                                </label>
                                <select
                                    id="li-intent"
                                    v-model="form.intent"
                                    class="form-select"
                                    :class="{ 'is-invalid': errors.intent }"
                                    @change="delete errors.intent"
                                >
                                    <option v-for="intent in intents" :key="intent" :value="intent">{{ t(`ai_learned_intents.intents.${intent}`) }}</option>
                                </select>
                                <div v-if="errors.intent" class="invalid-feedback d-block">{{ errors.intent }}</div>
                            </div>

                            <div v-if="form.intent === 'file_output'" class="col-md-6">
                                <label for="li-format" class="form-label">{{ t('ai_learned_intents.file_format') }}</label>
                                <select id="li-format" v-model="form.file_format" class="form-select">
                                    <option :value="null">{{ t('ai_learned_intents.format_auto') }}</option>
                                    <option value="pdf">PDF</option>
                                    <option value="docx">Word (DOCX)</option>
                                    <option value="xlsx">Excel (XLSX)</option>
                                </select>
                            </div>

                            <div v-if="isEdit" class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_learned_intents.is_active') }}</label>
                                <div
                                    class="toggle toggle-success mb-0"
                                    :class="{ on: form.is_active }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_active = ! form.is_active"
                                    @keydown.enter.space.prevent="form.is_active = ! form.is_active"
                                >
                                    <span></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            <span v-if="submitting" class="spinner-border spinner-border-sm me-1"></span>
                            {{ t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
    intents: { type: Array, default: () => [] },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError, showWarning } = useToast();

const modalElement = ref(null);
const submitting = ref(false);
const errors = reactive({});
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');
const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_learned_intents.edit_title')} #${props.record?.id ?? ''}` : t('ai_learned_intents.create_title')
));

const form = reactive({
    phrase: '',
    match_mode: 'phrase',
    intent: 'image_generation',
    file_format: null,
    is_active: true,
});

function clearErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function resetForm() {
    form.phrase = '';
    form.match_mode = 'phrase';
    form.intent = props.intents[0] ?? 'image_generation';
    form.file_format = null;
    form.is_active = true;
    clearErrors();
}

function fillForm(record) {
    form.phrase = record?.phrase ?? '';
    form.match_mode = record?.match_mode ?? 'phrase';
    form.intent = record?.intent ?? 'image_generation';
    form.file_format = record?.file_format ?? null;
    form.is_active = Boolean(record?.is_active);
    clearErrors();
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

async function submit() {
    clearErrors();

    if (! isEdit.value && form.phrase.trim().length < 2) {
        errors.phrase = t('ai_learned_intents.phrase_too_short');
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;

    try {
        const fileFormat = form.intent === 'file_output' ? form.file_format : null;
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-learned-intents/${props.record.id}`, {
                intent: form.intent,
                file_format: fileFormat,
                is_active: form.is_active,
            });
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-learned-intents', {
                phrase: form.phrase,
                match_mode: form.match_mode,
                intent: form.intent,
                file_format: fileFormat,
            });
            showSuccess(extractApiMessage(response, t('toast.created')));
        }

        closeModal();
        emit('saved');
    } catch (error) {
        if (error.response?.status === 422) {
            const serverErrors = error.response.data.errors ?? {};
            Object.entries(serverErrors).forEach(([key, messages]) => {
                errors[key] = Array.isArray(messages) ? messages[0] : messages;
            });
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
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
