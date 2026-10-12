<template>
    <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title mb-0">{{ t('ai_site_requests.manage_statuses') }}</h6>
                    <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                </div>
                <div class="modal-body px-4">
                    <div class="alert alert-info fs-13">{{ t('ai_site_requests.statuses_hint') }}</div>
                    <div class="table-responsive">
                        <table class="table align-middle mb-0">
                            <thead>
                                <tr>
                                    <th>{{ t('ai_site_requests.code') }}</th>
                                    <th>{{ t('ai_site_requests.name_ar') }}</th>
                                    <th>{{ t('ai_site_requests.name_en') }}</th>
                                    <th>{{ t('ai_site_requests.color') }}</th>
                                    <th style="width: 90px;">{{ t('ai_site_offers.sort_order') }}</th>
                                    <th>{{ t('ai_site_offers.is_active') }}</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="row in rows" :key="row.id ?? row.code">
                                    <td>
                                        <input v-if="! row.id" v-model="row.code" class="form-control form-control-sm" dir="ltr" placeholder="design_review">
                                        <span v-else class="badge bg-light text-default" dir="ltr">{{ row.code }}</span>
                                        <span v-if="row.is_system" class="badge bg-primary-transparent ms-1">{{ t('ai_site_requests.system') }}</span>
                                    </td>
                                    <td><input v-model="row.name_ar" class="form-control form-control-sm" dir="rtl"></td>
                                    <td><input v-model="row.name_en" class="form-control form-control-sm" dir="ltr"></td>
                                    <td>
                                        <select v-model="row.color" class="form-select form-select-sm">
                                            <option v-for="c in colors" :key="c" :value="c">{{ c }}</option>
                                        </select>
                                        <span class="badge mt-1" :class="`bg-${row.color}-transparent`">{{ row.name_en || row.code }}</span>
                                    </td>
                                    <td><input v-model.number="row.sort_order" type="number" min="0" class="form-control form-control-sm"></td>
                                    <td><input v-model="row.is_active" type="checkbox" class="form-check-input" :disabled="row.is_system"></td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-primary" :disabled="busy === (row.id ?? row.code)" @click="save(row)">{{ t('ai_site_offers.save') }}</button>
                                        <button v-if="! row.is_system" type="button" class="btn btn-sm btn-danger-light btn-icon ms-1" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <button type="button" class="btn btn-light btn-sm mt-3" @click="addRow"><i class="ri-add-line me-1"></i>{{ t('ai_site_requests.add_status') }}</button>
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

const props = defineProps({ show: { type: Boolean, default: false } });
const emit = defineEmits(['close', 'saved']);

const { t } = useI18n();
const { showSuccess, showError } = useToast();
const colors = ['primary', 'secondary', 'success', 'info', 'warning', 'danger'];
const modalElement = ref(null);
let modalInstance = null;
const rows = ref([]);
const busy = ref(null);

async function load() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-site-requests/statuses');
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function addRow() {
    rows.value.push({ id: null, code: '', name_ar: '', name_en: '', color: 'secondary', sort_order: 100, is_active: true, is_system: false });
}

async function save(row) {
    busy.value = row.id ?? row.code;

    try {
        const payload = { code: row.code, name_ar: row.name_ar, name_en: row.name_en, color: row.color, sort_order: row.sort_order, is_active: row.is_active };
        const response = row.id
            ? await adminAxios.put(`/api/admin/v1/ai-site-requests/statuses/${row.id}`, payload)
            : await adminAxios.post('/api/admin/v1/ai-site-requests/statuses', payload);
        showSuccess(extractApiMessage(response, t('toast.success')));
        await load();
        emit('saved');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busy.value = null;
    }
}

async function remove(row) {
    if (! row.id) {
        rows.value = rows.value.filter((r) => r !== row);

        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/ai-site-requests/statuses/${row.id}`);
        await load();
        emit('saved');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function close() {
    modalInstance?.hide();
    emit('close');
}

watch(() => props.show, (show) => {
    if (show) {
        load();
        modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
        modalInstance.show();
    } else {
        modalInstance?.hide();
    }
});

onMounted(() => modalElement.value?.addEventListener('hidden.bs.modal', () => emit('close')));
onUnmounted(() => modalInstance?.dispose());
</script>
