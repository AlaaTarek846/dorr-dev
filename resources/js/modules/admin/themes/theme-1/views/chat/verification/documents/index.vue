<template>
    <div>
        <WalletPageHeader :title="t('chat.verification.documents.title')" :section="t('sidebar.chat')" :total="loading ? null : rows.length" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('chat.verification.documents.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="f in trackFilters" :key="f || 'all'" type="button" class="btn" :class="trackFilter === f ? 'btn-primary' : 'btn-outline-light text-default'" @click="trackFilter = f">
                        {{ f ? t(`chat.verification.tracks.${f}`) : t('chat.verification.documents.all_tracks') }}
                    </button>
                </div>
                <button v-if="canUpdate" type="button" class="btn btn-primary btn-sm btn-wave" @click="openForm(null)">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.verification.documents.add') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.common.name') }}</th>
                                <th>{{ t('chat.verification.documents.tracks') }}</th>
                                <th>{{ t('chat.verification.documents.options') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="3" :layout="['two-lines', 'badge', 'badge', 'toggle', 'actions']" />
                            <tr v-else-if="!visibleRows.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in visibleRows" v-else :key="row.id">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-2">
                                        <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-file-shield-2-line"></i></span>
                                        <div>
                                            <span class="fw-semibold d-block">{{ row.name }}</span>
                                            <code class="fs-11 text-muted">{{ row.code }}</code>
                                        </div>
                                    </div>
                                </td>
                                <td><span v-for="tr in row.tracks" :key="tr" class="badge bg-info-transparent me-1">{{ t(`chat.verification.tracks.${tr}`) }}</span></td>
                                <td class="fs-12">
                                    <span class="badge me-1" :class="row.is_required ? 'bg-danger-transparent' : 'bg-secondary-transparent'">{{ row.is_required ? t('chat.verification.documents.required') : t('chat.verification.documents.optional') }}</span>
                                    <span v-if="row.needs_back_side" class="badge bg-primary-transparent">{{ t('chat.verification.documents.needs_back_side') }}</span>
                                </td>
                                <td>
                                    <div
                                        v-if="canUpdate"
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
                                    <div v-if="canUpdate" class="btn-list justify-content-end">
                                        <button type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('countries.edit_title')" @click="openForm(row)"><i class="ri-pencil-line"></i></button>
                                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" :title="t('countries.confirm_delete')" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <ConfirmDeleteModal :show="deleteConfirm.state.show" :title="deleteConfirm.state.title" :message="deleteConfirm.state.message" :loading="deleteConfirm.state.loading" @close="deleteConfirm.close()" @confirm="confirmRemove" />

        <WalletModal :show="showForm" :title="form.id ? t('chat.verification.documents.edit') : t('chat.verification.documents.add')" size="lg" @close="showForm = false">
            <form id="chat-verification-doc-form" novalidate @submit.prevent="save">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('chat.verification.documents.name_ar') }} <span class="text-danger">*</span></label>
                        <input v-model="form.name.ar" type="text" class="form-control" dir="rtl" :class="{ 'is-invalid': errors['name.ar'] }">
                        <div v-if="errors['name.ar']" class="text-danger fs-12 mt-1">{{ errors['name.ar'] }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('chat.verification.documents.name_en') }} <span class="text-danger">*</span></label>
                        <input v-model="form.name.en" type="text" class="form-control" dir="ltr" :class="{ 'is-invalid': errors['name.en'] }">
                        <div v-if="errors['name.en']" class="text-danger fs-12 mt-1">{{ errors['name.en'] }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('chat.verification.documents.code') }} <span class="text-danger">*</span></label>
                        <input v-model="form.code" type="text" maxlength="48" dir="ltr" class="form-control" :class="{ 'is-invalid': errors.code }" placeholder="identity_document">
                        <div v-if="errors.code" class="text-danger fs-12 mt-1">{{ errors.code }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('chat.verification.documents.tracks') }} <span class="text-danger">*</span></label>
                        <div class="d-flex gap-3 pt-1">
                            <div v-for="tr in tracks" :key="tr" class="form-check">
                                <input :id="`doc-track-${tr}`" v-model="form.tracks" class="form-check-input" type="checkbox" :value="tr">
                                <label class="form-check-label" :for="`doc-track-${tr}`">{{ t(`chat.verification.tracks.${tr}`) }}</label>
                            </div>
                        </div>
                        <div v-if="listError('tracks')" class="text-danger fs-12 mt-1">{{ listError('tracks') }}</div>
                    </div>
                    <div class="col-12 d-flex flex-wrap gap-4">
                        <div v-for="flag in ['is_required', 'needs_back_side', 'status']" :key="flag">
                            <label class="form-label d-block mb-2">{{ flag === 'status' ? t('wallet.common.status') : t(`chat.verification.documents.${flag === 'is_required' ? 'required' : flag}`) }}</label>
                            <div class="toggle toggle-success mb-0 catalog-modal-toggle" :class="{ on: form[flag] }" role="button" tabindex="0" @click="form[flag] = !form[flag]" @keydown.enter.space.prevent="form[flag] = !form[flag]"><span></span></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('chat.verification.documents.instructions_ar') }}</label>
                        <textarea v-model="form.instructions.ar" rows="2" class="form-control" dir="rtl"></textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('chat.verification.documents.instructions_en') }}</label>
                        <textarea v-model="form.instructions.en" rows="2" class="form-control" dir="ltr"></textarea>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger fs-13 mt-2">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showForm = false">{{ t('cancel') }}</button>
                <button type="submit" form="chat-verification-doc-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../../api/adminAxios';
