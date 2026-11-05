<template>
    <div>
        <WalletPageHeader :title="t('watch_earn.ads.title')" :section="t('sidebar.watch_earn')" :total="pagination?.total ?? null" />

        <!-- The numbers: waiting for review first -->
        <div class="row g-3 mb-3">
            <div v-for="tile in tiles" :key="tile.key" class="col-6 col-md-4 col-xl-2">
                <div class="card custom-card mb-0 h-100 we-tile" role="button" @click="tile.status !== undefined && (filters.status = tile.status)">
                    <div class="card-body py-3">
                        <div class="fs-12 text-muted">{{ t(`watch_earn.ads.tile_${tile.key}`) }}</div>
                        <div class="fs-20 fw-semibold" :class="tile.class">{{ tileValue(tile) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('watch_earn.ads.search')">
                </div>
                <select v-model="filters.status" class="form-select form-select-sm" style="max-width: 190px;">
                    <option value="">{{ t('watch_earn.common.all') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`watch_earn.status.${s}`) }}</option>
                </select>
                <div class="form-check form-switch ms-2 mb-0">
                    <input id="we-reported" v-model="filters.reported" class="form-check-input" type="checkbox" :true-value="1" :false-value="''">
                    <label class="form-check-label fs-13" for="we-reported">{{ t('watch_earn.ads.reported_only') }}</label>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('watch_earn.ads.ad') }}</th>
                                <th>{{ t('watch_earn.ads.advertiser') }}</th>
                                <th>{{ t('watch_earn.ads.status') }}</th>
                                <th>{{ t('watch_earn.ads.budget') }}</th>
                                <th>{{ t('watch_earn.ads.views') }}</th>
                                <th>{{ t('watch_earn.ads.reports') }}</th>
                                <th>{{ t('watch_earn.ads.dates') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="7" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" class="we-row" @click="openAd(row)">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-md bg-light overflow-hidden rounded">
                                                <img v-if="row.media.type === 'image'" :src="row.media.url" alt="" class="w-100 h-100 object-fit-cover">
                                                <i v-else class="ri-play-circle-line fs-20 text-primary"></i>
                                            </span>
                                            <div>
                                                <div class="fw-semibold text-truncate" style="max-width: 220px;">{{ row.title }}</div>
                                                <div class="fs-11 text-muted">{{ t(`watch_earn.media.${row.media.type}`) }} · {{ row.media.seconds }}s</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <div>{{ row.advertiser?.name || '—' }}</div>
                                        <div class="fs-12 text-muted" dir="ltr">{{ row.advertiser?.phone }}</div>
                                    </td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`watch_earn.status.${row.status}`) }}</span></td>
                                    <td>
                                        <div>{{ money(row.budget.spent_minor, row.currency) }} / {{ money(row.budget.total_minor, row.currency) }}</div>
                                        <div class="progress progress-xs mt-1" style="width: 120px;">
                                            <div class="progress-bar bg-primary" :style="{ width: percent(row.budget.spent_minor, row.budget.total_minor) + '%' }"></div>
                                        </div>
                                    </td>
                                    <td>{{ row.counts.completed }}</td>
                                    <td><span :class="row.counts.reports ? 'text-danger fw-semibold' : 'text-muted'">{{ row.counts.reports }}</span></td>
                                    <td class="fs-12">{{ formatDate(row.starts_at) }} → {{ formatDate(row.ends_at) }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <!-- One ad, in a modal: preview, audience, money, stats, reports — and the review buttons -->
        <transition name="we-fade">
            <div v-if="detail" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.45);" @click.self="detail = null">
                <div class="modal-dialog modal-xl modal-dialog-scrollable we-pop">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h6 class="modal-title">
                                {{ detail.ad.title }}
                                <span class="badge ms-2" :class="statusClass(detail.ad.status)">{{ t(`watch_earn.status.${detail.ad.status}`) }}</span>
                            </h6>
                            <button type="button" class="btn-close" @click="detail = null"></button>
                        </div>
                        <div class="modal-body">
                            <div class="row g-4">
                                <div class="col-lg-4">
                                    <div class="we-phone">
                                        <img v-if="detail.ad.media.type === 'image'" :src="detail.ad.media.url" alt="">
                                        <video v-else :src="detail.ad.media.url" controls playsinline></video>
                                    </div>
                                    <div class="fs-12 text-muted text-center mt-2">
                                        {{ t(`watch_earn.media.${detail.ad.media.type}`) }} · {{ detail.ad.media.seconds }}s · {{ sizeMb(detail.ad.media.size) }} MB · v{{ detail.ad.media.version }}
                                    </div>
                                </div>
                                <div class="col-lg-8">
                                    <ul class="nav nav-tabs mb-3">
                                        <li v-for="tab in tabs" :key="tab" class="nav-item">
                                            <a class="nav-link" :class="{ active: activeTab === tab }" href="#" @click.prevent="activeTab = tab">
                                                {{ t(`watch_earn.ads.tab_${tab}`) }}
                                                <span v-if="tab === 'reports' && detail.reports.length" class="badge bg-danger ms-1">{{ detail.ad.counts.reports }}</span>
                                            </a>
                                        </li>
                                    </ul>

                                    <div v-if="activeTab === 'info'" class="fs-13">
                                        <div class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.advertiser') }}:</span> {{ detail.ad.advertiser?.name }} <span dir="ltr">{{ detail.ad.advertiser?.phone }}</span></div>
                                        <div v-if="detail.ad.body" class="mb-2 we-body">{{ detail.ad.body }}</div>
                                        <div v-if="detail.ad.link_url" class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.link') }}:</span> <a :href="detail.ad.link_url" target="_blank" rel="noopener noreferrer" dir="ltr">{{ detail.ad.link_url }}</a></div>
                                        <div class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.dates') }}:</span> {{ formatDateTime(detail.ad.starts_at) }} → {{ formatDateTime(detail.ad.ends_at) }}</div>
                                        <div class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.submitted') }}:</span> {{ formatDateTime(detail.ad.submitted_at) }}</div>
                                        <div v-if="detail.ad.reject_reason" class="alert alert-warning py-2 fs-12 mb-0">{{ detail.ad.reject_reason }}</div>
                                    </div>

                                    <div v-if="activeTab === 'audience'" class="fs-13">
                                        <div class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.cities') }}:</span>
                                            <template v-if="detail.ad.audience.cities?.length">
                                                <span v-for="c in cityNames" :key="c" class="badge bg-primary-transparent me-1">{{ c }}</span>
                                            </template>
                                            <span v-else>{{ t('watch_earn.ads.whole_country') }}</span>
                                        </div>
                                        <div class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.gender') }}:</span> {{ detail.ad.audience.gender ? t(`watch_earn.gender.${detail.ad.audience.gender}`) : t('watch_earn.common.all') }}</div>
                                        <div><span class="text-muted">{{ t('watch_earn.ads.language') }}:</span> {{ detail.ad.audience.locale ? detail.ad.audience.locale.toUpperCase() : t('watch_earn.common.all') }}</div>
                                    </div>

                                    <div v-if="activeTab === 'money'" class="fs-13">
                                        <div class="row g-2 mb-3">
                                            <div v-for="m in moneyTiles" :key="m.key" class="col-6 col-md-4">
                                                <div class="border rounded p-2 h-100">
                                                    <div class="fs-11 text-muted">{{ t(`watch_earn.ads.money_${m.key}`) }}</div>
                                                    <div class="fw-semibold">{{ m.value }}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="fs-12 text-muted mb-1">{{ t('watch_earn.ads.split') }}</div>
                                        <SplitBar :split="detail.ad.split" :price="detail.ad.budget.price_per_view_minor" :currency="detail.ad.currency" />
                                    </div>

                                    <div v-if="activeTab === 'stats'" class="fs-13">
                                        <div class="row g-2 mb-3">
                                            <div v-for="(v, k) in statTiles" :key="k" class="col-6 col-md-3">
                                                <div class="border rounded p-2 h-100">
                                                    <div class="fs-11 text-muted">{{ t(`watch_earn.stats.${k}`) }}</div>
                                                    <div class="fw-semibold">{{ v }}</div>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="fs-12 text-muted mb-1">{{ t('watch_earn.stats.daily') }}</div>
                                        <div class="we-bars mb-3">
                                            <div v-for="d in detail.stats.daily" :key="d.date" class="we-bar" :title="`${d.date}: ${d.completed}`">
                                                <span :style="{ height: barHeight(d.completed) + '%' }"></span>
                                            </div>
                                        </div>
                                        <div class="fs-12 text-muted mb-1">{{ t('watch_earn.stats.by_city') }}</div>
                                        <div v-for="c in detail.stats.by_city" :key="c.city_id ?? 'x'" class="d-flex justify-content-between border-bottom py-1">
                                            <span>{{ c.name || t('watch_earn.stats.unknown_city') }}</span><span class="fw-semibold">{{ c.completed }}</span>
                                        </div>
                                    </div>

                                    <div v-if="activeTab === 'reports'">
                                        <table class="table table-sm fs-13">
                                            <tbody>
                                                <tr v-for="r in detail.reports" :key="r.id">
                                                    <td class="text-nowrap text-muted">{{ formatDateTime(r.created_at) }}</td>
                                                    <td>{{ r.user?.name }} <span class="fs-11 text-muted" dir="ltr">{{ r.user?.phone }}</span></td>
                                                    <td><span class="badge bg-danger-transparent">{{ t(`watch_earn.report.${r.reason}`) }}</span></td>
                                                    <td>{{ r.note }}</td>
                                                </tr>
                                                <tr v-if="!detail.reports.length"><td class="text-muted">—</td></tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div v-if="canUpdate" class="modal-footer flex-wrap gap-2">
                            <template v-if="detail.ad.status === 'pending_review'">
                                <input v-model="rejectReason" class="form-control form-control-sm me-auto" style="max-width: 380px;" :placeholder="t('watch_earn.ads.reject_reason')">
                                <button type="button" class="btn btn-sm btn-outline-danger" :disabled="busy || rejectReason.trim().length < 3" @click="act('reject', { reason: rejectReason })"><i class="ri-close-line me-1"></i>{{ t('watch_earn.ads.reject') }}</button>
                                <button type="button" class="btn btn-sm btn-success" :disabled="busy" @click="act('approve')"><i class="ri-check-line me-1"></i>{{ t('watch_earn.ads.approve') }}</button>
                            </template>
                            <button v-else-if="detail.ad.status === 'suspended'" type="button" class="btn btn-sm btn-success" :disabled="busy" @click="act('unsuspend')"><i class="ri-play-line me-1"></i>{{ t('watch_earn.ads.unsuspend') }}</button>
                            <button v-else-if="['active', 'paused', 'exhausted'].includes(detail.ad.status)" type="button" class="btn btn-sm btn-warning" :disabled="busy" @click="suspend"><i class="ri-pause-line me-1"></i>{{ t('watch_earn.ads.suspend') }}</button>
                        </div>
                    </div>
                </div>
            </div>
        </transition>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import SplitBar from '../SplitBar.vue';
