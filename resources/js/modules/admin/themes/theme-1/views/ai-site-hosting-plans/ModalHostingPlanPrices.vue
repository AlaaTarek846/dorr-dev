<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">
                        {{ t('ai_site_hosting.prices_title') }}
                        <span v-if="offer" class="text-muted fs-13">— {{ offer.name }}</span>
                    </h6>
                    <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                </div>

                <div class="modal-body px-4 pb-2">
                    <div class="alert alert-info fs-13">{{ t('ai_site_hosting.prices_intro') }}</div>

                    <div v-if="loading" class="text-center py-5">
                        <span class="spinner-border spinner-border-sm me-2"></span>{{ t('ai_site_offers.loading') }}
                    </div>

                    <div v-else-if="!rows.length" class="text-center text-muted py-5">{{ t('ai_site_offers.prices_empty') }}</div>

                    <div v-else class="table-responsive">
                        <table class="table text-nowrap table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>{{ t('ai_site_offers.country') }}</th>
                                    <th>{{ t('ai_site_offers.currency') }}</th>
                                    <th>{{ t('ai_site_offers.price') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.country_id">
                                    <td class="fw-semibold">{{ row.country_name }}</td>
                                    <td dir="ltr">{{ row.currency_code }}</td>
                                    <td style="min-width: 160px;">
                                        <input v-model="row.price" type="number" step="0.01" min="0" class="form-control form-control-sm" dir="ltr">
                                    </td>
                                    <td>
                                        <span v-if="row.price !== '' && row.price !== null" class="badge bg-success-transparent">{{ t('ai_site_offers.on_sale') }}</span>
                                        <span v-else class="badge bg-secondary-transparent">{{ t('ai_site_offers.not_sold') }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" @click="close">{{ t('ai_site_offers.cancel') }}</button>
                    <button type="button" class="btn btn-primary" :disabled="saving || loading" @click="save">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('ai_site_offers.save') }}
                    </button>
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
    offer: { type: Object, default: null },
});

const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const modalElement = ref(null);
let modalInstance = null;
const loading = ref(false);
const saving = ref(false);
const rows = ref([]);

async function loadRows() {
    if (! props.offer) {
        rows.value = [];

        return;
    }

    loading.value = true;

    try {
        const { data } = await adminAxios.get(`/api/admin/v1/ai-site-hosting-plans/${props.offer.id}/prices`);
        rows.value = (data.data?.rows ?? []).map((row) => ({ ...row, price: row.price ?? '' }));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        rows.value = [];
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;

    try {
        const prices = rows.value
            .filter((row) => row.price !== '' && row.price !== null && Number(row.price) >= 0)
            .map((row) => ({ country_id: row.country_id, price: Number(row.price) }));

        const response = await adminAxios.put(`/api/admin/v1/ai-site-hosting-plans/${props.offer.id}/prices`, { prices });
        showSuccess(extractApiMessage(response, t('toast.success')));
        emit('saved');
        close();
    } catch (error) {
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
        loadRows();
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
