<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ t('ai_plans.prices_title') }}
                            <span v-if="plan" class="text-muted fs-13">— {{ plan.name }}</span>
                        </h6>
                        <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>

                <div class="modal-body px-4 pb-2">
                    <div class="alert alert-info fs-13">{{ t('ai_plans.prices_intro') }}</div>

                    <div v-if="loading" class="text-center py-5">
                        <span class="spinner-border spinner-border-sm me-2"></span>{{ t('ai_plans.prices_loading') }}
                    </div>

                    <div v-else-if="!rows.length" class="text-center text-muted py-5">
                        {{ t('ai_plans.prices_empty') }}
                    </div>

                    <div v-else class="table-responsive">
                        <table class="table text-nowrap table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>{{ t('ai_plans.prices_country') }}</th>
                                    <th>{{ t('ai_plans.prices_currency') }}</th>
                                    <th>{{ t('ai_plans.prices_price') }}</th>
                                    <th>{{ t('ai_plans.prices_original_price') }}</th>
                                    <th></th>
                                    <th class="text-end"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.country_id">
                                    <td class="fw-semibold">{{ row.country_name }}</td>
                                    <td dir="ltr">{{ row.country_currency_code }}</td>
                                    <td style="min-width: 140px;">
                                        <input
                                            v-model="row.price"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="form-control form-control-sm"
                                            dir="ltr"
                                            :placeholder="String(plan?.price ?? 0)"
                                        >
                                    </td>
                                    <td style="min-width: 140px;">
                                        <input
                                            v-model="row.original_price"
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            class="form-control form-control-sm"
                                            dir="ltr"
                                        >
                                    </td>
                                    <td>
                                        <span v-if="row.is_country_specific" class="badge bg-success-transparent">
                                            {{ t('ai_plans.prices_custom_badge') }}
                                        </span>
                                        <span v-else class="badge bg-secondary-transparent">
                                            {{ t('ai_plans.prices_base_badge') }}
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <div class="btn-list justify-content-end">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary-light"
                                                :disabled="savingRow === row.country_id || row.price === '' || row.price === null"
                                                @click="saveRow(row)"
                                            >
                                                <span v-if="savingRow === row.country_id" class="spinner-border spinner-border-sm"></span>
                                                <span v-else>{{ t('ai_plans.prices_save_row') }}</span>
                                            </button>
                                            <button
                                                v-if="row.is_country_specific"
                                                type="button"
                                                class="btn btn-sm btn-danger-light btn-icon"
                                                :disabled="savingRow === row.country_id"
                                                @click="removeRow(row)"
                                            >
                                                <i class="ri-close-line"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" @click="close">{{ t('ai_plans.prices_close') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const props = defineProps({
    show: { type: Boolean, default: false },
    plan: { type: Object, default: null },
});

const emit = defineEmits(['close']);

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const modalElement = ref(null);
let modalInstance = null;

const loading = ref(false);
const savingRow = ref(null);
const rows = ref([]);

async function loadRows() {
    if (! props.plan) {
        rows.value = [];

        return;
    }

    loading.value = true;

    try {
        const { data } = await adminAxios.get(`/api/admin/v1/ai-plans/${props.plan.id}/prices`);
        const payload = data.data ?? {};

        rows.value = (payload.rows ?? []).map((row) => ({
            ...row,
            price: row.price ?? '',
            original_price: row.original_price ?? '',
        }));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        rows.value = [];
    } finally {
        loading.value = false;
    }
}

async function saveRow(row) {
    if (! props.plan) {
        return;
    }

    savingRow.value = row.country_id;

    try {
        const response = await adminAxios.post(`/api/admin/v1/ai-plans/${props.plan.id}/prices`, {
            country_id: row.country_id,
            price: row.price,
            original_price: row.original_price === '' ? null : row.original_price,
        });

        const saved = response.data.data ?? {};
        row.ai_plan_price_id = saved.ai_plan_price_id ?? row.ai_plan_price_id;
        row.is_country_specific = true;

        showSuccess(extractApiMessage(response, t('toast.success')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        savingRow.value = null;
    }
}

async function removeRow(row) {
    if (! props.plan || ! row.ai_plan_price_id) {
        return;
    }

    if (! window.confirm(t('ai_plans.prices_confirm_remove'))) {
        return;
    }

    savingRow.value = row.country_id;

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-plans/${props.plan.id}/prices/${row.ai_plan_price_id}`);

        row.ai_plan_price_id = null;
        row.is_country_specific = false;
        row.price = '';
        row.original_price = '';

        showSuccess(extractApiMessage(response, t('toast.deleted')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        savingRow.value = null;
    }
}

function openModal() {
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

watch(() => props.show, (show) => {
    if (show) {
        loadRows();
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