import { money as fmt } from '../money';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const canUpdate = computed(() => can('watch-earn-ads.update'));
const { rows, loading, pagination, filters, fetch } = useWalletList('watch-earn/ads', { defaults: { search: '', status: '', reported: '' } });
const statuses = ['pending_review', 'active', 'paused', 'exhausted', 'suspended', 'rejected', 'ended', 'stopped'];
const tiles = [
    { key: 'pending_review', status: 'pending_review', class: 'text-warning' },
    { key: 'active', status: 'active', class: 'text-success' },
    { key: 'paused', status: 'paused' },
    { key: 'exhausted', status: 'exhausted' },
    { key: 'suspended', status: 'suspended', class: 'text-danger' },
    { key: 'reported', class: 'text-danger' },
];
const counts = ref({ by_status: {}, reported: 0 });
const detail = ref(null);
const tabs = ['info', 'audience', 'money', 'stats', 'reports'];
const activeTab = ref('info');
const rejectReason = ref('');
const busy = ref(false);

const money = (minor, currency) => fmt(minor, currency, locale.value);
const percent = (part, whole) => (whole > 0 ? Math.min(100, Math.round((part * 100) / whole)) : 0);
const sizeMb = (bytes) => (bytes ? (bytes / 1048576).toFixed(1) : '—');
const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value) : '—');
const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString(locale.value) : '—');
const statusClass = (s) => ({
    pending_review: 'bg-warning-transparent', active: 'bg-success-transparent', paused: 'bg-secondary-transparent', exhausted: 'bg-info-transparent',
    suspended: 'bg-danger-transparent', rejected: 'bg-danger-transparent', ended: 'bg-light text-dark', stopped: 'bg-light text-dark',
}[s] || 'bg-light');
const tileValue = (tile) => (tile.key === 'reported' ? counts.value.reported : counts.value.by_status?.[tile.key] ?? 0);

