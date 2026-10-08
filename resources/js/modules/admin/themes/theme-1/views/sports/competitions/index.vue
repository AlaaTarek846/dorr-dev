<template>
    <div>
        <WalletPageHeader :title="t('sports.competitions.title')" :section="t('sidebar.sports')" :total="meta.total || null" />

        <div class="alert alert-info fs-13">{{ t('sports.competitions.intro') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <ul class="nav nav-pills nav-style-3 gap-1">
                    <li v-for="tab in tierTabs" :key="tab" class="nav-item">
                        <button type="button" class="nav-link py-1 px-3" :class="{ active: filters.tier === tab }" @click="filters.tier = tab; load(1)">
                            {{ t(`sports.tiers.${tab || 'all'}`) }}
                            <span v-if="tab && tab !== 'active' && counts[tab]" class="badge bg-light text-dark ms-1">{{ counts[tab] }}</span>
                        </button>
                    </li>
                </ul>
                <select v-model="filters.sport" class="form-select form-select-sm" style="max-width: 160px;" @change="load(1)">
                    <option value="">{{ t('sports.common.all_sports') }}</option>
                    <option v-for="s in sports" :key="s.key" :value="s.key">{{ s.emoji }} {{ s.name }}</option>
                </select>
                <select v-model="filters.country" class="form-select form-select-sm" style="max-width: 180px;" @change="load(1)">
                    <option value="">{{ t('sports.common.all_countries') }}</option>
                    <option v-for="c in countries" :key="c" :value="c">{{ c }}</option>
                </select>
                <div class="input-group input-group-sm" style="max-width: 220px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('sports.competitions.search')" @keyup.enter="load(1)">
                </div>
                <div v-if="canImport" class="ms-auto d-flex gap-2">
                    <select v-model="importSport" class="form-select form-select-sm" style="max-width: 150px;">
                        <option v-for="s in sports" :key="s.key" :value="s.key">{{ s.emoji }} {{ s.name }}</option>
                    </select>
                    <button type="button" class="btn btn-primary btn-sm btn-wave text-nowrap" :disabled="importing" @click="runImport">
                        <span v-if="importing" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-download-cloud-2-line me-1"></i>{{ t('sports.competitions.import') }}
                    </button>
                </div>
            </div>

            <div v-if="selected.length && canUpdate" class="px-3 py-2 bg-light d-flex align-items-center gap-2 flex-wrap">
                <span class="fs-13 fw-semibold">{{ t('sports.competitions.selected', { n: selected.length }) }}</span>
                <button v-for="tier in tiers" :key="tier" type="button" class="btn btn-sm" :class="tierBtn(tier)" @click="bulk(tier)">{{ t(`sports.tiers.${tier}`) }}</button>
                <button type="button" class="btn btn-sm btn-link" @click="selected = []">{{ t('cancel') }}</button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-3" style="width: 32px;"><input class="form-check-input" type="checkbox" :checked="allSelected" @change="toggleAll"></th>
                                <th>{{ t('sports.competitions.name') }}</th>
                                <th>{{ t('sports.competitions.country') }}</th>
                                <th>{{ t('sports.competitions.season') }}</th>
                                <th>{{ t('sports.competitions.coverage') }}</th>
                                <th>{{ t('sports.competitions.scope') }}</th>
                                <th>{{ t('sports.competitions.tier') }}</th>
                                <th>{{ t('sports.competitions.priority') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="9" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="9" class="text-center text-muted py-5">{{ t('sports.competitions.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" :class="{ 'opacity-75': row.tier === 'off' }">
                                    <td class="ps-3"><input v-model="selected" class="form-check-input" type="checkbox" :value="row.id"></td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-sm bg-light"><img v-if="row.logo" :src="row.logo" alt="" style="object-fit: contain;"><i v-else class="ri-trophy-line text-muted"></i></span>
                                            <div>
                                                <div class="fw-semibold">{{ row.display_name }}</div>
                                                <div v-if="row.display_name !== row.name" class="text-muted fs-11">{{ row.name }}</div>
                                                <div class="text-muted fs-11">{{ sportEmoji(row.sport) }} {{ row.type }} · #{{ row.provider_id }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td><img v-if="row.flag" :src="row.flag" alt="" style="width: 18px; height: 13px; object-fit: cover;" class="me-1">{{ row.country }}</td>
                                    <td dir="ltr" class="text-start">{{ row.season }}</td>
                                    <td>
                                        <span v-for="c in coverageKeys" :key="c" class="me-1" :title="t(`sports.coverage.${c}`)" :class="row.coverage?.[c] ? 'text-success' : 'text-muted opacity-50'"><i :class="coverageIcon(c)"></i></span>
                                    </td>
                                    <td>
                                        <select :value="row.scope || ''" class="form-select form-select-sm" style="min-width: 130px;" :disabled="!canUpdate" @change="save(row, { scope: $event.target.value || null })">
                                            <option value="">—</option>
                                            <option v-for="s in scopes" :key="s" :value="s">{{ t(`sports.scopes.${s}`) }}</option>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button v-for="tier in tiers" :key="tier" type="button" class="btn" :class="row.tier === tier ? tierBtn(tier, true) : 'btn-outline-light text-muted'" :disabled="!canUpdate" @click="save(row, { tier })">{{ t(`sports.tiers.${tier}`) }}</button>
                                        </div>
                                    </td>
                                    <td><input :value="row.priority" type="number" min="0" class="form-control form-control-sm" style="width: 80px;" :disabled="!canUpdate" @change="save(row, { priority: Number($event.target.value) || 0 })"></td>
                                    <td class="text-end pe-4">
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" :title="t('sports.competitions.names')" @click="openNames(row)"><i class="ri-translate-2"></i></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="meta.last_page > 1" class="card-footer d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-sm btn-light" :disabled="meta.current_page <= 1" @click="load(meta.current_page - 1)"><i class="ri-arrow-left-s-line"></i></button>
                <span class="align-self-center fs-13">{{ meta.current_page }} / {{ meta.last_page }}</span>
                <button type="button" class="btn btn-sm btn-light" :disabled="meta.current_page >= meta.last_page" @click="load(meta.current_page + 1)"><i class="ri-arrow-right-s-line"></i></button>
            </div>
        </div>

        <WalletModal :show="!!namesRow" :title="t('sports.competitions.names')" size="md" @close="namesRow = null">
            <form v-if="namesRow" id="sports-names-form" @submit.prevent="saveNames">
                <p class="text-muted fs-13">{{ namesRow.name }}</p>
                <div v-for="lang in languages" :key="lang.code" class="mb-2">
                    <label class="form-label fs-13">{{ lang.name || lang.code }}</label>
                    <input v-model="names[lang.code]" type="text" maxlength="160" class="form-control" :placeholder="namesRow.name">
                </div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="namesRow = null">{{ t('cancel') }}</button>
                <button type="submit" form="sports-names-form" class="btn btn-primary">{{ t('save') }}</button>
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
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const languagesStore = useAvailableLanguagesStore();
const canUpdate = computed(() => can('sports-competitions.update'));
const canImport = computed(() => can('sports-competitions.create'));

const tiers = ['big', 'normal', 'minor', 'off'];
const tierTabs = ['active', 'big', 'normal', 'minor', 'off', ''];
const scopes = ['international', 'continental', 'regional', 'domestic'];
const coverageKeys = ['events', 'lineups', 'statistics', 'standings'];

const rows = ref([]);
const meta = ref({});
const counts = ref({});
const countries = ref([]);
const sports = ref([]);
const loading = ref(false);
const importing = ref(false);
const importSport = ref('football');
const filters = reactive({ tier: 'active', sport: '', country: '', search: '' });
const selected = ref([]);
const namesRow = ref(null);
const names = reactive({});
const languages = computed(() => languagesStore.items);
const allSelected = computed(() => rows.value.length > 0 && rows.value.every((r) => selected.value.includes(r.id)));

function tierBtn(tier, solid = false) {
    const map = { big: 'danger', normal: 'primary', minor: 'secondary', off: 'light' };
    return solid ? `btn-${map[tier]}` : `btn-${map[tier]}-light`;
}

function coverageIcon(c) {
    return { events: 'ri-football-line', lineups: 'ri-team-line', statistics: 'ri-bar-chart-2-line', standings: 'ri-list-ordered' }[c];
}

function sportEmoji(key) {
    return sports.value.find((s) => s.key === key)?.emoji || '';
}

function toggleAll() {
    selected.value = allSelected.value ? [] : rows.value.map((r) => r.id);
}

async function save(row, patch) {
    try {
        const { data } = await adminAxios.patch(`/api/admin/v1/sports-competitions/${row.id}`, patch);
        Object.assign(row, data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function bulk(tier) {
    try {
        await adminAxios.patch('/api/admin/v1/sports-competitions/bulk', { ids: selected.value, tier });
        showSuccess(t('sports.common.saved'));
        selected.value = [];
        load(meta.value.current_page || 1);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function openNames(row) {
    namesRow.value = row;
    Object.keys(names).forEach((k) => delete names[k]);
    languages.value.forEach((lang) => { names[lang.code] = row.translations?.find((tr) => tr.locale === lang.code)?.name || ''; });
}

async function saveNames() {
    await save(namesRow.value, { translations: Object.entries(names).map(([locale, name]) => ({ locale, name: name || null })) });
    namesRow.value = null;
}

async function runImport() {
    importing.value = true;
    try {
        const { data } = await adminAxios.post('/api/admin/v1/sports-competitions/import', { sport: importSport.value });
        showSuccess(t('sports.competitions.imported', { created: data.data.created, updated: data.data.updated }));
        load(1);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        importing.value = false;
    }
}

async function load(page = 1) {
    loading.value = true;
    try {
        const params = { page, per_page: 30 };
        Object.entries(filters).forEach(([k, v]) => { if (v) params[k] = v; });
        const { data } = await adminAxios.get('/api/admin/v1/sports-competitions', { params });
        rows.value = data.data ?? [];
        meta.value = data.pagination ?? {};
        counts.value = data.counts ?? {};
        countries.value = data.countries ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    load(1);
    try {
        const { data } = await adminAxios.get('/api/admin/v1/sports-settings');
        sports.value = data.data?.sports ?? [];
    } catch {
        sports.value = [];
    }
    languagesStore.fetch();
});
</script>
