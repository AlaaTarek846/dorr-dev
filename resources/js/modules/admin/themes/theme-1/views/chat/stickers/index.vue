<template>
    <div>
        <WalletPageHeader :title="t('chat.stickers.title')" :section="t('sidebar.chat')" :total="packs.length || null" />

        <div class="alert alert-info fs-13">{{ t('chat.stickers.intro') }}</div>

        <div class="d-flex mb-3">
            <button v-if="canCreate" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openPack()">
                <i class="ri-add-line me-1 align-middle"></i>{{ t('chat.stickers.add_pack') }}
            </button>
        </div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
        <div v-else-if="!packs.length" class="card custom-card"><div class="card-body text-center text-muted py-5">{{ t('chat.stickers.empty') }}</div></div>

        <div v-else class="row g-3">
            <div v-for="pack in packs" :key="pack.id" class="col-xxl-3 col-xl-4 col-md-6">
                <div class="card custom-card h-100 mb-0 pack-card" :class="{ 'opacity-50': !pack.status }">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="pack-cover">
                                <img v-if="pack.cover" :src="pack.cover" alt="">
                                <i v-else class="ri-emotion-sticker-line"></i>
                            </div>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">{{ pack.name || `#${pack.id}` }}</div>
                                <div class="fs-12 text-muted">{{ t('chat.stickers.count', { count: pack.stickers.length }) }}</div>
                            </div>
                            <div v-if="canUpdate" class="toggle toggle-success mb-0" :class="{ on: pack.status }" role="button" @click="toggle(pack)"><span></span></div>
                        </div>
                        <div class="sticker-strip">
                            <img v-for="s in pack.stickers.slice(0, 8)" :key="s.id" :src="s.url" alt="">
                        </div>
                        <div class="btn-list mt-3">
                            <button v-if="canUpdate || canCreate" type="button" class="btn btn-sm btn-primary-light" @click="manage(pack)"><i class="ri-apps-2-line me-1"></i>{{ t('chat.stickers.manage') }}</button>
                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="openPack(pack)"><i class="ri-pencil-line"></i></button>
                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="removePack(pack)"><i class="ri-delete-bin-line"></i></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- pack name / cover -->
        <WalletModal :show="packModal" :title="form.id ? t('chat.stickers.edit_pack') : t('chat.stickers.add_pack')" size="md" @close="packModal = false">
            <form id="pack-form" @submit.prevent="savePack">
                <div v-for="lang in languages" :key="lang.code" class="mb-2">
                    <label class="form-label">{{ t('chat.common.name') }} ({{ lang.name || lang.code }}) <span class="text-danger">*</span></label>
                    <input v-model="form.translations[lang.code]" type="text" maxlength="100" class="form-control">
                </div>
                <div v-if="errors.translations" class="text-danger fs-13 mb-2">{{ errors.translations }}</div>
                <label class="form-label mt-2">{{ t('chat.stickers.cover') }}</label>
                <input type="file" accept="image/png,image/webp,image/gif" class="form-control" @change="form.cover = $event.target.files?.[0] || null">
                <div class="row g-2 mt-2 align-items-end">
                    <div class="col-5">
                        <label class="form-label">{{ t('chat.common.sort_order') }}</label>
                        <input v-model.number="form.sort_order" type="number" min="0" class="form-control">
                    </div>
                    <div class="col-7">
                        <div class="form-check form-switch mb-2"><input id="pk-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="pk-status">{{ t('wallet.common.active') }}</label></div>
                    </div>
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="saving" @click="packModal = false">{{ t('cancel') }}</button>
                <button type="submit" form="pack-form" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </template>
        </WalletModal>

        <!-- stickers of a pack -->
        <WalletModal :show="!!current" :title="current ? current.name : ''" size="lg" @close="current = null">
            <div v-if="current">
                <label v-if="canCreate" class="drop" :class="{ over: dragging }" @dragover.prevent="dragging = true" @dragleave="dragging = false" @drop.prevent="onDrop">
                    <input type="file" multiple accept="image/png,image/webp,image/gif" hidden @change="upload($event.target.files); $event.target.value = ''">
                    <i class="ri-upload-cloud-2-line fs-2 text-primary"></i>
                    <span class="fw-semibold">{{ t('chat.stickers.drop') }}</span>
                    <span class="fs-12 text-muted">{{ t('chat.stickers.drop_hint') }}</span>
                    <span v-if="uploading" class="spinner-border spinner-border-sm mt-1"></span>
                </label>
                <div class="d-flex align-items-center gap-2 mt-3 mb-2">
                    <label class="form-label mb-0 fs-13">{{ t('chat.stickers.emoji') }}</label>
                    <input v-model="newEmoji" type="text" maxlength="8" class="form-control form-control-sm" style="max-width: 90px;" placeholder="😀">
                    <span class="fs-12 text-muted">{{ t('chat.stickers.emoji_hint') }}</span>
                </div>
                <div class="sticker-grid">
                    <div v-for="s in current.stickers" :key="s.id" class="sticker-cell">
                        <img :src="s.url" alt="">
                        <input v-if="canUpdate" :value="s.emoji" type="text" maxlength="8" class="emoji-input" placeholder="🙂" @change="setEmoji(s, $event.target.value)">
                        <button v-if="canDelete" type="button" class="del" @click="removeSticker(s)"><i class="ri-close-line"></i></button>
                    </div>
                </div>
            </div>
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
const API = '/api/admin/v1';

const canCreate = computed(() => can('chat-stickers.create'));
const canUpdate = computed(() => can('chat-stickers.update'));
const canDelete = computed(() => can('chat-stickers.delete'));

