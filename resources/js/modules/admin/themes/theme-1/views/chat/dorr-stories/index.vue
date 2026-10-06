<template>
    <div>
        <WalletPageHeader :title="t('chat.dorr_stories.title')" :section="t('sidebar.chat')" :total="rows.length || null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <span class="text-muted fs-13">{{ t('chat.dorr_stories.intro') }}</span>
                <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.dorr_stories.add') }}
                </button>
            </div>
            <div class="card-body">
                <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
                <div v-else-if="!rows.length" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</div>
                <div v-else class="row g-3">
                    <div v-for="row in rows" :key="row.id" class="col-6 col-md-4 col-xl-3">
                        <div class="dorr-story-card" :class="{ off: !row.is_showing }">
                            <div class="dorr-story-thumb" :style="row.type === 'text' ? { background: gradient(row.style?.background) } : {}">
                                <img v-if="row.type === 'image' && row.media" :src="row.media.url" alt="">
                                <video v-else-if="row.type === 'video' && row.media" :src="row.media.url" muted preload="metadata"></video>
                                <div v-else class="dorr-story-text">{{ row.body }}</div>
                                <span class="badge dorr-story-state" :class="row.is_showing ? 'bg-success' : 'bg-secondary'">{{ row.is_showing ? t('chat.dorr_stories.showing') : t('chat.dorr_stories.hidden') }}</span>
                                <span class="badge bg-dark dorr-story-views"><i class="ri-eye-line me-1"></i>{{ row.views_count }}</span>
                            </div>
                            <div class="p-2">
                                <div class="fs-12 text-truncate">{{ row.body || '—' }}</div>
                                <div class="fs-11 text-muted">{{ when(row) }}</div>
                                <div class="d-flex align-items-center gap-1 mt-2">
                                    <div v-if="canUpdate" class="toggle toggle-success mb-0 me-auto" :class="{ on: row.status }" role="button" @click="toggleStatus(row)"><span></span></div>
                                    <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(row)"><i class="ri-pencil-line"></i></button>
                                    <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(row)"><i class="ri-delete-bin-line"></i></button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <WalletModal :show="showModal" :title="editingId ? t('chat.dorr_stories.edit') : t('chat.dorr_stories.add')" size="md" @close="showModal = false">
            <form id="dorr-story-form" @submit.prevent="save">
                <div class="btn-group w-100 mb-3" role="group">
                    <button v-for="type in ['image', 'video', 'text']" :key="type" type="button" class="btn btn-sm" :class="form.type === type ? 'btn-primary' : 'btn-outline-primary'" @click="form.type = type">
                        <i :class="{ image: 'ri-image-line', video: 'ri-video-line', text: 'ri-text' }[type]" class="me-1"></i>{{ t(`chat.dorr_stories.types.${type}`) }}
                    </button>
                </div>
                <div v-if="form.type !== 'text'" class="mb-3">
                    <label class="form-label">{{ t('chat.dorr_stories.file') }} <span v-if="!editingId" class="text-danger">*</span></label>
                    <input type="file" :accept="form.type === 'video' ? 'video/*' : 'image/*'" class="form-control" @change="form.file = $event.target.files?.[0] || null">
                    <div v-if="errors.file" class="text-danger fs-12 mt-1">{{ errors.file }}</div>
                </div>
                <div class="mb-3">
                    <label class="form-label">{{ form.type === 'text' ? t('chat.dorr_stories.text') : t('chat.dorr_stories.caption') }} <span v-if="form.type === 'text'" class="text-danger">*</span></label>
                    <textarea v-model="form.body" rows="3" maxlength="700" class="form-control"></textarea>
                    <div v-if="errors.body" class="text-danger fs-12 mt-1">{{ errors.body }}</div>
                </div>
                <div v-if="form.type === 'text'" class="mb-3">
                    <label class="form-label">{{ t('chat.dorr_stories.background') }}</label>
                    <div class="d-flex gap-2 flex-wrap">
                        <button v-for="bg in backgrounds" :key="bg" type="button" class="dorr-bg-swatch" :class="{ active: form.background === bg }" :style="{ background: gradient(bg) }" @click="form.background = bg"></button>
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-7">
                        <label class="form-label">{{ t('chat.dorr_stories.link') }}</label>
                        <input v-model="form.link_url" type="url" class="form-control" placeholder="https://" dir="ltr">
                        <div v-if="errors.link_url" class="text-danger fs-12 mt-1">{{ errors.link_url }}</div>
                    </div>
                    <div class="col-5">
                        <label class="form-label">{{ t('chat.dorr_stories.link_label') }}</label>
                        <input v-model="form.link_label" type="text" maxlength="60" class="form-control" :placeholder="t('chat.dorr_stories.link_label_hint')">
                    </div>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-6">
                        <label class="form-label">{{ t('chat.dorr_stories.starts_at') }}</label>
                        <input v-model="form.starts_at" type="datetime-local" class="form-control">
                    </div>
                    <div class="col-6">
                        <label class="form-label">{{ t('chat.dorr_stories.ends_at') }}</label>
                        <input v-model="form.ends_at" type="datetime-local" class="form-control">
                        <div v-if="errors.ends_at" class="text-danger fs-12 mt-1">{{ errors.ends_at }}</div>
                    </div>
                </div>
                <div class="row g-2 align-items-end">
                    <div class="col-5">
                        <label class="form-label">{{ t('chat.common.sort_order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-7">
                        <div class="form-check form-switch mb-2"><input id="ds-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="ds-status">{{ t('wallet.common.active') }}</label></div>
                    </div>
                </div>
                <div v-if="errors.general" class="text-danger mt-2 fs-13">{{ errors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="showModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="dorr-story-form" class="btn btn-primary" :disabled="saving">
                    <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canCreate = computed(() => can('chat-dorr-stories.create'));
const canUpdate = computed(() => can('chat-dorr-stories.update'));
const canDelete = computed(() => can('chat-dorr-stories.delete'));

/** The app's text-story backgrounds (StoryLook) — sent by name so the phone paints the same gradient. */
const looks = {
    dorr: ['#1E3A7B', '#7A0410'], sunset: ['#FF8A4C', '#DB2777'], ocean: ['#22D3EE', '#1D4ED8'], forest: ['#34D399', '#065F46'],
    grape: ['#A78BFA', '#5B21B6'], gold: ['#FBBF24', '#B45309'], night: ['#334155', '#020617'], rose: ['#FDA4AF', '#BE123C'],
};
const backgrounds = Object.keys(looks);
const gradient = (name) => `linear-gradient(160deg, ${(looks[name] || looks.dorr).join(', ')})`;

const rows = ref([]);
const loading = ref(false);
const showModal = ref(false);
const editingId = ref(null);
const saving = ref(false);
const errors = reactive({});
const blank = () => ({ type: 'image', body: '', background: backgrounds[0], link_url: '', link_label: '', starts_at: '', ends_at: '', sort_order: 0, status: true, file: null });
const form = reactive(blank());

const toLocalInput = (iso) => (iso ? new Date(iso).toISOString().slice(0, 16) : '');
const fmt = (iso) => new Date(iso).toLocaleString(locale.value, { dateStyle: 'medium', timeStyle: 'short' });

function when(row) {
    if (!row.starts_at && !row.ends_at) return t('chat.dorr_stories.always');
    return [row.starts_at ? fmt(row.starts_at) : '…', row.ends_at ? fmt(row.ends_at) : '…'].join(' → ');
}

function resetErrors() {
    Object.keys(errors).forEach((key) => delete errors[key]);
}

function openCreate() {
    editingId.value = null;
    resetErrors();
    Object.assign(form, blank(), { sort_order: rows.value.length });
    showModal.value = true;
}

function openEdit(row) {
    editingId.value = row.id;
    resetErrors();
    Object.assign(form, blank(), {
        type: row.type, body: row.body ?? '', background: row.style?.background || backgrounds[0], link_url: row.link_url ?? '', link_label: row.link_label ?? '',
        starts_at: toLocalInput(row.starts_at), ends_at: toLocalInput(row.ends_at), sort_order: row.sort_order ?? 0, status: !!row.status,
    });
    showModal.value = true;
}

async function save() {
    resetErrors();
    saving.value = true;

    const body = new FormData();
    body.append('type', form.type);
    body.append('body', form.body || '');
    if (form.type === 'text') body.append('style[background]', form.background);
    body.append('link_url', form.link_url || '');
    body.append('link_label', form.link_label || '');
    if (form.starts_at) body.append('starts_at', new Date(form.starts_at).toISOString());
    if (form.ends_at) body.append('ends_at', new Date(form.ends_at).toISOString());
    body.append('sort_order', String(form.sort_order || 0));
    body.append('status', form.status ? '1' : '0');
    if (form.file && form.type !== 'text') body.append('file', form.file);

    try {
        await adminAxios.post(editingId.value ? `/api/admin/v1/chat-dorr-stories/${editingId.value}` : '/api/admin/v1/chat-dorr-stories', body);
        showSuccess(t('chat.dorr_stories.saved'));
        showModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};

        Object.entries(bag).forEach(([key, messages]) => { errors[key] = messages[0]; });
        errors.general = Object.keys(bag).length ? '' : extractApiErrorMessage(error);
    } finally {
        saving.value = false;
    }
}

async function toggleStatus(row) {
    try {
        const { data } = await adminAxios.patch(`/api/admin/v1/chat-dorr-stories/${row.id}/status`, { status: !row.status });
        Object.assign(row, data.data ?? { status: !row.status });
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(row) {
    if (!window.confirm(t('chat.dorr_stories.confirm_delete'))) {
        return;
    }

    try {
        await adminAxios.delete(`/api/admin/v1/chat-dorr-stories/${row.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-dorr-stories');

        rows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.dorr-story-card { border: 1px solid var(--default-border, #eef0f3); border-radius: 14px; overflow: hidden; background: var(--custom-white, #fff); }
.dorr-story-card.off { opacity: 0.6; }
.dorr-story-thumb { position: relative; aspect-ratio: 9 / 16; background: #111; display: flex; align-items: center; justify-content: center; }
.dorr-story-thumb img, .dorr-story-thumb video { width: 100%; height: 100%; object-fit: cover; }
.dorr-story-text { color: #fff; font-weight: 800; text-align: center; padding: 12px; font-size: 15px; }
.dorr-story-state { position: absolute; top: 8px; inset-inline-start: 8px; }
.dorr-story-views { position: absolute; bottom: 8px; inset-inline-end: 8px; opacity: 0.85; }
.dorr-bg-swatch { width: 34px; height: 34px; border-radius: 50%; border: 2px solid transparent; }
.dorr-bg-swatch.active { border-color: var(--primary-color, #001B53); box-shadow: 0 0 0 2px #fff inset; }
</style>
