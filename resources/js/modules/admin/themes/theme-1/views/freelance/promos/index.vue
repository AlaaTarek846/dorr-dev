<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.promos.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.promos.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.promos.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <FlStatusFilter v-model="state" :options="states" />
                <select v-model="audience" class="form-select form-select-sm w-auto" :aria-label="t('freelance.promos.audience')">
                    <option value="">{{ t('freelance.promos.audiences.all') }}</option>
                    <option v-for="a in audiences" :key="a" :value="a">{{ t(`freelance.promos.audiences.${a}`) }}</option>
                </select>
                <div class="ms-auto d-flex align-items-center gap-2">
                    <input v-model="search" type="search" class="form-control form-control-sm" style="min-width: 200px" maxlength="80" :placeholder="t('freelance.promos.search')">
                    <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave text-nowrap" @click="openForm(null)">
                        <i class="ri-add-line me-1"></i>{{ t('freelance.promos.add') }}
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.promos.banner') }}</th>
                                <th>{{ t('freelance.promos.audience') }}</th>
                                <th>{{ t('freelance.promos.opens') }}</th>
                                <th>{{ t('freelance.promos.country') }}</th>
                                <th>{{ t('freelance.promos.period') }}</th>
                                <th class="text-center">{{ t('freelance.promos.order') }}</th>
                                <th>{{ t('freelance.promos.state') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="4" :layout="['two-lines', 'badge', 'text', 'text', 'two-lines', 'text', 'badge', 'actions']" />
                            <tr v-else-if="!rows.length"><td colspan="8" class="text-center text-muted py-5">{{ t('freelance.promos.empty') }}</td></tr>
                            <tr v-for="p in rows" v-else :key="p.uuid">
                                <td class="ps-4">
                                    <div class="d-flex align-items-center gap-3">
                                        <div class="promo-thumb rounded-3 flex-shrink-0" :style="p.image_path ? { backgroundImage: `url(${p.image_path})` } : null">
                                            <i v-if="!p.image_path" class="ri-image-line"></i>
                                        </div>
                                        <div class="min-w-0">
                                            <span class="d-block fw-semibold text-truncate" style="max-width: 260px">{{ text(p.title) }}</span>
                                            <span class="d-block text-muted fs-12 text-truncate" style="max-width: 260px">{{ text(p.body) || '—' }}</span>
                                        </div>
                                    </div>
                                </td>
                                <td><span class="badge" :class="audienceBadge[p.audience] ?? audienceBadge.freelancer">{{ t(`freelance.promos.audiences.${p.audience ?? 'freelancer'}`) }}</span></td>
                                <td>
                                    <span v-if="p.target_type === 'screen'" class="badge bg-primary-transparent">{{ screenLabel(p.target, p.audience) }}</span>
                                    <a v-else-if="p.target_type === 'url'" :href="p.target" target="_blank" rel="noopener noreferrer" class="fs-12 text-truncate d-inline-block" style="max-width: 180px">{{ p.target }}</a>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td>{{ p.country || t('freelance.promos.all_countries') }}</td>
                                <td class="fs-12">
                                    <span class="d-block">{{ t('freelance.promos.from') }}: {{ day(p.starts_at) }}</span>
                                    <span class="d-block text-muted">{{ t('freelance.promos.to') }}: {{ p.ends_at ? day(p.ends_at) : t('freelance.promos.open_ended') }}</span>
                                </td>
                                <td class="text-center">{{ p.sort_order }}</td>
                                <td><span class="badge" :class="badge[p.state]">{{ t(`freelance.promos.states.${p.state}`) }}</span></td>
                                <td class="text-end pe-4">
                                    <div class="d-inline-flex gap-1">
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-icon btn-light" :title="p.is_active ? t('freelance.promos.turn_off') : t('freelance.promos.turn_on')" @click="toggle(p)">
                                            <i :class="p.is_active ? 'ri-pause-circle-line' : 'ri-play-circle-line'"></i>
                                        </button>
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-icon btn-primary-light" :title="t('freelance.promos.edit')" @click="openForm(p)"><i class="ri-edit-line"></i></button>
                                        <button v-if="canDelete" type="button" class="btn btn-sm btn-icon btn-danger-light" :title="t('freelance.promos.delete')" @click="remove(p)"><i class="ri-delete-bin-line"></i></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="!loading && rows.length && (pagination.last_page ?? 1) > 1" class="card-footer border-top-0 d-flex align-items-center gap-3">
                <div class="text-muted fs-13">{{ t('countries.showing_entries', { from: pagination.from ?? 0, to: pagination.to ?? 0, total: pagination.total ?? 0 }) }}</div>
                <nav class="pagination-style-4 ms-md-auto">
                    <ul class="pagination mb-0">
                        <li class="page-item" :class="{ disabled: page <= 1 }"><button type="button" class="page-link" @click="page--">{{ t('countries.previous') }}</button></li>
                        <li class="page-item active"><span class="page-link">{{ page }}</span></li>
                        <li class="page-item" :class="{ disabled: page >= pagination.last_page }"><button type="button" class="page-link text-primary" @click="page++">{{ t('countries.next') }}</button></li>
                    </ul>
                </nav>
            </div>
        </div>

        <!-- Add / change a banner, with a live look at how the app shows it. -->
        <WalletModal :show="formOpen" :title="editing ? t('freelance.promos.edit') : t('freelance.promos.add')" smooth @close="formOpen = false">
            <form @submit.prevent="save">
                <div class="promo-preview rounded-4 mb-3" :style="previewUrl ? { backgroundImage: `linear-gradient(90deg, rgba(0,0,0,.55), rgba(0,0,0,.05)), url(${previewUrl})` } : null">
                    <div class="promo-preview-text">
                        <div class="fw-bold fs-16">{{ form.title[locale] || form.title.ar || t('freelance.promos.preview_title') }}</div>
                        <div class="fs-12 opacity-75 mt-1">{{ form.body[locale] || form.body.ar }}</div>
                        <span v-if="form.cta[locale] || form.cta.ar" class="badge bg-white text-dark mt-2 px-3 py-2">{{ form.cta[locale] || form.cta.ar }}</span>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.promos.title_ar') }} <span class="text-danger">*</span></label>
                        <input v-model="form.title.ar" type="text" class="form-control" maxlength="80" dir="rtl" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.promos.title_en') }} <span class="text-danger">*</span></label>
                        <input v-model="form.title.en" type="text" class="form-control" maxlength="80" dir="ltr" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.promos.body_ar') }}</label>
                        <input v-model="form.body.ar" type="text" class="form-control" maxlength="160" dir="rtl">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.promos.body_en') }}</label>
                        <input v-model="form.body.en" type="text" class="form-control" maxlength="160" dir="ltr">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.promos.cta_ar') }}</label>
                        <input v-model="form.cta.ar" type="text" class="form-control" maxlength="30" dir="rtl">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.promos.cta_en') }}</label>
                        <input v-model="form.cta.en" type="text" class="form-control" maxlength="30" dir="ltr">
                    </div>

                    <div class="col-12">
                        <label class="form-label">{{ t('freelance.promos.audience') }}</label>
                        <div class="d-flex flex-wrap gap-3">
                            <div v-for="a in audiences" :key="a" class="form-check">
                                <input :id="`promo-audience-${a}`" v-model="form.audience" class="form-check-input" type="radio" :value="a">
                                <label class="form-check-label" :for="`promo-audience-${a}`">{{ t(`freelance.promos.audiences.${a}`) }}</label>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">{{ t('freelance.promos.opens') }}</label>
                        <select v-model="form.target_type" class="form-select">
                            <option value="none">{{ t('freelance.promos.target.none') }}</option>
                            <option value="screen">{{ t('freelance.promos.target.screen') }}</option>
                            <option value="url">{{ t('freelance.promos.target.url') }}</option>
                        </select>
                    </div>
                    <div v-if="form.target_type === 'screen'" class="col-md-8">
                        <label class="form-label">{{ t('freelance.promos.screen') }} <span class="text-danger">*</span></label>
                        <select v-model="form.target" class="form-select" required>
                            <option v-for="s in formScreens" :key="s" :value="s">{{ screenLabel(s, form.audience) }}</option>
                        </select>
                    </div>
                    <div v-else-if="form.target_type === 'url'" class="col-md-8">
                        <label class="form-label">{{ t('freelance.promos.link') }} <span class="text-danger">*</span></label>
                        <input v-model="form.target" type="url" class="form-control" dir="ltr" placeholder="https://" pattern="https://.*" required>
                    </div>

                    <div class="col-md-6">
                        <CountrySelect v-model="form.country_id" :label="t('freelance.promos.country')" :placeholder="t('freelance.promos.all_countries')" />
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('freelance.promos.order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" max="1000" class="form-control">
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <div class="form-check form-switch mb-2">
                            <input id="promo-active" v-model="form.is_active" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="promo-active">{{ t('freelance.promos.active') }}</label>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.promos.starts_at') }}</label>
                        <input v-model="form.starts_at" type="datetime-local" class="form-control">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('freelance.promos.ends_at') }}</label>
                        <input v-model="form.ends_at" type="datetime-local" class="form-control">
                    </div>

                    <div class="col-12">
                        <label class="form-label">{{ t('freelance.promos.image') }}</label>
                        <input ref="fileInput" type="file" class="form-control" accept="image/jpeg,image/png,image/webp" @change="pickImage">
                        <div class="form-text">{{ t('freelance.promos.image_hint') }}</div>
                        <button v-if="editing && previewUrl && !form.image" type="button" class="btn btn-link btn-sm text-danger px-0" @click="dropImage">{{ t('freelance.promos.remove_image') }}</button>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-4">
                    <button type="button" class="btn btn-light" @click="formOpen = false">{{ t('freelance.promos.cancel') }}</button>
                    <button type="submit" class="btn btn-primary" :disabled="saving">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.promos.save') }}
                    </button>
                </div>
            </form>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import CountrySelect from '../../../../../../../components/catalog/CountrySelect.vue';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The promo banners on the freelancer app's home and / or the client's freelance hub in the user app (`audience`): on / off, between dates, for a country or all, opening a page of the app or a web page.
const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { can } = usePermission();
const canCreate = computed(() => can('freelance-promos.create'));
const canUpdate = computed(() => can('freelance-promos.update'));
const canDelete = computed(() => can('freelance-promos.delete'));
const base = '/api/admin/v1/freelance-promos';

const badge = { showing: 'bg-success-transparent', scheduled: 'bg-info-transparent', ended: 'bg-secondary-transparent', off: 'bg-warning-transparent' };
const states = computed(() => [
    { value: '', label: t('freelance.promos.states.all'), tone: 'all' },
    ...['showing', 'scheduled', 'ended', 'off'].map((s) => ({ value: s, label: t(`freelance.promos.states.${s}`), tone: s === 'showing' ? 'active' : s === 'off' ? 'pending' : 'all' })),
]);

const audiences = ['freelancer', 'client', 'both'];
const audienceBadge = { freelancer: 'bg-secondary-transparent', client: 'bg-info-transparent', both: 'bg-success-transparent' };
// A page's name in the app the banner is for (the client's names for «client» and «both»: both apps have those pages).
const screenLabel = (s, audience) => t(audience === 'client' || audience === 'both' ? `freelance.promos.client_screens.${s}` : `freelance.promos.screens.${s}`);

const text = (v) => (v ? (v[locale.value] || v.ar || v.en || '') : '');
const day = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : t('freelance.promos.now'));
// datetime-local works in the browser's time; the API takes ISO with the zone.
const toLocalInput = (iso) => {
    if (!iso) return '';
    const d = new Date(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`;
};

const rows = ref([]);
const screens = ref([]);
// The pages a banner may open, for each audience (from the list's meta).
const screensByAudience = ref({});
const audience = ref('');
const pagination = ref({});
const page = ref(1);
const state = ref('');
const search = ref('');
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(base, { params: { page: page.value, state: state.value || undefined, audience: audience.value || undefined, search: search.value || undefined } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? data.meta?.pagination ?? {};
        screens.value = data.meta?.screens ?? data.screens ?? screens.value;
        screensByAudience.value = data.meta?.screens_by_audience ?? data.screens_by_audience ?? screensByAudience.value;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

let searchTimer = null;
watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 400);
});
watch([state, audience], () => (page.value === 1 ? load() : (page.value = 1)));
watch(page, load);
onMounted(load);

// ---------------------------------------------------------------- the form

const blank = () => ({
    title: { ar: '', en: '' }, body: { ar: '', en: '' }, cta: { ar: '', en: '' },
    target_type: 'none', target: '', audience: 'freelancer', country_id: null, starts_at: '', ends_at: '', sort_order: 0, is_active: true, image: null, remove_image: false,
});
const form = reactive(blank());
const formOpen = ref(false);
const editing = ref(null);
const saving = ref(false);
const previewUrl = ref('');
const fileInput = ref(null);

function openForm(p) {
    Object.assign(form, blank());
    editing.value = p?.uuid ?? null;
    previewUrl.value = p?.image_path ?? '';
    if (p) {
        Object.assign(form, {
            title: { ar: p.title?.ar ?? '', en: p.title?.en ?? '' }, body: { ar: p.body?.ar ?? '', en: p.body?.en ?? '' }, cta: { ar: p.cta?.ar ?? '', en: p.cta?.en ?? '' },
            target_type: p.target_type, target: p.target ?? '', audience: p.audience ?? 'freelancer', country_id: p.country_id, sort_order: p.sort_order, is_active: p.is_active,
            starts_at: toLocalInput(p.starts_at), ends_at: toLocalInput(p.ends_at),
        });
    }
    if (fileInput.value) fileInput.value.value = '';
    formOpen.value = true;
}

const formScreens = computed(() => screensByAudience.value[form.audience] ?? screens.value);

// A target that does not fit the kind (or the audience's pages) is cleared — checked on the values, so filling the form for a banner keeps its own.
watch(() => form.target_type, (type) => {
    if (type === 'screen') {
        if (!formScreens.value.includes(form.target)) form.target = formScreens.value[0] ?? '';
    } else if (type === 'url') {
        if (!/^https:\/\//.test(form.target)) form.target = '';
    } else {
        form.target = '';
    }
});
// Another audience may not have the page picked: the first of its pages then.
watch(() => form.audience, () => {
    if (form.target_type === 'screen' && !formScreens.value.includes(form.target)) form.target = formScreens.value[0] ?? '';
});

function pickImage(e) {
    const file = e.target.files?.[0] ?? null;
    form.image = file;
    form.remove_image = false;
    if (file) previewUrl.value = URL.createObjectURL(file);
}

function dropImage() {
    form.remove_image = true;
    previewUrl.value = '';
}

async function save() {
    saving.value = true;
    const body = new FormData();
    for (const field of ['title', 'body', 'cta']) {
        for (const lang of ['ar', 'en']) body.append(`${field}[${lang}]`, form[field][lang] ?? '');
    }
    body.append('target_type', form.target_type);
    body.append('audience', form.audience);
    if (form.target_type !== 'none') body.append('target', form.target);
    body.append('country_id', form.country_id ?? '');
    body.append('starts_at', form.starts_at ? new Date(form.starts_at).toISOString() : '');
    body.append('ends_at', form.ends_at ? new Date(form.ends_at).toISOString() : '');
    body.append('sort_order', String(form.sort_order ?? 0));
    body.append('is_active', form.is_active ? '1' : '0');
    if (form.image) body.append('image', form.image);
    if (form.remove_image) body.append('remove_image', '1');
    try {
        const response = await adminAxios.post(editing.value ? `${base}/${editing.value}` : base, body);
        showSuccess(extractApiMessage(response, t('toast.success')));
        formOpen.value = false;
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

async function toggle(p) {
    try {
        const response = await adminAxios.post(`${base}/${p.uuid}/toggle`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function remove(p) {
    if (!window.confirm(t('freelance.promos.delete_confirm'))) return;
    try {
        const response = await adminAxios.delete(`${base}/${p.uuid}`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}
</script>

<style scoped>
.promo-thumb {
    width: 84px;
    height: 42px;
    background: var(--primary01, #f3f4f6) center / cover no-repeat;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #9ca3af;
}
.promo-preview {
    min-height: 150px;
    padding: 20px;
    display: flex;
    align-items: flex-end;
    color: #fff;
    background: linear-gradient(135deg, rgb(var(--primary-rgb, 250 117 82)), rgba(var(--primary-rgb, 250 117 82), 0.7)) center / cover no-repeat;
}
.promo-preview-text {
    max-width: 75%;
}
</style>
