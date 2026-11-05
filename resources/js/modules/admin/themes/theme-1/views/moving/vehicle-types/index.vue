<template>
    <div>
        <WalletPageHeader :title="t('moving.vehicle_types.title')" :section="t('sidebar.moving')" :total="rows.length || null" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('moving.vehicle_types.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center py-3">
                <button v-if="can('provider-vehicle-types.create')" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openForm(null)">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('moving.vehicle_types.add') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('moving.vehicle_types.name') }}</th>
                                <th>{{ t('moving.vehicle_types.countries') }}</th>
                                <th>{{ t('moving.vehicle_types.services') }}</th>
                                <th>{{ t('moving.common.sort') }}</th>
                                <th>{{ t('moving.common.active') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.id">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-md bg-light rounded-3 overflow-hidden">
                                            <img v-if="row.image" :src="row.image" alt="" class="w-100 h-100 object-fit-contain">
                                            <i v-else class="ri-truck-line fs-18 text-muted"></i>
                                        </span>
                                        <div>
                                            <div class="fw-semibold">{{ row.name }}</div>
                                            <code class="fs-11 text-muted">{{ row.code }}</code>
                                        </div>
                                    </div>
                                </td>
                                <td class="text-wrap fs-12" style="min-width: 160px">
                                    <span v-for="id in row.country_ids" :key="id" class="badge bg-info-transparent me-1 mb-1">{{ nameOf(options.countries, id) }}</span>
                                </td>
                                <td class="text-wrap fs-12" style="min-width: 160px">
                                    <span v-for="id in row.service_ids" :key="id" class="badge bg-primary-transparent me-1 mb-1">{{ nameOf(options.services, id) }}</span>
                                </td>
                                <td>{{ row.sort_order }}</td>
                                <td><span class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span></td>
                                <td class="text-end pe-4">
                                    <button v-if="can('provider-vehicle-types.update')" type="button" class="btn btn-sm btn-light btn-icon me-1" @click="openForm(row)"><i class="ri-edit-line"></i></button>
                                    <button v-if="can('provider-vehicle-types.delete')" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showForm" :title="form.id ? t('moving.vehicle_types.edit') : t('moving.vehicle_types.add')" size="lg" @close="showForm = false">
            <form id="provider-vehicle-type-form" @submit.prevent="save">
                <div class="row g-3">
                    <div class="col-12">
                        <RideLangFields v-slot="{ locale, dir }" :required="[form.name]">
                            <label class="form-label">{{ t('rides.lang.name') }} *</label>
                            <input v-model="form.name[locale]" type="text" class="form-control mb-3" :dir="dir">
                            <label class="form-label">{{ t('moving.vehicle_types.description') }}</label>
                            <textarea v-model="form.description[locale]" rows="2" class="form-control" :dir="dir"></textarea>
                        </RideLangFields>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('moving.document_types.code') }} *</label>
                        <input v-model="form.code" type="text" maxlength="48" dir="ltr" class="form-control" placeholder="half_truck">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ t('moving.common.sort') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('moving.vehicle_types.image') }}</label>
                        <input type="file" accept="image/*" class="form-control" @change="form.image = $event.target.files[0] ?? null">
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input id="pvt-status" v-model="form.status" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="pvt-status">{{ t('moving.common.active') }}</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('moving.vehicle_types.countries') }}</label>
                        <div class="border rounded p-2" style="max-height: 180px; overflow-y: auto">
                            <div v-for="c in options.countries" :key="c.id" class="form-check">
                                <input :id="`pvt-c-${c.id}`" v-model="form.country_ids" class="form-check-input" type="checkbox" :value="c.id">
                                <label class="form-check-label" :for="`pvt-c-${c.id}`">{{ c.name }}</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('moving.vehicle_types.services') }}</label>
                        <div class="border rounded p-2" style="max-height: 180px; overflow-y: auto">
                            <div v-for="s in options.services" :key="s.id" class="form-check">
                                <input :id="`pvt-s-${s.id}`" v-model="form.service_ids" class="form-check-input" type="checkbox" :value="s.id">
                                <label class="form-check-label" :for="`pvt-s-${s.id}`">{{ s.name }}</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="error" class="text-danger fs-13 mt-2">{{ error }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showForm = false">{{ t('cancel') }}</button>
                <button type="submit" form="provider-vehicle-type-form" class="btn btn-primary" :disabled="saving">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import RideLangFields from '../../rides/RideLangFields.vue';
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Provider vehicle types (docs/moving_tasks.md A-11): name, image, order, where each exists and which services it fits.
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const rows = ref([]);
const loading = ref(false);
const options = reactive({ countries: [], services: [] });
const showForm = ref(false);
const saving = ref(false);
const error = ref('');
const blank = () => ({ id: null, code: '', status: true, sort_order: 0, name: { ar: '', en: '' }, description: { ar: '', en: '' }, country_ids: [], service_ids: [], image: null });
const form = reactive(blank());

const nameOf = (list, id) => list.find((x) => x.id === id)?.name ?? `#${id}`;

function openForm(row) {
    error.value = '';
    Object.assign(form, blank(), row ? {
        id: row.id, code: row.code, status: row.status, sort_order: row.sort_order,
        name: { ar: row.names?.ar ?? '', en: row.names?.en ?? '' },
        description: { ar: row.descriptions?.ar ?? '', en: row.descriptions?.en ?? '' },
        country_ids: [...row.country_ids], service_ids: [...row.service_ids],
    } : { sort_order: rows.value.length + 1, country_ids: options.countries.map((c) => c.id) });
    showForm.value = true;
}

function body() {
    const data = new FormData();
    data.append('code', form.code);
    data.append('status', form.status ? '1' : '0');
    data.append('sort_order', String(form.sort_order ?? 0));
    ['ar', 'en'].forEach((l) => {
        data.append(`name[${l}]`, form.name[l] ?? '');
        data.append(`description[${l}]`, form.description[l] ?? '');
    });
    form.country_ids.forEach((id) => data.append('country_ids[]', id));
    form.service_ids.forEach((id) => data.append('service_ids[]', id));
    if (form.image) {
        data.append('image', form.image);
    }

    return data;
}

async function save() {
    error.value = '';
    saving.value = true;
    try {
        const url = form.id ? `/api/admin/v1/provider-vehicle-types/${form.id}` : '/api/admin/v1/provider-vehicle-types';
        await adminAxios.post(url, body());
        showForm.value = false;
        showSuccess(t('moving.common.saved'));
        load();
    } catch (e) {
        const bag = e?.response?.data?.errors ?? {};
        error.value = Object.keys(bag).length ? Object.values(bag)[0][0] : extractApiErrorMessage(e);
    } finally {
        saving.value = false;
    }
}

async function remove(row) {
    if (!window.confirm(t('moving.common.confirm_delete'))) {
        return;
    }
    try {
        await adminAxios.delete(`/api/admin/v1/provider-vehicle-types/${row.id}`);
        load();
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/provider-vehicle-types');
        rows.value = data.data ?? [];
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load();
    try {
        const { data } = await adminAxios.get('/api/admin/v1/provider-vehicle-types/options');
        Object.assign(options, data.data ?? {});
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
});
</script>
