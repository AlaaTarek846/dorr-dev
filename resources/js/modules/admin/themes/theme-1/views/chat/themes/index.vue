<template>
    <div>
        <WalletPageHeader :title="t('chat.themes.title')" :section="t('sidebar.chat')" :total="rows.length || null" />

        <div class="alert alert-info fs-13">{{ t('chat.themes.intro') }}</div>

        <div class="d-flex align-items-center flex-wrap gap-2 mb-3">
            <div class="input-group input-group-sm" style="max-width: 260px;">
                <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                <input v-model="search" type="search" class="form-control" :placeholder="t('chat.themes.search')">
            </div>
            <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.themes.add') }}
            </button>
        </div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
        <div v-else-if="!visibleRows.length" class="card custom-card"><div class="card-body text-center text-muted py-5">{{ t('wallet.common.empty') }}</div></div>

        <div v-else class="row g-3">
            <div v-for="row in visibleRows" :key="row.id" class="col-xxl-2 col-xl-3 col-lg-4 col-sm-6">
                <div class="card custom-card h-100 mb-0 theme-card" :class="{ 'opacity-50': !row.status }">
                    <ThemePreview :theme="row" />
                    <div class="card-body py-2 px-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="fw-semibold text-truncate">{{ row.name || `#${row.id}` }}</span>
                            <span v-if="row.is_default" class="badge bg-success-transparent ms-auto">{{ t('chat.themes.default') }}</span>
                        </div>
                        <div class="d-flex align-items-center mt-2">
                            <div v-if="canUpdate" class="toggle toggle-success mb-0" :class="{ on: row.status }" role="button" :title="t('wallet.common.status')" @click="toggleStatus(row)"><span></span></div>
                            <div class="btn-list ms-auto">
                                <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="editingId ? t('chat.themes.edit') : t('chat.themes.add')" size="lg" @close="showModal = false">
            <form id="chat-theme-form" class="row g-3" @submit.prevent="save">
                <div class="col-md-7">
                    <div v-for="lang in languages" :key="lang.code" class="mb-2">
                        <label class="form-label">{{ t('chat.common.name') }} ({{ lang.name || lang.code }}) <span class="text-danger">*</span></label>
                        <input v-model="form.translations[lang.code]" type="text" maxlength="100" class="form-control">
                    </div>
                    <div v-if="errors.translations" class="text-danger fs-13 mb-2">{{ errors.translations }}</div>

                    <div class="row g-2">
                        <div v-for="f in colorFields" :key="f" class="col-4">
                            <label class="form-label fs-12">{{ t(`chat.themes.${f}`) }}<span v-if="f !== 'background_color'" class="text-danger"> *</span></label>
                            <div class="d-flex gap-1 align-items-center">
                                <input v-model="form[f]" type="color" class="form-control form-control-color p-1" style="width: 42px;">
                                <input v-model="form[f]" type="text" maxlength="9" dir="ltr" class="form-control form-control-sm" :class="{ 'is-invalid': errors[f] }">
                            </div>
                            <div v-if="errors[f]" class="text-danger fs-11">{{ errors[f] }}</div>
                        </div>
                    </div>

                    <div class="mt-3">
                        <label class="form-label">{{ t('chat.themes.wallpaper') }}</label>
                        <input type="file" accept="image/*" class="form-control" :class="{ 'is-invalid': errors.wallpaper }" @change="pickWallpaper">
                        <div v-if="errors.wallpaper" class="invalid-feedback">{{ errors.wallpaper }}</div>
                        <button v-if="preview.wallpaper" type="button" class="btn btn-link btn-sm text-danger px-0" @click="clearWallpaper">
                            <i class="ri-close-line"></i> {{ t('chat.themes.remove_wallpaper') }}
                        </button>
                    </div>

                    <div class="row g-2 mt-1 align-items-end">
                        <div class="col-4">
                            <label class="form-label fs-12">{{ t('chat.common.sort_order') }}</label>
                            <input v-model.number="form.sort_order" type="number" min="0" class="form-control form-control-sm">
                        </div>
                        <div class="col-8 d-flex flex-wrap gap-3">
                            <div class="form-check form-switch"><input id="th-dark" v-model="form.is_dark" class="form-check-input" type="checkbox"><label class="form-check-label" for="th-dark">{{ t('chat.themes.is_dark') }}</label></div>
                            <div class="form-check form-switch"><input id="th-default" v-model="form.is_default" class="form-check-input" type="checkbox"><label class="form-check-label" for="th-default">{{ t('chat.themes.default') }}</label></div>
                            <div class="form-check form-switch"><input id="th-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="th-status">{{ t('wallet.common.active') }}</label></div>
                        </div>
                    </div>
                    <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
                </div>

                <div class="col-md-5">
                    <label class="form-label">{{ t('chat.themes.preview') }}</label>
                    <div class="rounded-3 overflow-hidden border">
                        <ThemePreview :theme="preview" tall />
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="chat-theme-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';
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

const canCreate = computed(() => can('chat-themes.create'));
const canUpdate = computed(() => can('chat-themes.update'));
const canDelete = computed(() => can('chat-themes.delete'));

