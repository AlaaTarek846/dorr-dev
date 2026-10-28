<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('freelance.document_types.title') }}
                <span v-if="!loading" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ rows.length }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.document_types.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border mb-3 fs-13">{{ t('freelance.document_types.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <Select
                        v-model="countryFilter"
                        :options="countryFilterOptions"
                        option-label="label"
                        option-value="value"
                        filter
                        :filter-placeholder="t('search_placeholder')"
                        class="wallet-filter-select"
                    />
                </div>
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm btn-wave" :disabled="downloading" @click="downloadGuide(`${endpoint}/guide`, { country_id: guideCountry }, 'freelance-verification-documents.docx')">
                        <span v-if="downloading" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-file-word-2-line me-1 align-middle"></i>{{ t('freelance.switches.guide') }}
                    </button>
                    <button v-if="can('freelance-document-types.create')" type="button" class="btn btn-primary btn-sm btn-wave" @click="openForm(null)">
                        <i class="ri-add-line me-1 align-middle"></i>{{ t('freelance.document_types.add') }}
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.common.name') }}</th>
                                <th>{{ t('freelance.document_types.fields') }}</th>
                                <th>{{ t('freelance.document_types.required_from') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :layout="['two-lines', 'badge', 'text', 'toggle', 'actions']" />
                            <tr v-else-if="!visibleRows.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in visibleRows" v-else :key="row.id">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-id-card-line"></i></span>
                                        <div>
                                            <span class="fw-semibold d-block">{{ row.name }}</span>
                                            <code class="fs-11 text-muted">{{ row.code }}</code>
                                        </div>
                                    </div>
                                </td>
                                <td class="fs-12">
                                    <span v-if="row.has_number" class="badge bg-primary-transparent me-1">{{ t('freelance.document_types.has_number') }}</span>
                                    <span v-if="row.has_expiry" class="badge bg-primary-transparent me-1">{{ t('freelance.document_types.has_expiry') }}</span>
                                    <span v-if="row.needs_back_side" class="badge bg-primary-transparent">{{ t('freelance.document_types.needs_back_side') }}</span>
                                </td>
                                <td class="text-wrap" style="min-width: 220px">
                                    <span v-if="!row.requirements.length" class="text-muted fs-12">{{ t('freelance.document_types.optional_everywhere') }}</span>
                                    <span v-for="r in row.requirements" :key="r.id" class="badge bg-info-transparent me-1 mb-1">
                                        {{ countryName(r.country_id) }} · {{ r.residency ? t(`freelance.document_types.residency.${r.residency}`) : t('freelance.document_types.everyone') }}
                                    </span>
                                </td>
                                <td>
                                    <div
                                        v-if="can('freelance-document-types.update')"
                                        class="toggle toggle-success mb-0 catalog-status-toggle"
                                        :class="{ on: row.status, 'catalog-status-toggle--loading': toggling.includes(row.id) }"
                                        role="button"
                                        tabindex="0"
                                        @click="toggle(row)"
                                        @keydown.enter.space.prevent="toggle(row)"
                                    ><span></span></div>
                                    <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-list justify-content-end">
                                        <button v-if="can('freelance-document-types.update')" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('countries.edit_title')" @click="openForm(row)"><i class="ri-pencil-line"></i></button>
                                        <button v-if="can('freelance-document-types.delete') && !row.documents_count" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('countries.confirm_delete')" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <ConfirmDeleteModal :show="deleteConfirm.state.show" :title="deleteConfirm.state.title" :message="deleteConfirm.state.message" :loading="deleteConfirm.state.loading" @close="deleteConfirm.close()" @confirm="confirmRemove" />

        <WalletModal :show="showForm" :title="form.id ? t('freelance.document_types.edit') : t('freelance.document_types.add')" size="lg" @close="showForm = false">
            <form id="freelance-doc-type-form" novalidate @submit.prevent="save">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.document_types.name_ar') }} <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-id-card-line"></i></span><input v-model="form.name.ar" type="text" class="form-control" dir="rtl" :class="{ 'is-invalid': errors['name.ar'] }"></div>
                        <div v-if="errors['name.ar']" class="text-danger fs-12 mt-1">{{ errors['name.ar'] }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.document_types.name_en') }} <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-id-card-line"></i></span><input v-model="form.name.en" type="text" class="form-control" dir="ltr" :class="{ 'is-invalid': errors['name.en'] }"></div>
                        <div v-if="errors['name.en']" class="text-danger fs-12 mt-1">{{ errors['name.en'] }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.document_types.code') }} <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-hashtag"></i></span><input v-model="form.code" type="text" maxlength="48" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.code }" placeholder="national_id"></div>
                        <div v-if="errors.code" class="text-danger fs-12 mt-1">{{ errors.code }}</div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-4">
                        <div v-for="flag in ['has_number', 'has_expiry', 'needs_back_side', 'status']" :key="flag">
                            <label class="form-label d-block mb-2">{{ flag === 'status' ? t('wallet.common.status') : t(`freelance.document_types.${flag}`) }}</label>
                            <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form[flag] }" role="button" tabindex="0" @click="form[flag] = !form[flag]" @keydown.enter.space.prevent="form[flag] = !form[flag]"><span></span></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.document_types.instructions_ar') }}</label>
                        <textarea v-model="form.instructions.ar" rows="2" class="form-control" dir="rtl"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.document_types.instructions_en') }}</label>
                        <textarea v-model="form.instructions.en" rows="2" class="form-control" dir="ltr"></textarea>
                    </div>
                </div>

                <hr>
                <div class="d-flex align-items-center mb-2">
                    <h6 class="mb-0">{{ t('freelance.document_types.required_from') }}</h6>
                    <button type="button" class="btn btn-sm btn-outline-primary ms-auto" @click="addRequirement"><i class="ri-add-line me-1"></i>{{ t('freelance.document_types.add_requirement') }}</button>
                </div>
                <div v-if="!form.requirements.length" class="text-muted fs-12 mb-2">{{ t('freelance.document_types.optional_everywhere') }}</div>
                <div v-for="(r, i) in form.requirements" :key="i" class="row g-2 align-items-center mb-2">
                    <div class="col-md-6">
                        <Select v-model="r.country_id" :options="countryOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" append-to="body" class="w-100" />
                    </div>
                    <div class="col-md-5">
                        <Select v-model="r.residency" :options="residencyOptions" option-label="label" option-value="value" append-to="body" class="w-100" />
                    </div>
                    <div class="col-md-1 text-end">
                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="form.requirements.splice(i, 1)"><i class="ri-close-line"></i></button>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger fs-13 mt-2">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showForm = false">{{ t('cancel') }}</button>
                <button type="submit" form="freelance-doc-type-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../../api/adminAxios';
import ConfirmDeleteModal from '../../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { useConfirmDelete } from '../../../../../../../composables/useConfirmDelete';
import { usePermission } from '../../../../../../../composables/usePermission';

// The identity documents freelancers upload (FL-107), and where each is required: the country, and from whom —
// citizens, residents or everyone.
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const deleteConfirm = useConfirmDelete();
const endpoint = '/api/admin/v1/freelance-document-types';

const rows = ref([]);
const loading = ref(false);
const toggling = ref([]);
const options = reactive({ countries: [], residencies: [] });
const countryFilter = ref('all');
const downloading = ref(false);
// The guide names what one country requires: the filtered one, else the first.
const guideCountry = computed(() => (countryFilter.value !== 'all' ? countryFilter.value : options.countries[0]?.id));
const showForm = ref(false);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ id: null, code: '', has_number: false, has_expiry: true, needs_back_side: false, status: true, name: { ar: '', en: '' }, instructions: { ar: '', en: '' }, requirements: [] });
const form = reactive(blank());

const countryName = (id) => options.countries.find((c) => c.id === id)?.name ?? `#${id}`;
const countryOptions = computed(() => options.countries.map((c) => ({ value: c.id, label: `${c.name} (${c.code})` })));
const countryFilterOptions = computed(() => [{ value: 'all', label: t('freelance.document_types.all_countries') }, ...countryOptions.value]);
const residencyOptions = computed(() => [{ value: 'everyone', label: t('freelance.document_types.everyone') }, ...options.residencies.map((r) => ({ value: r, label: t(`freelance.document_types.residency.${r}`) }))]);
const visibleRows = computed(() => (countryFilter.value === 'all' ? rows.value : rows.value.filter((r) => r.requirements.some((q) => q.country_id === countryFilter.value))));

function openForm(row) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.assign(form, blank(), row ? {
        id: row.id, code: row.code, has_number: row.has_number, has_expiry: row.has_expiry, needs_back_side: row.needs_back_side, status: row.status,
        name: { ar: row.names?.ar ?? '', en: row.names?.en ?? '' },
        instructions: { ar: row.instructions?.ar ?? '', en: row.instructions?.en ?? '' },
        requirements: row.requirements.map((r) => ({ country_id: r.country_id, residency: r.residency ?? 'everyone' })),
    } : {});
    showForm.value = true;
}