const languages = computed(() => languagesStore.items);
const packs = ref([]);
const loading = ref(false);
const packModal = ref(false);
const saving = ref(false);
const errors = reactive({});
const form = reactive({ id: null, translations: {}, sort_order: 0, status: true, cover: null });
const current = ref(null);
const uploading = ref(false);
const dragging = ref(false);
const newEmoji = ref('');

function ensureTranslations() {
    languages.value.forEach((lang) => { form.translations[lang.code] ??= ''; });
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${API}/chat-sticker-packs`);
        packs.value = data.data ?? [];
        if (current.value) current.value = packs.value.find((p) => p.id === current.value.id) ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

function openPack(pack = null) {
    Object.keys(errors).forEach((k) => delete errors[k]);
    Object.assign(form, { id: pack?.id ?? null, translations: {}, sort_order: pack?.sort_order ?? packs.value.length, status: pack?.status ?? true, cover: null });
    ensureTranslations();
    (pack?.translations ?? []).forEach((tr) => { form.translations[tr.locale] = tr.name; });
    packModal.value = true;
}

async function savePack() {
    saving.value = true;
    Object.keys(errors).forEach((k) => delete errors[k]);
    const body = new FormData();
    languages.value.forEach((lang, i) => {
        body.append(`translations[${i}][locale]`, lang.code);
        body.append(`translations[${i}][name]`, form.translations[lang.code] || '');
    });
    body.append('sort_order', String(form.sort_order || 0));
    body.append('status', form.status ? '1' : '0');
    if (form.cover) body.append('cover', form.cover);
    try {
        await adminAxios.post(form.id ? `${API}/chat-sticker-packs/${form.id}` : `${API}/chat-sticker-packs`, body);
        showSuccess(t('chat.stickers.saved'));
        packModal.value = false;
        load();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { errors[key.startsWith('translations') ? 'translations' : key] = messages[0]; });
        if (! Object.keys(bag).length) showError(extractApiErrorMessage(error));
    } finally {
        saving.value = false;
    }
}

async function toggle(pack) {
    try {
        await adminAxios.patch(`${API}/chat-sticker-packs/${pack.id}/status`, { status: ! pack.status });
        pack.status = ! pack.status;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function removePack(pack) {
    if (! window.confirm(t('chat.stickers.confirm_delete_pack', { name: pack.name }))) return;
    try {
        await adminAxios.delete(`${API}/chat-sticker-packs/${pack.id}`);
        showSuccess(t('chat.common.deleted'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function manage(pack) {
    current.value = pack;
    newEmoji.value = '';
}

function onDrop(event) {
    dragging.value = false;
    upload(event.dataTransfer?.files);
}

async function upload(files) {
    const list = Array.from(files || []);
    if (! list.length || ! current.value) return;
    uploading.value = true;
    const body = new FormData();
    list.slice(0, 50).forEach((f) => body.append('files[]', f));
    if (newEmoji.value) body.append('emoji', newEmoji.value);
    try {
        const { data } = await adminAxios.post(`${API}/chat-sticker-packs/${current.value.id}/stickers`, body);
        current.value = data.data;
        showSuccess(t('chat.stickers.uploaded', { count: list.length }));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        uploading.value = false;
    }
}

async function setEmoji(sticker, emoji) {
    try {
        await adminAxios.patch(`${API}/chat-stickers/${sticker.id}`, { emoji: emoji || null });
        sticker.emoji = emoji;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function removeSticker(sticker) {
    try {
        await adminAxios.delete(`${API}/chat-stickers/${sticker.id}`);
        current.value.stickers = current.value.stickers.filter((s) => s.id !== sticker.id);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

watch(languages, () => ensureTranslations(), { immediate: true });

onMounted(async () => {
    load();
    await languagesStore.fetch();
});
</script>

<style scoped>
.pack-card { transition: transform .15s ease, box-shadow .15s ease; }
.pack-card:hover { transform: translateY(-3px); box-shadow: 0 8px 22px rgba(0, 0, 0, .08); }
.pack-cover { width: 56px; height: 56px; border-radius: 16px; background: var(--primary01, rgba(0,0,0,.05)); display: flex; align-items: center; justify-content: center; overflow: hidden; font-size: 28px; color: var(--primary-color); }
.pack-cover img { width: 100%; height: 100%; object-fit: contain; }
.sticker-strip { display: flex; gap: 6px; flex-wrap: wrap; min-height: 40px; }
.sticker-strip img { width: 40px; height: 40px; object-fit: contain; transition: transform .2s; }
.sticker-strip img:hover { transform: scale(1.3) rotate(-6deg); }
.drop { display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 22px; border: 2px dashed rgba(0, 0, 0, .15); border-radius: 16px; cursor: pointer; transition: all .2s; }
.drop.over, .drop:hover { border-color: rgb(var(--primary-rgb)); background: rgba(var(--primary-rgb), .05); }
.sticker-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(96px, 1fr)); gap: 10px; }
.sticker-cell { position: relative; border-radius: 14px; background: rgba(0, 0, 0, .03); padding: 8px; display: flex; flex-direction: column; align-items: center; }
.sticker-cell img { width: 76px; height: 76px; object-fit: contain; }
.emoji-input { width: 56px; border: 0; background: transparent; text-align: center; font-size: 16px; }
.sticker-cell .del { position: absolute; top: 4px; inset-inline-end: 4px; width: 24px; height: 24px; border: 0; border-radius: 50%; background: rgba(220, 38, 38, .9); color: #fff; display: none; align-items: center; justify-content: center; }
.sticker-cell:hover .del { display: flex; }
</style>