const cityNames = computed(() => {
    const ids = detail.value?.ad.audience.cities ?? [];
    const named = new Map((detail.value?.stats.by_city ?? []).map((c) => [c.city_id, c.name]));
    return ids.map((id) => named.get(id) || `#${id}`);
});
const moneyTiles = computed(() => {
    const b = detail.value.ad.budget;
    const c = detail.value.ad.currency;
    return [
        { key: 'budget', value: money(b.total_minor, c) },
        { key: 'spent', value: money(b.spent_minor, c) },
        { key: 'remaining', value: money(b.remaining_minor, c) },
        { key: 'price', value: money(b.price_per_view_minor, c) },
        { key: 'views_left', value: b.views_left },
    ];
});
const statTiles = computed(() => {
    const s = detail.value.stats.totals;
    return {
        impressions: s.impressions, completed: s.completed, completion_rate: `${s.completion_rate}%`, interactions: s.interactions,
        interaction_rate: `${s.interaction_rate}%`, comments: s.comments, reactions: s.reactions, reports: s.reports,
    };
});
const barHeight = (n) => {
    const max = Math.max(1, ...(detail.value?.stats.daily ?? []).map((d) => d.completed));
    return Math.max(4, Math.round((n * 100) / max));
};

async function loadCounts() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/watch-earn/ads/counts');
        counts.value = data.data ?? { by_status: {}, reported: 0 };
    } catch {
        counts.value = { by_status: {}, reported: 0 };
    }
}