/** A tiny chat screen: wallpaper (image or colour) with one bubble from each side. */
const ThemePreview = defineComponent({
    props: { theme: { type: Object, required: true }, tall: { type: Boolean, default: false } },
    setup(props) {
        const bubble = (mine, text) => h('div', {
            class: 'px-2 py-1 rounded-3 fs-12 shadow-sm',
            style: {
                maxWidth: '75%',
                alignSelf: mine ? 'flex-end' : 'flex-start',
                background: (mine ? props.theme.sender_color : props.theme.receiver_color) || '#fff',
                color: textOn(mine ? props.theme.sender_color : props.theme.receiver_color),
            },
        }, text);

        return () => h('div', {
            class: 'd-flex flex-column justify-content-end gap-2 p-3',
            style: {
                height: props.tall ? '280px' : '150px',
                backgroundColor: props.theme.background_color || (props.theme.is_dark ? '#0b141a' : '#efeae2'),
                backgroundImage: props.theme.wallpaper ? `url(${props.theme.wallpaper})` : 'none',
                backgroundSize: 'cover',
                backgroundPosition: 'center',
            },
        }, [bubble(false, t('chat.themes.sample_in')), bubble(true, t('chat.themes.sample_out'))]);
    },
});

/** Dark text on light bubbles, white on dark ones. */
function textOn(hex) {
    const m = /^#?([0-9a-f]{6})/i.exec(hex || '');

    if (! m) {
        return '#111';
    }

    const n = parseInt(m[1], 16);
    const luminance = (0.299 * (n >> 16) + 0.587 * ((n >> 8) & 255) + 0.114 * (n & 255)) / 255;

    return luminance > 0.6 ? '#111' : '#fff';
}

const colorFields = ['sender_color', 'receiver_color', 'background_color'];
const languages = computed(() => languagesStore.items);
const rows = ref([]);
const loading = ref(false);
const search = ref('');
const visibleRows = computed(() => {
    const needle = search.value.trim().toLowerCase();

    return needle ? rows.value.filter((row) => (row.name || '').toLowerCase().includes(needle)) : rows.value;
});

const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const emptyForm = () => ({
    sender_color: '#DCF8C6', receiver_color: '#FFFFFF', background_color: '',
    is_dark: false, is_default: false, status: true, sort_order: 0, translations: {},
    wallpaperFile: null, wallpaperUrl: null, removeWallpaper: false,
});
const form = reactive(emptyForm());
let objectUrl = null;

const preview = computed(() => ({
    sender_color: form.sender_color,
    receiver_color: form.receiver_color,
    background_color: form.background_color,
    is_dark: form.is_dark,
    wallpaper: form.wallpaperUrl,
}));

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= ''; });
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function releaseObjectUrl() {
    if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
        objectUrl = null;
    }
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    releaseObjectUrl();
    Object.assign(form, emptyForm());
    form.translations = {};
    ensureTranslations();
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    releaseObjectUrl();
    Object.assign(form, emptyForm(), {
        sender_color: row.sender_color, receiver_color: row.receiver_color, background_color: row.background_color || '',
        is_dark: !!row.is_dark, is_default: !!row.is_default, status: !!row.status, sort_order: row.sort_order ?? 0,
        wallpaperUrl: row.wallpaper,
    });
    form.translations = {};
    ensureTranslations();
    (row.translations ?? []).forEach((tr) => { form.translations[tr.locale] = tr.name ?? ''; });
    showModal.value = true;
}

function pickWallpaper(event) {
    const file = event.target.files?.[0];

    if (! file) {
        return;
    }

    releaseObjectUrl();
    objectUrl = URL.createObjectURL(file);
    form.wallpaperFile = file;
    form.wallpaperUrl = objectUrl;
    form.removeWallpaper = false;
}

function clearWallpaper() {
    releaseObjectUrl();
    form.wallpaperFile = null;
    form.wallpaperUrl = null;
    form.removeWallpaper = true;
}

async function save() {
    resetErrors();
    saving.value = true;

    const body = new FormData();
    languages.value.forEach((lang, i) => {
        body.append(`translations[${i}][locale]`, lang.code);
        body.append(`translations[${i}][name]`, form.translations[lang.code] || '');
    });
    colorFields.forEach((f) => { if (form[f]) body.append(f, form[f]); });
    ['is_dark', 'is_default', 'status'].forEach((f) => body.append(f, form[f] ? '1' : '0'));
    body.append('sort_order', String(form.sort_order || 0));

    if (form.wallpaperFile) {
        body.append('wallpaper', form.wallpaperFile);
    } else if (form.removeWallpaper) {
        body.append('remove_wallpaper', '1');
    }

    try {
        const url = editingId.value ? `/api/admin/v1/chat-themes/${editingId.value}` : '/api/admin/v1/chat-themes';

        await adminAxios.post(url, body);
        showSuccess(t('chat.themes.saved'));
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
        await adminAxios.patch(`/api/admin/v1/chat-themes/${row.id}/status`, { status: ! row.status });
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (! window.confirm(t('chat.themes.confirm_delete', { name: row.name || `#${row.id}` }))) {
        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/chat-themes/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-themes');

        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });
onBeforeUnmount(releaseObjectUrl);

onMounted(async () => {
    load();
    await languagesStore.fetch();
});
</script>

<style scoped>
.theme-card { transition: transform .15s ease, box-shadow .15s ease; overflow: hidden; }
.theme-card:hover { transform: translateY(-3px); box-shadow: 0 8px 22px rgba(0, 0, 0, .08); }
</style>
