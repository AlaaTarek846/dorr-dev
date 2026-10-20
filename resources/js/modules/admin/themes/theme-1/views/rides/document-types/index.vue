<template>
    <div>
        <WalletPageHeader :title="t('rides.document_types.title')" :section="t('sidebar.rides')" :total="rows.length || null" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('rides.document_types.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center py-3">
                <button v-if="can('driver-document-types.create')" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openForm(null)">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('rides.document_types.add') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('rides.document_types.name') }}</th>
                                <th>{{ t('rides.document_types.applies_to') }}</th>
                                <th>{{ t('rides.document_types.fields') }}</th>
                                <th>{{ t('rides.document_types.required_from') }}</th>
                                <th>{{ t('rides.document_types.active') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.id">
                                <td class="ps-4">
                                    <div class="fw-semibold">{{ row.name }}</div>
                                    <code class="fs-11 text-muted">{{ row.code }}</code>
                                </td>
                                <td><span class="badge bg-light text-dark"><i :class="row.applies_to === 'vehicle' ? 'ri-car-line' : 'ri-user-line'" class="me-1"></i>{{ t(`rides.document_types.subject.${row.applies_to}`) }}</span></td>
                                <td class="fs-12">
                                    <span v-if="row.has_number" class="badge bg-primary-transparent me-1">{{ t('rides.document_types.has_number') }}</span>
                                    <span v-if="row.has_expiry" class="badge bg-primary-transparent me-1">{{ t('rides.document_types.has_expiry') }}</span>
                                    <span v-if="row.needs_back_side" class="badge bg-primary-transparent">{{ t('rides.document_types.needs_back_side') }}</span>
                                </td>
                                <td class="text-wrap" style="min-width: 220px">
                                    <span v-if="!row.requirements.length" class="text-muted fs-12">{{ t('rides.document_types.optional_everywhere') }}</span>
                                    <span v-for="r in row.requirements" :key="r.id" class="badge bg-info-transparent me-1 mb-1">
                                        {{ countryName(r.country_id) }}
                                        · {{ r.residency ? t(`rides.residency.${r.residency}`) : t('rides.document_types.everyone') }}
                                        <template v-if="r.service_category_id"> · {{ serviceName(r.service_category_id) }}</template>
                                    </span>
                                </td>
                                <td>
                                    <div v-if="can('driver-document-types.update')" class="toggle toggle-success mb-0" :class="{ on: row.status }" role="button" @click="toggle(row)"><span></span></div>
                                    <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <button v-if="can('driver-document-types.update')" type="button" class="btn btn-sm btn-light btn-icon me-1" @click="openForm(row)"><i class="ri-edit-line"></i></button>
                                    <button v-if="can('driver-document-types.delete') && !row.documents_count" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showForm" :title="form.id ? t('rides.document_types.edit') : t('rides.document_types.add')" @close="showForm = false">
            <form id="doc-type-form" @submit.prevent="save">
                <div class="row g-3">
                    <div class="col-12">
                        <RideLangFields v-slot="{ locale, dir }" :required="[form.name]">
                            <label class="form-label">{{ t('rides.lang.name') }} *</label>
                            <input v-model="form.name[locale]" type="text" class="form-control mb-3" :dir="dir">
                            <label class="form-label">{{ t('rides.lang.instructions') }}</label>
                            <textarea v-model="form.instructions[locale]" rows="2" class="form-control" :dir="dir"></textarea>
                        </RideLangFields>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.document_types.code') }} *</label>
                        <input v-model="form.code" type="text" maxlength="48" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.code }" placeholder="driving_license">
                        <div class="invalid-feedback">{{ errors.code }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.document_types.applies_to') }} *</label>
                        <select v-model="form.applies_to" class="form-select">
                            <option v-for="a in options.applies_to" :key="a" :value="a">{{ t(`rides.document_types.subject.${a}`) }}</option>
                        </select>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-3">
                        <div v-for="flag in ['has_number', 'has_expiry', 'needs_back_side', 'status']" :key="flag" class="form-check form-switch">
                            <input :id="`f-${flag}`" v-model="form[flag]" class="form-check-input" type="checkbox">
                            <label class="form-check-label" :for="`f-${flag}`">{{ t(`rides.document_types.${flag === 'status' ? 'active' : flag}`) }}</label>
                        </div>
                    </div>
                </div>

                <hr>
                <div class="d-flex align-items-center mb-2">
                    <h6 class="mb-0">{{ t('rides.document_types.required_from') }}</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" @click="addRequirement"><i class="ri-add-line me-1"></i>{{ t('rides.document_types.add_requirement') }}</button>
                </div>
                <div v-if="!form.requirements.length" class="text-muted fs-12 mb-2">{{ t('rides.document_types.optional_everywhere') }}</div>
                <div v-for="(r, i) in form.requirements" :key="i" class="row g-2 align-items-center mb-2">
                    <div class="col-md-4">
                        <select v-model="r.country_id" class="form-select form-select-sm">
                            <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select v-model="r.residency" class="form-select form-select-sm">
                            <option :value="null">{{ t('rides.document_types.everyone') }}</option>
                            <option v-for="res in options.residencies" :key="res" :value="res">{{ t(`rides.residency.${res}`) }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <select v-model="r.service_category_id" class="form-select form-select-sm">
                            <option :value="null">{{ t('rides.document_types.any_service') }}</option>
                            <option v-for="s in options.services" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-1 text-end">
                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="form.requirements.splice(i, 1)"><i class="ri-close-line"></i></button>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger fs-13 mt-2">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showForm = false">{{ t('cancel') }}</button>
                <button type="submit" form="doc-type-form" class="btn btn-primary" :disabled="saving">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import RideLangFields from '../RideLangFields.vue';
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The documents drivers upload (Jawad's documents screen), and where each is required: the country,
// the service, and from whom — citizens, residents or both.
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const rows = ref([]);
const loading = ref(false);
const options = reactive({ countries: [], services: [], residencies: [], applies_to: [] });
const showForm = ref(false);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ id: null, code: '', applies_to: 'driver', has_number: false, has_expiry: true, needs_back_side: false, status: true, name: { ar: '', en: '' }, instructions: { ar: '', en: '' }, requirements: [] });
const form = reactive(blank());

const countryName = (id) => options.countries.find((c) => c.id === id)?.name ?? `#${id}`;
const serviceName = (id) => options.services.find((s) => s.id === id)?.name ?? `#${id}`;

function openForm(row) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.assign(form, blank(), row ? {
        id: row.id, code: row.code, applies_to: row.applies_to, has_number: row.has_number, has_expiry: row.has_expiry, needs_back_side: row.needs_back_side, status: row.status,
        name: { ar: row.names?.ar ?? '', en: row.names?.en ?? '' },
        instructions: { ar: row.instructions?.ar ?? '', en: row.instructions?.en ?? '' },
        requirements: row.requirements.map((r) => ({ country_id: r.country_id, residency: r.residency, service_category_id: r.service_category_id })),
    } : {});
    showForm.value = true;
}

function addRequirement() {
    form.requirements.push({ country_id: options.countries[0]?.id ?? null, residency: null, service_category_id: null });
}

async function save() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    saving.value = true;
    try {
        if (form.id) {
            await adminAxios.put(`/api/admin/v1/driver-document-types/${form.id}`, form);
        } else {
            await adminAxios.post('/api/admin/v1/driver-document-types', form);
        }
        showForm.value = false;
        showSuccess(t('rides.drivers.saved'));
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key] ??= messages[0]; });
        errors.general = Object.keys(bag).length ? Object.values(bag)[0][0] : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

async function toggle(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/driver-document-types/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (!window.confirm(t('rides.document_types.confirm_delete'))) {
        return;
    }
    try {
        await adminAxios.delete(`/api/admin/v1/driver-document-types/${row.id}`);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/driver-document-types');
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load();
    try {
        const { data } = await adminAxios.get('/api/admin/v1/driver-document-types/options');
        Object.assign(options, data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>