function addRequirement() {
    form.requirements.push({ country_id: countryFilter.value !== 'all' ? countryFilter.value : options.countries[0]?.id ?? null, residency: 'everyone' });
}

async function save() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    saving.value = true;
    try {
        // 'everyone' is the screen's word for a requirement that has no residency.
        const body = { ...form, requirements: form.requirements.map((r) => ({ ...r, residency: r.residency === 'everyone' ? null : r.residency })) };
        const response = form.id ? await adminAxios.put(`${endpoint}/${form.id}`, body) : await adminAxios.post(endpoint, body);
        showForm.value = false;
        showSuccess(extractApiMessage(response, t('toast.success')));
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key] ??= messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error, t('toast.error'));
    } finally {
        saving.value = false;
    }
}

async function toggle(row) {
    if (toggling.value.includes(row.id)) return;
    toggling.value.push(row.id);
    try {
        await adminAxios.patch(`${endpoint}/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        toggling.value = toggling.value.filter((id) => id !== row.id);
    }
}

function remove(row) {
    deleteConfirm.open({ title: t('countries.confirm_delete'), message: t('freelance.document_types.confirm_delete', { name: row.name }), payload: row });
}

async function confirmRemove() {
    const row = deleteConfirm.state.payload;
    deleteConfirm.setLoading(true);
    try {
        const response = await adminAxios.delete(`${endpoint}/${row.id}`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        deleteConfirm.close();
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        deleteConfirm.setLoading(false);
    }
}

async function downloadGuide(url, params, name) {
    downloading.value = true;
    try {
        const { data } = await adminAxios.get(url, { params, responseType: 'blob' });
        const href = URL.createObjectURL(data);
        const link = document.createElement('a');
        link.href = href;
        link.download = name;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(href);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        downloading.value = false;
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(endpoint);
        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load();
    try {
        const { data } = await adminAxios.get(`${endpoint}/options`);
        Object.assign(options, data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
});
</script>
