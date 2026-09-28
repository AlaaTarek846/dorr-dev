<template>
    <div>
        <WalletPageHeader :title="t('chat.report_types.title')" :section="t('sidebar.chat')" :total="rows.length || null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('chat.report_types.search')">
                </div>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.report_types.add') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.common.name') }}</th>
                                <th>{{ t('chat.report_types.reports_count') }}</th>
                                <th>{{ t('chat.common.sort_order') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="5" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!visibleRows.length"><td colspan="5" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in visibleRows" :key="row.id">
                                    <td class="ps-4 fw-semibold">{{ row.name || `#${row.id}` }}</td>
                                    <td><span class="badge bg-primary-transparent">{{ row.reports_count }}</span></td>
                                    <td>{{ row.sort_order }}</td>
                                    <td>
                                        <div v-if="canUpdate" class="toggle toggle-success mb-0" :class="{ on: row.status }" role="button" @click="toggleStatus(row)"><span></span></div>
                                        <span v-else class="badge" :class="row.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.status ? t('wallet.common.active') : t('wallet.common.inactive') }}</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <div class="btn-list justify-content-end">
                                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="editingId ? t('chat.report_types.edit') : t('chat.report_types.add')" size="md" @close="showModal = false">
            <form id="report-type-form" @submit.prevent="save">
                <div v-for="lang in languages" :key="lang.code" class="mb-2">
                    <label class="form-label">{{ t('chat.common.name') }} ({{ lang.name || lang.code }}) <span class="text-danger">*</span></label>
                    <input v-model="form.translations[lang.code]" type="text" maxlength="150" class="form-control">
                </div>
                <div v-if="errors.translations" class="text-danger fs-13 mb-2">{{ errors.translations }}</div>
                <div class="row g-2 align-items-end">
                    <div class="col-5">
                        <label class="form-label">{{ t('chat.common.sort_order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-7">
                        <div class="form-check form-switch mb-2"><input id="rt-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="rt-status">{{ t('wallet.common.active') }}</label></div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="report-type-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();

const canCreate = computed(() => can('chat-report-types.create'));
const canUpdate = computed(() => can('chat-report-types.update'));
const canDelete = computed(() => can('chat-report-types.delete'));

const languages = computed(() => languagesStore.items);
const rows = ref([]);
const loading = ref(false);
const search = ref('');
const visibleRows = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle ? rows.value.filter((row) => (row.translations ?? []).some((tr) => (tr.name || '').toLowerCase().includes(needle))) : rows.value;
});

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const form = reactive({ sort_order: 0, status: true, translations: {} });

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= ''; });
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    Object.assign(form, { sort_order: rows.value.length, status: true, translations: {} });
    ensureTranslations();
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, { sort_order: row.sort_order ?? 0, status: !!row.status, translations: {} });
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = tr.name ?? ''; });
    showModal.value = true;
}

async function save() {
    resetErrors();
    saving.value = true;

    const body = {
        sort_order: form.sort_order || 0,
        status: form.status,
        translations: languages.value.map((lang) => ({ locale: lang.code, name: form.translations[lang.code] })),
    };

    try {
        if (editingId.value) {
            await adminAxios.put(`/api/admin/v1/chat-report-types/${editingId.value}`, body);
        } else {
            await adminAxios.post('/api/admin/v1/chat-report-types', body);
        }

        showSuccess(t('chat.report_types.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { errors[key.startsWith('translations') ? 'translations' : key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/chat-report-types/${row.id}/status`, { status: ! row.status });
        row.status = ! row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (! window.confirm(t('chat.report_types.confirm_delete', { name: row.name || `#${row.id}` }))) {
        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/chat-report-types/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-report-types');

        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    load();
    await languagesStore.fetch();
});
</script>
