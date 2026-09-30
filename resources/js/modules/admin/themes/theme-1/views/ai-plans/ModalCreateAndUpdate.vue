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
                        <div class="mb-3">
                            <label for="plan-name" class="form-label">
                                {{ t('ai_plans.name') }} <span class="text-danger">*</span>
                            </label>
                            <input
                                id="plan-name"
                                v-model="form.name"
                                type="text"
                                class="form-control"
                                :class="fieldClass('name')"
                                :placeholder="t('ai_plans.name_placeholder')"
                                @input="onFieldInput('name')"
                            >
                            <div v-if="fieldMessage('name')" class="invalid-feedback d-block">{{ fieldMessage('name') }}</div>
                        </div>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="plan-code" class="form-label">
                                    {{ t('ai_plans.code') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="plan-code"
                                    v-model="form.code"
                                    type="text"
                                    class="form-control"
                                    :class="fieldClass('code')"
                                    :placeholder="t('ai_plans.code_placeholder')"
                                    @input="onFieldInput('code')"
                                >
                                <div v-if="fieldMessage('code')" class="invalid-feedback d-block">{{ fieldMessage('code') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="plan-usage-minutes" class="form-label">
                                    {{ t('ai_plans.usage_minutes') }} <span class="text-danger">*</span>
                                </label>
                                <input
                                    id="plan-usage-minutes"
                                    v-model.number="form.usage_minutes"
                                    type="number"
                                    min="0"
                                    class="form-control"
                                    :class="fieldClass('usage_minutes')"
                                    @input="onFieldInput('usage_minutes')"
                                >
                                <div v-if="fieldMessage('usage_minutes')" class="invalid-feedback d-block">{{ fieldMessage('usage_minutes') }}</div>
                            </div>

                            <div class="col-md-6">
                                <label for="plan-cooldown" class="form-label">{{ t('ai_plans.cooldown_minutes') }}</label>
                                <input id="plan-cooldown" v-model.number="form.cooldown_minutes" type="number" min="0" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="plan-duration-days" class="form-label">{{ t('ai_plans.duration_days') }}</label>
                                <input id="plan-duration-days" v-model.number="form.duration_days" type="number" min="1" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="plan-sort-order" class="form-label">{{ t('ai_plans.sort_order') }}</label>
                                <input id="plan-sort-order" v-model.number="form.sort_order" type="number" min="0" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="plan-price" class="form-label">{{ t('ai_plans.price') }}</label>
                                <input id="plan-price" v-model.number="form.price" type="number" min="0" step="0.01" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="plan-currency" class="form-label">{{ t('ai_plans.currency') }}</label>
                                <input id="plan-currency" v-model="form.currency" type="text" maxlength="3" class="form-control text-uppercase">
                            </div>

                            <div class="col-md-6">
                                <label for="plan-original-price" class="form-label">{{ t('ai_plans.original_price') }}</label>
                                <input id="plan-original-price" v-model.number="form.original_price" type="number" min="0" step="0.01" class="form-control">
                            </div>

                            <div class="col-md-6">
                                <label for="plan-badge" class="form-label">{{ t('ai_plans.badge') }}</label>
                                <input id="plan-badge" v-model="form.badge" type="text" maxlength="60" class="form-control" :placeholder="t('ai_plans.badge_placeholder')">
                            </div>

                            <div class="col-12">
                                <label for="plan-description" class="form-label">{{ t('ai_plans.description') }}</label>
                                <textarea
                                    id="plan-description"
                                    v-model="form.description"
                                    class="form-control"
                                    rows="2"
                                    :placeholder="t('ai_plans.description_placeholder')"
                                ></textarea>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_plans.is_trial') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.is_trial }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_trial = !form.is_trial"
                                    @keydown.enter.space.prevent="form.is_trial = !form.is_trial"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_plans.is_active') }}</label>
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

                            <div class="col-md-6">
                                <label class="form-label d-block mb-2">{{ t('ai_plans.is_featured') }}</label>
                                <div
                                    class="toggle toggle-primary mb-0"
                                    :class="{ on: form.is_featured }"
                                    role="button"
                                    tabindex="0"
                                    @click="form.is_featured = !form.is_featured"
                                    @keydown.enter.space.prevent="form.is_featured = !form.is_featured"
                                >
                                    <span></span>
                                </div>
                            </div>

                            <div class="col-12">
                                <label class="form-label">{{ t('ai_plans.features') }}</label>
                                <div class="d-flex gap-2 mb-2">
                                    <input
                                        v-model="newFeature"
                                        type="text"
                                        class="form-control"
                                        :placeholder="t('ai_plans.features_placeholder')"
                                        @keydown.enter.prevent="addFeature"
                                    >
                                    <button type="button" class="btn btn-light" @click="addFeature">{{ t('ai_plans.add_feature') }}</button>
                                </div>
                                <div class="d-flex flex-wrap gap-1">
                                    <span v-for="(feature, index) in form.features" :key="index" class="badge bg-light text-default border d-flex align-items-center gap-1">
                                        {{ feature }}
                                        <i class="ri-close-line" role="button" @click="form.features.splice(index, 1)"></i>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                        <button type="submit" class="btn btn-primary btn-wave" :disabled="submitting">
                            {{ submitting ? t('ai_plans.saving') : t('save_changes') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import useVuelidate from '@vuelidate/core';
import { integer, minValue } from '@vuelidate/validators';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { helpers } from '@vuelidate/validators';
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
const { requiredField, stringFieldRules, applyApiErrors, fieldFeedback } = useValidation();

const modalElement = ref(null);
const submitting = ref(false);
const serverErrors = reactive({});
let modalInstance = null;

const isEdit = computed(() => props.type === 'edit');

const form = reactive({
    name: '',
    code: '',
    description: '',
    usage_minutes: 0,
    cooldown_minutes: 0,
    duration_days: 30,
    price: 0,
    original_price: null,
    currency: 'EGP',
    badge: '',
    is_featured: false,
    features: [],
    is_trial: false,
    is_active: true,
    sort_order: 0,
});

const newFeature = ref('');

function addFeature() {
    const value = newFeature.value.trim();
    if (! value) return;
    form.features.push(value);
    newFeature.value = '';
}

const rules = computed(() => ({
    name: stringFieldRules('ai_plans.name', 150),
    code: stringFieldRules('ai_plans.code', 100),
    usage_minutes: {
        required: requiredField('ai_plans.usage_minutes'),
        integer: helpers.withMessage(() => t('validation.integer', { field: t('ai_plans.usage_minutes') }), integer),
        minValue: helpers.withMessage(() => t('validation.min.numeric', { field: t('ai_plans.usage_minutes'), min: 0 }), minValue(0)),
    },
}));

const v$ = useVuelidate(rules, form, { $autoDirty: true });

const modalTitle = computed(() => (
    isEdit.value ? `${t('ai_plans.edit_title')}${props.record?.id ? ` #${props.record.id}` : ''}` : t('ai_plans.create_title')
));

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
    form.name = '';
    form.code = '';
    form.description = '';
    form.usage_minutes = 0;
    form.cooldown_minutes = 0;
    form.duration_days = 30;
    form.price = 0;
    form.original_price = null;
    form.currency = 'EGP';
    form.badge = '';
    form.is_featured = false;
    form.features = [];
    form.is_trial = false;
    form.is_active = true;
    form.sort_order = 0;
    newFeature.value = '';
    v$.value.$reset();
    applyApiErrors(serverErrors, {});
}

function fillForm(record) {
    form.name = record?.name ?? '';
    form.code = record?.code ?? '';
    form.description = record?.description ?? '';
    form.usage_minutes = record?.usage_minutes ?? 0;
    form.cooldown_minutes = record?.cooldown_minutes ?? 0;
    form.duration_days = record?.duration_days ?? 30;
    form.price = record?.price ?? 0;
    form.original_price = record?.original_price ?? null;
    form.currency = record?.currency ?? 'EGP';
    form.badge = record?.badge ?? '';
    form.is_featured = Boolean(record?.is_featured);
    form.features = Array.isArray(record?.features) ? [...record.features] : [];
    form.is_trial = Boolean(record?.is_trial);
    form.is_active = Boolean(record?.is_active ?? true);
    form.sort_order = record?.sort_order ?? 0;
    newFeature.value = '';
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

async function submit() {
    v$.value.$touch();

    if (v$.value.$invalid) {
        showWarning(t('toast.validation_error'));
        return;
    }

    submitting.value = true;
    applyApiErrors(serverErrors, {});

    try {
        let response;

        if (isEdit.value && props.record?.id) {
            response = await adminAxios.put(`/api/admin/v1/ai-plans/${props.record.id}`, { ...form });
            showSuccess(extractApiMessage(response, t('toast.updated')));
        } else {
            response = await adminAxios.post('/api/admin/v1/ai-plans', { ...form });
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
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
