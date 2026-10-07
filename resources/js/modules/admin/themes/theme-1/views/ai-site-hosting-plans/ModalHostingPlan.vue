<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <form class="modal-content" @submit.prevent="submit">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">{{ type === 'edit' ? t('ai_site_hosting.edit') : t('ai_site_hosting.add') }}</h6>
                    <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                </div>

                <div class="modal-body px-4">
                    <div class="mb-3">
                        <label class="form-label" for="offer-name">{{ t('ai_site_offers.name') }}</label>
                        <input id="offer-name" v-model="form.name" type="text" class="form-control" :class="{ 'is-invalid': errors.name }" maxlength="150" required>
                        <div v-if="errors.name" class="invalid-feedback">{{ errors.name[0] }}</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="offer-code">{{ t('ai_site_offers.code') }}</label>
                        <input id="offer-code" v-model="form.code" type="text" class="form-control" :class="{ 'is-invalid': errors.code }" dir="ltr" maxlength="60" required>
                        <div v-if="errors.code" class="invalid-feedback">{{ errors.code[0] }}</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="offer-desc">{{ t('ai_site_offers.description') }}</label>
                        <textarea id="offer-desc" v-model="form.description" class="form-control" rows="3" maxlength="2000"></textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label" for="offer-period">{{ t('ai_site_hosting.period') }}</label>
                            <select id="offer-period" v-model="form.period" class="form-select" :class="{ 'is-invalid': errors.period }">
                                <option value="monthly">{{ t('ai_site_hosting.period_monthly') }}</option>
                                <option value="yearly">{{ t('ai_site_hosting.period_yearly') }}</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="offer-sort">{{ t('ai_site_offers.sort_order') }}</label>
                            <input id="offer-sort" v-model.number="form.sort_order" type="number" min="0" class="form-control">
                        </div>
                    </div>
                    <div class="form-check form-switch mt-3">
                        <input id="offer-active" v-model="form.is_active" type="checkbox" class="form-check-input">
                        <label class="form-check-label" for="offer-active">{{ t('ai_site_offers.is_active') }}</label>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" @click="close">{{ t('ai_site_offers.cancel') }}</button>
                    <button type="submit" class="btn btn-primary" :disabled="saving">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('ai_site_offers.save') }}
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const props = defineProps({
    show: { type: Boolean, default: false },
    type: { type: String, default: 'create' },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const modalElement = ref(null);
let modalInstance = null;
const saving = ref(false);
const errors = ref({});

const form = reactive({ name: '', code: '', description: '', period: 'monthly', sort_order: 0, is_active: true });

function fill() {
    errors.value = {};
    form.name = props.record?.name ?? '';
    form.code = props.record?.code ?? '';
    form.description = props.record?.description ?? '';
    form.period = props.record?.period ?? 'monthly';
    form.sort_order = props.record?.sort_order ?? 0;
    form.is_active = props.record?.is_active ?? true;
}

async function submit() {
    saving.value = true;
    errors.value = {};

    try {
        const payload = { ...form, sort_order: form.sort_order || 0 };
        const response = props.type === 'edit'
            ? await adminAxios.put(`/api/admin/v1/ai-site-hosting-plans/${props.record.id}`, payload)
            : await adminAxios.post('/api/admin/v1/ai-site-hosting-plans', payload);

        showSuccess(extractApiMessage(response, t('toast.success')));
        emit('saved');
    } catch (error) {
        errors.value = error.response?.data?.errors ?? {};
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

function close() {
    modalInstance?.hide();
    emit('close');
}

watch(() => props.show, (show) => {
    if (show) {
        fill();
        modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
        modalInstance.show();
    } else {
        modalInstance?.hide();
    }
});

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', () => emit('close'));
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
