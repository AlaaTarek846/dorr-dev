<template>
    <div>
        <WalletPageHeader :title="t('moving.document_types.title')" :section="t('sidebar.moving')" :total="rows.length || null" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('moving.document_types.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center py-3">
                <button v-if="can('provider-document-types.create')" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openForm(null)">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('moving.document_types.add') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('moving.document_types.name') }}</th>
                                <th>{{ t('moving.document_types.subject_label') }}</th>
                                <th>{{ t('moving.document_types.fields') }}</th>
                                <th>{{ t('moving.document_types.required_from') }}</th>
                                <th>{{ t('moving.common.active') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :layout="['two-lines', 'badge', 'badge', 'badge', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="6" class="text-center text-muted py-5"><i class="ri-file-list-3-line fs-24 d-block mb-1"></i>{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <tr v-for="row in rows" v-else :key="row.id">
                                <td class="ps-4">
                                    <div class="fw-semibold">
                                        {{ row.name }}
                                        <span v-if="row.locked" class="badge bg-warning-transparent ms-1"><i class="ri-lock-line me-1"></i>{{ t('moving.document_types.always') }}</span>
                                    </div>
                                    <code class="fs-11 text-muted">{{ row.code }}</code>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark me-1"><i :class="subjectIcon(row.subject)" class="me-1"></i>{{ t(`moving.document_types.subject.${row.subject}`) }}</span>
                                    <span class="badge" :class="row.kind === 'photo' ? 'bg-info-transparent' : 'bg-secondary-transparent'">{{ t(`moving.document_types.kind.${row.kind}`) }}</span>
                                </td>
                                <td class="fs-12">
                                    <span v-if="row.has_number" class="badge bg-primary-transparent me-1">{{ t('moving.document_types.has_number') }}</span>
                                    <span v-if="row.has_expiry" class="badge bg-primary-transparent me-1">{{ t('moving.document_types.has_expiry') }}</span>
                                    <span v-if="row.needs_back_side" class="badge bg-primary-transparent">{{ t('moving.document_types.needs_back_side') }}</span>
                                </td>
                                <td class="text-wrap" style="min-width: 240px">
                                    <span v-if="!row.requirements.length" class="text-muted fs-12">{{ t('moving.document_types.optional_everywhere') }}</span>
                                    <span v-for="r in row.requirements" :key="r.id" class="badge bg-info-transparent me-1 mb-1">
                                        {{ nameOf(options.countries, r.country_id) }}
                                        <template v-if="r.service_category_id"> · {{ nameOf(options.services, r.service_category_id) }}</template>
                                        <template v-if="r.vehicle_type_id"> · {{ nameOf(options.vehicle_types, r.vehicle_type_id) }}</template>
                                        <template v-if="r.establishment_type"> · {{ t(`moving.establishment_type.${r.establishment_type}`) }}</template>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <button v-if="can('provider-document-types.update')" type="button" class="btn btn-sm btn-light btn-icon me-1" @click="openForm(row)"><i class="ri-edit-line"></i></button>
                                    <button v-if="can('provider-document-types.delete') && !row.locked" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showForm" :title="form.id ? t('moving.document_types.edit') : t('moving.document_types.add')" size="lg" @close="showForm = false">
            <form id="provider-doc-type-form" @submit.prevent="save">
                <div class="row g-3">
                    <div class="col-12">
                        <RideLangFields v-slot="{ locale, dir }" :required="[form.name]">
                            <label class="form-label">{{ t('rides.lang.name') }} <span class="text-danger">*</span></label>
                            <input v-model="form.name[locale]" type="text" maxlength="191" class="form-control" :class="{ 'is-invalid': errorOf(`name.${locale}`) }" :dir="dir">
                            <div class="invalid-feedback d-block mb-3">{{ errorOf(`name.${locale}`) }}</div>
                            <label class="form-label">{{ t('rides.lang.instructions') }}</label>
                            <textarea v-model="form.instructions[locale]" rows="2" class="form-control" :dir="dir"></textarea>
                        </RideLangFields>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('moving.document_types.code') }} <span class="text-danger">*</span></label>
                        <input v-model="form.code" type="text" maxlength="48" dir="ltr" class="form-control" :class="{ 'is-invalid': errorOf('code') }" :disabled="form.locked" placeholder="vehicle_side_photo">
                        <div v-if="errorOf('code')" class="invalid-feedback d-block">{{ errorOf('code') }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('moving.document_types.subject_label') }} <span class="text-danger">*</span></label>
                        <select v-model="form.subject" class="form-select" :class="{ 'is-invalid': errorOf('subject') }" :disabled="form.locked">
                            <option v-for="s in options.subjects" :key="s" :value="s">{{ t(`moving.document_types.subject.${s}`) }}</option>
                        </select>
                        <div v-if="errorOf('subject')" class="invalid-feedback d-block">{{ errorOf('subject') }}</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('moving.document_types.kind_label') }} <span class="text-danger">*</span></label>
                        <select v-model="form.kind" class="form-select" :class="{ 'is-invalid': errorOf('kind') }" :disabled="form.locked">
                            <option v-for="k in options.kinds" :key="k" :value="k">{{ t(`moving.document_types.kind.${k}`) }}</option>
                        </select>
                        <div v-if="errorOf('kind')" class="invalid-feedback d-block">{{ errorOf('kind') }}</div>
                    </div>
                    <div class="col-md-8 d-flex flex-wrap gap-3 align-items-end">
                        <div v-for="flag in ['has_number', 'has_expiry', 'needs_back_side', 'status']" :key="flag" class="form-check form-switch">
                            <input :id="`pd-${flag}`" v-model="form[flag]" class="form-check-input" type="checkbox" :disabled="flag === 'status' && form.locked">
                            <label class="form-check-label" :for="`pd-${flag}`">{{ flag === 'status' ? t('moving.common.active') : t(`moving.document_types.${flag}`) }}</label>
                        </div>
                    </div>
                    <div v-if="form.has_expiry" class="col-md-4">
                        <label class="form-label">{{ t('moving.document_types.expiry_alert_days') }}</label>
                        <input v-model.number="form.expiry_alert_days" type="number" min="0" max="365" class="form-control" :class="{ 'is-invalid': errorOf('expiry_alert_days') }">
                        <div v-if="errorOf('expiry_alert_days')" class="invalid-feedback d-block">{{ errorOf('expiry_alert_days') }}</div>
                    </div>
                </div>

                <hr>
                <div class="d-flex align-items-center mb-2">
                    <h6 class="mb-0">{{ t('moving.document_types.required_from') }}</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" @click="addRequirement"><i class="ri-add-line me-1"></i>{{ t('moving.document_types.add_requirement') }}</button>
                </div>
                <div v-if="!form.requirements.length" class="text-muted fs-12 mb-2">{{ t('moving.document_types.optional_everywhere') }}</div>
                <div v-for="(r, i) in form.requirements" :key="i" class="row g-2 align-items-center mb-2">
                    <div class="col-md-3">
                        <select v-model="r.country_id" class="form-select form-select-sm" :class="{ 'is-invalid': errorOf(`requirements.${i}.country_id`) }">
                            <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select v-model="r.service_category_id" class="form-select form-select-sm">
                            <option :value="null">{{ t('moving.document_types.any_service') }}</option>
                            <option v-for="s in options.services" :key="s.id" :value="s.id">{{ s.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select v-model="r.vehicle_type_id" class="form-select form-select-sm" :disabled="form.subject !== 'vehicle'">
                            <option :value="null">{{ t('moving.document_types.any_vehicle') }}</option>
                            <option v-for="v in options.vehicle_types" :key="v.id" :value="v.id">{{ v.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select v-model="r.establishment_type" class="form-select form-select-sm">
                            <option :value="null">{{ t('moving.document_types.both_types') }}</option>
                            <option v-for="e in options.establishment_types" :key="e" :value="e">{{ t(`moving.establishment_type.${e}`) }}</option>
                        </select>
                    </div>
                    <div class="col-md-1 text-end">
                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="form.requirements.splice(i, 1)"><i class="ri-close-line"></i></button>
                    </div>
                </div>
                <div v-if="error" class="text-danger fs-13 mt-2">{{ error }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showForm = false">{{ t('cancel') }}</button>
                <button type="submit" form="provider-doc-type-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="t('confirm.delete_title')"
            :message="deleteConfirm.state.message"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close()"
            @confirm="handleDelete"
        />
    </div>
</template>

<script setup>
import RideLangFields from '../../rides/RideLangFields.vue';
import { onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Papers and vehicle photos asked from provider establishments, vehicles and drivers (docs/moving_tasks.md A-11),
// and where each is required. The photo showing the vehicle with its plate is always asked and is locked.
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const rows = ref([]);
const loading = ref(false);
const options = reactive({ countries: [], services: [], vehicle_types: [], subjects: [], kinds: [], establishment_types: [] });
const showForm = ref(false);
const saving = ref(false);
const error = ref('');
const errors = reactive({});
const errorOf = (key) => errors[key]?.[0] ?? null;
const clearErrors = () => Object.keys(errors).forEach((key) => delete errors[key]);
const deleteConfirm = useConfirmDelete();
const blank = () => ({
    id: null, locked: false, code: '', subject: 'vehicle', kind: 'photo', has_number: false, has_expiry: false, needs_back_side: false,
    expiry_alert_days: null, status: true, name: { ar: '', en: '' }, instructions: { ar: '', en: '' }, requirements: [],
});
const form = reactive(blank());

const nameOf = (list, id) => list.find((x) => x.id === id)?.name ?? `#${id}`;
const subjectIcon = (s) => ({ vehicle: 'ri-truck-line', driver: 'ri-steering-2-line', establishment: 'ri-store-2-line' }[s] ?? 'ri-file-line');

function openForm(row) {
    error.value = '';
    clearErrors();
    Object.assign(form, blank(), row ? {
        id: row.id, locked: row.locked, code: row.code, subject: row.subject, kind: row.kind, has_number: row.has_number, has_expiry: row.has_expiry,
        needs_back_side: row.needs_back_side, expiry_alert_days: row.expiry_alert_days, status: row.status,
        name: { ar: row.names?.ar ?? '', en: row.names?.en ?? '' },
        instructions: { ar: row.instructions?.ar ?? '', en: row.instructions?.en ?? '' },
        requirements: row.requirements.map((r) => ({ country_id: r.country_id, service_category_id: r.service_category_id, vehicle_type_id: r.vehicle_type_id, establishment_type: r.establishment_type })),
    } : {});
    showForm.value = true;
}

function addRequirement() {
    form.requirements.push({ country_id: options.countries[0]?.id ?? null, service_category_id: null, vehicle_type_id: null, establishment_type: null });
}

async function save() {
    error.value = '';
    clearErrors();
    saving.value = true;
    try {
        if (form.id) {
            await adminAxios.put(`/api/admin/v1/provider-document-types/${form.id}`, form);
        } else {
            await adminAxios.post('/api/admin/v1/provider-document-types', form);
        }
        showForm.value = false;
        showSuccess(t('moving.common.saved'));
        load();
    } catch (e) {
        const bag = e?.response?.data?.errors ?? {};
        Object.assign(errors, bag);
        // A message no field shows (a requirement row, the image…) still appears under the form.
        const shown = ['code', 'name.ar', 'name.en', 'subject', 'kind', 'sort_order', 'expiry_alert_days', 'image'];
        const rest = Object.entries(bag).find(([key]) => !shown.includes(key));
        error.value = Object.keys(bag).length ? (rest?.[1]?.[0] ?? '') : extractApiErrorMessage(e);
    } finally {
        saving.value = false;
    }
}

function remove(row) {
    deleteConfirm.open({ title: t('confirm.delete_title'), message: t('moving.common.confirm_delete_named', { name: row.name || row.code }), payload: row });
}

async function handleDelete() {
    deleteConfirm.setLoading(true);
    try {
        await adminAxios.delete(`/api/admin/v1/provider-document-types/${deleteConfirm.state.payload.id}`);
        showSuccess(t('moving.common.deleted'));
        load();
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/provider-document-types');
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
        const { data } = await adminAxios.get('/api/admin/v1/provider-document-types/options');
        Object.assign(options, data.data ?? {});
    } catch (e) {
        showError(extractApiErrorMessage(e));
    }
});
</script>
