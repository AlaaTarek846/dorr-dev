<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">
                {{ t('freelance.checklist_templates.title') }}
                <span v-if="!loading" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ rows.length }}</span>
            </h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.checklist_templates.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border mb-3 fs-13">{{ t('freelance.checklist_templates.hint', { max: options.max_checks }) }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-end flex-wrap gap-3 py-3">
                <div class="catalog-toolbar-actions d-flex flex-wrap align-items-center gap-2">
                    <button v-if="can('freelance-checklist-templates.create')" type="button" class="btn btn-primary btn-sm btn-wave" @click="openForm(null)">
                        <i class="ri-add-line me-1 align-middle"></i>{{ t('freelance.checklist_templates.add') }}
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.common.name') }}</th>
                                <th>{{ t('freelance.checklist_templates.specialty') }}</th>
                                <th>{{ t('freelance.checklist_templates.checks') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="5" :layout="['two-lines', 'badge', 'badge', 'toggle', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.id">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-list-check-2"></i></span>
                                        <div>
                                            <span class="fw-semibold d-block">{{ row.name }}</span>
                                            <code class="fs-11 text-muted">{{ row.code }}</code>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span v-if="row.specialty" class="badge bg-info-transparent">{{ row.specialty }}</span>
                                    <span v-else class="text-muted fs-12">{{ t('freelance.checklist_templates.any_project') }}</span>
                                </td>
                                <td><span class="badge bg-primary-transparent">{{ checkCount(row) }}</span></td>
                                <td>
                                    <div
                                        v-if="can('freelance-checklist-templates.update')"
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
                                        <button v-if="can('freelance-checklist-templates.update')" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('countries.edit_title')" @click="openForm(row)"><i class="ri-pencil-line"></i></button>
                                        <button v-if="can('freelance-checklist-templates.delete')" type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('countries.confirm_delete')" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <ConfirmDeleteModal :show="deleteConfirm.state.show" :title="deleteConfirm.state.title" :message="deleteConfirm.state.message" :loading="deleteConfirm.state.loading" @close="deleteConfirm.close()" @confirm="confirmRemove" />

        <WalletModal :show="showForm" :title="form.id ? t('freelance.checklist_templates.edit') : t('freelance.checklist_templates.add')" size="lg" @close="showForm = false">
            <form id="freelance-checklist-template-form" novalidate @submit.prevent="save">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.checklist_templates.name_ar') }} <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-list-check-2"></i></span><input v-model="form.name.ar" type="text" maxlength="191" class="form-control" dir="rtl" :class="{ 'is-invalid': errors['name.ar'] }"></div>
                        <div v-if="errors['name.ar']" class="text-danger fs-12 mt-1">{{ errors['name.ar'] }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.checklist_templates.name_en') }} <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-list-check-2"></i></span><input v-model="form.name.en" type="text" maxlength="191" class="form-control" dir="ltr" :class="{ 'is-invalid': errors['name.en'] }"></div>
                        <div v-if="errors['name.en']" class="text-danger fs-12 mt-1">{{ errors['name.en'] }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.checklist_templates.code') }} <span class="text-danger">*</span></label>
                        <div class="input-group"><span class="input-group-text"><i class="ri-hashtag"></i></span><input v-model="form.code" type="text" maxlength="48" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.code }" placeholder="logo_design"></div>
                        <div v-if="errors.code" class="text-danger fs-12 mt-1">{{ errors.code }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.checklist_templates.specialty') }}</label>
                        <Select v-model="form.specialty_id" :options="specialtyOptions" option-label="label" option-value="value" filter :filter-placeholder="t('search_placeholder')" append-to="body" class="w-100" />
                        <div v-if="errors.specialty_id" class="text-danger fs-12 mt-1">{{ errors.specialty_id }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.checklist_templates.sort_order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control" dir="ltr" :class="{ 'is-invalid': errors.sort_order }">
                        <div v-if="errors.sort_order" class="text-danger fs-12 mt-1">{{ errors.sort_order }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label d-block mb-2">{{ t('wallet.common.status') }}</label>
                        <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form.status }" role="button" tabindex="0" @click="form.status = !form.status" @keydown.enter.space.prevent="form.status = !form.status"><span></span></div>
                    </div>
                </div>

                <hr>
                <div class="row g-3">
                    <div v-for="lang in ['ar', 'en']" :key="lang" class="col-md-6">
                        <div class="d-flex align-items-center mb-2">
                            <h6 class="mb-0">{{ t(`freelance.checklist_templates.checks_${lang}`) }} <span class="text-danger">*</span></h6>
                            <button type="button" class="btn btn-sm btn-outline-primary ms-auto" :disabled="form.items[lang].length >= options.max_checks" @click="form.items[lang].push('')"><i class="ri-add-line me-1"></i>{{ t('freelance.checklist_templates.add_check') }}</button>
                        </div>
                        <div v-for="(_, i) in form.items[lang]" :key="i" class="input-group mb-2">
                            <input v-model="form.items[lang][i]" type="text" maxlength="200" class="form-control" :dir="lang === 'ar' ? 'rtl' : 'ltr'" :class="{ 'is-invalid': errors[`items.${lang}.${i}`] }">
                            <button type="button" class="btn btn-danger-light btn-icon" :disabled="form.items[lang].length <= 1" @click="form.items[lang].splice(i, 1)"><i class="ri-close-line"></i></button>
                        </div>
                        <div v-if="itemsError(lang)" class="text-danger fs-12 mt-1">{{ itemsError(lang) }}</div>
                        <div class="text-muted fs-11 mt-1">{{ t('freelance.checklist_templates.max_checks', { n: form.items[lang].length, max: options.max_checks }) }}</div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger fs-13 mt-2">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showForm = false">{{ t('cancel') }}</button>
                <button type="submit" form="freelance-checklist-template-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
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

// The ready-made lists of what a client checks before approving a part of a project (FS-105), by kind of project
// (specialty) and in both languages.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const deleteConfirm = useConfirmDelete();
const endpoint = '/api/admin/v1/freelance-checklist-templates';

const rows = ref([]);
const loading = ref(false);
const toggling = ref([]);
const options = reactive({ specialties: [], max_checks: 10 });
const showForm = ref(false);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ id: null, code: '', specialty_id: null, status: true, sort_order: 0, name: { ar: '', en: '' }, items: { ar: [''], en: [''] } });
const form = reactive(blank());

const specialtyOptions = computed(() => [{ value: null, label: t('freelance.checklist_templates.any_project') }, ...options.specialties.map((s) => ({ value: s.id, label: s.name }))]);
// How many checks the list has, in the language the admin is reading.
const checkCount = (row) => (row.items?.[locale.value] ?? row.items?.en ?? row.items?.ar ?? []).length;
const itemsError = (lang) => errors[`items.${lang}`] ?? Object.entries(errors).find(([key]) => key.startsWith(`items.${lang}.`))?.[1] ?? '';

function openForm(row) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    const lines = (lang) => (row?.items?.[lang]?.length ? [...row.items[lang]] : ['']);
    Object.assign(form, blank(), row ? {
        id: row.id, code: row.code, specialty_id: row.specialty_id ?? null, status: row.status, sort_order: row.sort_order ?? 0,
        name: { ar: row.names?.ar ?? '', en: row.names?.en ?? '' },
        items: { ar: lines('ar'), en: lines('en') },
    } : {});
    showForm.value = true;
}

async function save() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    saving.value = true;
    try {
        // Blank lines are dropped: a check is a line with text in it.
        const clean = (list) => list.map((s) => String(s ?? '').trim()).filter(Boolean);
        const body = { ...form, sort_order: form.sort_order || 0, items: { ar: clean(form.items.ar), en: clean(form.items.en) } };
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
    deleteConfirm.open({ title: t('countries.confirm_delete'), message: t('freelance.checklist_templates.confirm_delete', { name: row.name }), payload: row });
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