async function openAd(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/watch-earn/ads/${row.id}`);
        detail.value = data.data;
        activeTab.value = 'info';
        rejectReason.value = '';
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function act(action, body = {}) {
    busy.value = true;
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/watch-earn/ads/${detail.value.ad.id}/${action}`, body);
        detail.value.ad = data.data;
        showSuccess(t(`watch_earn.ads.done_${action}`));
        fetch(pagination.value?.current_page ?? 1);
        loadCounts();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = false;
    }
}

function suspend() {
    const reason = window.prompt(t('watch_earn.ads.suspend_reason'));
    if (reason === null) return;
    act('suspend', { reason: reason.trim() || null });
}

onMounted(() => {
    fetch(1);
    loadCounts();
});
</script>

<style scoped>
.we-tile { transition: transform .15s ease, box-shadow .15s ease; }
.we-tile:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0, 0, 0, .08); }
.we-row { transition: background-color .15s ease; }
.we-phone { aspect-ratio: 9 / 16; max-height: 460px; margin: 0 auto; border-radius: 18px; overflow: hidden; background: #111; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 30px rgba(0, 0, 0, .25); }
.we-phone img, .we-phone video { width: 100%; height: 100%; object-fit: contain; }
.we-body { white-space: pre-wrap; }
.we-bars { display: flex; align-items: flex-end; gap: 3px; height: 90px; }
.we-bar { flex: 1; height: 100%; display: flex; align-items: flex-end; }
.we-bar span { display: block; width: 100%; border-radius: 3px 3px 0 0; background: var(--primary-color, #845adf); animation: we-grow .5s ease-out; }
.we-fade-enter-active, .we-fade-leave-active { transition: opacity .2s ease; }
.we-fade-enter-from, .we-fade-leave-to { opacity: 0; }
.we-pop { animation: we-pop .22s ease-out; }
@keyframes we-pop { from { transform: translateY(12px) scale(.98); opacity: .6; } to { transform: none; opacity: 1; } }
@keyframes we-grow { from { transform: scaleY(0); transform-origin: bottom; } to { transform: scaleY(1); } }
</style>