import ConfirmDeleteModal from '../../../../../../../../components/ui/ConfirmDeleteModal.vue';
import TableSkeleton from '../../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../../composables/useToast';
import { useConfirmDelete } from '../../../../../../../../composables/useConfirmDelete';
import { usePermission } from '../../../../../../../../composables/usePermission';

// What a verification request asks the account to upload, per track (docs/verification_tasks.md AV-003).
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const deleteConfirm = useConfirmDelete();
const endpoint = '/api/admin/v1/chat-verification/document-types';

const canUpdate = computed(() => can('chat-verification-settings.update'));
const rows = ref([]);
const tracks = ref(['paid', 'celebrity']);
const loading = ref(false);
const toggling = ref([]);
const trackFilter = ref('');
const showForm = ref(false);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ id: null, code: '', tracks: [...tracks.value], is_required: true, needs_back_side: false, status: true, name: { ar: '', en: '' }, instructions: { ar: '', en: '' } });
const form = reactive(blank());

const trackFilters = computed(() => ['', ...tracks.value]);
const visibleRows = computed(() => (trackFilter.value ? rows.value.filter((r) => r.tracks.includes(trackFilter.value)) : rows.value));
const listError = (key) => errors[key] ?? Object.entries(errors).find(([k]) => k.startsWith(`${key}.`))?.[1];

function openForm(row) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.assign(form, blank(), row ? {
        id: row.id, code: row.code, tracks: [...row.tracks], is_required: row.is_required, needs_back_side: row.needs_back_side, status: row.status,
        name: { ar: row.names?.ar ?? '', en: row.names?.en ?? '' },
        instructions: { ar: row.instructions?.ar ?? '', en: row.instructions?.en ?? '' },
    } : {});
    showForm.value = true;
}

async function save() {
    Object.keys(errors).forEach((k) => delete errors[k]);
    saving.value = true;
    try {
        const response = form.id ? await adminAxios.put(`${endpoint}/${form.id}`, form) : await adminAxios.post(endpoint, form);
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
    deleteConfirm.open({ title: t('countries.confirm_delete'), message: t('chat.verification.documents.confirm_delete', { name: row.name }), payload: row });
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
        rows.value = data.data?.items ?? [];
        tracks.value = data.data?.tracks ?? tracks.value;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
