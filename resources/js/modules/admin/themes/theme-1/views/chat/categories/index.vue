<template>
    <div>
        <WalletPageHeader :title="t('chat.categories.title')" :section="t('sidebar.chat')" :total="rows.length || null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('chat.categories.search')">
                </div>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.categories.add') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.common.name') }}</th>
                                <th>{{ t('chat.categories.channels') }}</th>
                                <th>{{ t('chat.categories.portals') }}</th>
                                <th>{{ t('chat.common.sort_order') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!visibleRows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in visibleRows" :key="row.id">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm avatar-rounded bg-light">
                                                <img v-if="row.icon" :src="row.icon" alt="" style="object-fit: contain;">
                                                <i v-else class="ri-price-tag-3-line text-muted"></i>
                                            </span>
                                            <span class="fw-semibold">{{ row.name || `#${row.id}` }}</span>
                                        </div>
                                    </td>
                                    <td><span class="badge bg-primary-transparent">{{ row.channels_count }}</span></td>
                                    <td><span class="badge bg-info-transparent">{{ row.portals_count }}</span></td>
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

        <WalletModal :show="showModal" :title="editingId ? t('chat.categories.edit') : t('chat.categories.add')" size="md" @close="showModal = false">
            <form id="chat-category-form" @submit.prevent="save">
                <CatalogTranslationTabs
                    :languages="languages"
                    :active-locale="activeLocale"
                    :translation-tab-class="tabClass"
                    :translation-tab-feedback="tabFeedback"
                    @update:active-locale="activeLocale = $event"
                />
                <div class="mb-3">
                    <label class="form-label">{{ t('chat.common.name') }} <span class="text-danger">*</span></label>
                    <input v-model="form.translations[activeLocale]" type="text" maxlength="100" class="form-control" :class="{ 'is-invalid': errors.translations && !form.translations[activeLocale] }">
                    <div v-if="errors.translations" class="invalid-feedback d-block">{{ errors.translations }}</div>
                </div>

                <div class="mb-3">
                    <label class="form-label">{{ t('chat.categories.icon') }} <span v-if="!editingId" class="text-danger">*</span></label>
                    <div class="d-flex align-items-center gap-3">
                        <span class="avatar avatar-lg avatar-rounded bg-light border">
                            <img v-if="iconPreview" :src="iconPreview" alt="" style="object-fit: contain;">
                            <i v-else class="ri-image-add-line fs-20 text-muted"></i>
                        </span>
                        <input type="file" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="form-control" @change="pickIcon">
                    </div>
                    <div v-if="errors.icon" class="text-danger fs-12 mt-1">{{ errors.icon }}</div>
                </div>

                <div class="row g-2 align-items-end">
                    <div class="col-5">
                        <label class="form-label">{{ t('chat.common.sort_order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-7">
                        <div class="form-check form-switch mb-2"><input id="cat-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="cat-status">{{ t('wallet.common.active') }}</label></div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="chat-category-form" class="btn btn-primary" :disabled="saving">
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
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();

const canCreate = computed(() => can('chat-categories.create'));
const canUpdate = computed(() => can('chat-categories.update'));
const canDelete = computed(() => can('chat-categories.delete'));

const languages = computed(() => languagesStore.items);
const activeLocale = ref('');
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
const form = reactive({ sort_order: 0, status: true, translations: {}, icon: null });
const iconPreview = ref(null);

/** A tab shows a tick once its language has a name, a warning when saving failed without one. */
function tabClass(code) {
    const filled = !!(form.translations[code] || '').trim();

    return { active: activeLocale.value === code, 'catalog-lang-tab--error': !!errors.translations && !filled, 'catalog-lang-tab--valid': filled };
}

function tabFeedback(code) {
    const filled = !!(form.translations[code] || '').trim();

    return { show: filled || !!errors.translations, valid: filled };
}

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= ''; });
    if (!activeLocale.value && languages.value.length) activeLocale.value = languages.value[0].code;
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function pickIcon(event) {
    const file = event.target.files?.[0] || null;
    form.icon = file;
    if (file) iconPreview.value = URL.createObjectURL(file);
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    Object.assign(form, { sort_order: rows.value.length, status: true, translations: {}, icon: null });
    iconPreview.value = null;
    ensureTranslations();
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, { sort_order: row.sort_order ?? 0, status: !!row.status, translations: {}, icon: null });
    iconPreview.value = row.icon;
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = tr.name ?? ''; });
    activeLocale.value = languages.value[0]?.code || '';
    showModal.value = true;
}

async function save() {
    resetErrors();
    saving.value = true;

    const body = new FormData();
    languages.value.forEach((lang, i) => {
        body.append(`translations[${i}][locale]`, lang.code);
        body.append(`translations[${i}][name]`, form.translations[lang.code] || '');
    });
    body.append('sort_order', String(form.sort_order || 0));
    body.append('status', form.status ? '1' : '0');
    if (form.icon) body.append('icon', form.icon);

    try {
        await adminAxios.post(editingId.value ? `/api/admin/v1/chat-categories/${editingId.value}` : '/api/admin/v1/chat-categories', body);
        showSuccess(t('chat.categories.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { errors[key.startsWith('translations') ? 'translations' : key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);

        // Jump to the first language still missing its name.
        const missing = languages.value.find((lang) => !(form.translations[lang.code] || '').trim());
        if (errors.translations && missing) activeLocale.value = missing.code;
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    try {
        await adminAxios.patch(`/api/admin/v1/chat-categories/${row.id}/status`, { status: !row.status });
        row.status = !row.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (!window.confirm(t('chat.categories.confirm_delete', { name: row.name || `#${row.id}` }))) {
        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/chat-categories/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-categories');

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
