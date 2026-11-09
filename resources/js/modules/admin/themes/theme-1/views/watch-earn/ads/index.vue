<template>
    <div>
        <WalletPageHeader :title="t('watch_earn.ads.title')" :section="t('sidebar.watch_earn')" :total="pagination?.total ?? null" />

        <!-- The numbers: waiting for review first -->
        <div class="row g-3 mb-3">
            <div v-for="tile in tiles" :key="tile.key" class="col-6 col-md-4 col-xl-2">
                <div
                    class="card custom-card mb-0 h-100"
                    :class="{ 'we-tile': tile.status, 'border-primary': tile.status && filters.status === tile.status }"
                    :role="tile.status ? 'button' : undefined"
                    @click="tile.status && (filters.status = tile.status)"
                >
                    <div class="card-body py-3">
                        <div class="fs-12 text-muted">{{ t(`watch_earn.ads.tile_${tile.key}`) }}</div>
                        <div class="fs-20 fw-semibold" :class="tile.class">{{ tileValue(tile) }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-3 py-3">
                <div class="d-flex flex-wrap align-items-center gap-2 catalog-toolbar-filters">
                    <div class="input-group input-group-sm catalog-toolbar-search">
                        <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                        <input v-model="filters.search" type="search" class="form-control" :placeholder="t('watch_earn.ads.search')">
                        <button v-if="filters.search" type="button" class="btn btn-light border catalog-search-clear" :title="t('wallet.common.clear_search')" @click="filters.search = ''">
                            <i class="ri-close-line"></i>
                        </button>
                    </div>
                    <Select
                        v-model="filters.status"
                        :options="statusOptions"
                        option-label="label"
                        option-value="value"
                        :placeholder="t('wallet.common.all_statuses')"
                        show-clear
                        class="wallet-filter-select"
                    />
                    <div class="form-check form-switch ms-2 mb-0">
                        <input id="we-reported" v-model="filters.reported" class="form-check-input" type="checkbox" :true-value="1" :false-value="''">
                        <label class="form-check-label fs-13" for="we-reported">{{ t('watch_earn.ads.reported_only') }}</label>
                    </div>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
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
                            <TableSkeleton v-if="loading" :rows="6" :layout="['avatar-text', 'two-lines', 'badge', 'two-lines', 'text', 'text', 'text']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="7" class="text-center text-muted py-5"><i class="ri-advertisement-line fs-24 d-block mb-1"></i>{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" @click="openAd(row)">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-md bg-light overflow-hidden rounded">
                                                <img v-if="row.media.type === 'image'" :src="row.media.url" alt="" class="w-100 h-100 object-fit-cover">
                                                <i v-else class="ri-play-circle-line fs-20 text-primary"></i>
                                            </span>
                                            <div>
                                                <div class="fw-semibold text-truncate" style="max-width: 220px;">{{ row.title }}</div>
                                                <div class="fs-11 text-muted">{{ t(`watch_earn.media.${row.media.type}`) }} · {{ t('watch_earn.media.seconds', { n: row.media.seconds }) }}</div>
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
                                    <td class="fs-12">{{ formatDate(row.starts_at) }} – {{ formatDate(row.ends_at) }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <!-- One ad, in a modal: preview, audience, money, stats, reports — and the review buttons -->
        <WalletModal :show="!!detail" :title="detail?.ad.title ?? ''" size="xl" smooth @close="detail = null">
            <template v-if="detail">
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge" :class="statusClass(detail.ad.status)">{{ t(`watch_earn.status.${detail.ad.status}`) }}</span>
                    <span class="fs-12 text-muted">#{{ detail.ad.id }}</span>
                </div>
                <div class="row g-4">
                    <div class="col-lg-4">
                        <div class="we-phone">
                            <img v-if="detail.ad.media.type === 'image'" :src="detail.ad.media.url" alt="">
                            <video v-else :src="detail.ad.media.url" controls playsinline></video>
                        </div>
                        <div class="fs-12 text-muted text-center mt-2">
                            {{ t(`watch_earn.media.${detail.ad.media.type}`) }} · {{ t('watch_earn.media.seconds', { n: detail.ad.media.seconds }) }}
                            · {{ t('watch_earn.media.size_mb', { n: sizeMb(detail.ad.media.size) }) }} · {{ t('watch_earn.media.version', { n: detail.ad.media.version }) }}
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
                            <div class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.advertiser') }}:</span> {{ detail.ad.advertiser?.name || '—' }} <span dir="ltr">{{ detail.ad.advertiser?.phone }}</span></div>
                            <div v-if="detail.ad.body" class="mb-2 we-body">{{ detail.ad.body }}</div>
                            <div v-if="detail.ad.link_url" class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.link') }}:</span> <a :href="detail.ad.link_url" target="_blank" rel="noopener noreferrer" dir="ltr">{{ detail.ad.link_url }}</a></div>
                            <div class="mb-2"><span class="text-muted">{{ t('watch_earn.ads.dates') }}:</span> {{ formatDateTime(detail.ad.starts_at) }} – {{ formatDateTime(detail.ad.ends_at) }}</div>
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
                            <div v-if="detail.stats.daily?.length" class="we-bars mb-3">
                                <div v-for="d in detail.stats.daily" :key="d.date" class="we-bar" :title="`${formatDate(d.date)}: ${d.completed}`">
                                    <span :style="{ height: barHeight(d.completed) + '%' }"></span>
                                </div>
                            </div>
                            <div v-else class="text-muted fs-12 mb-3">{{ t('wallet.common.empty') }}</div>
                            <div class="fs-12 text-muted mb-1">{{ t('watch_earn.stats.by_city') }}</div>
                            <div v-for="c in detail.stats.by_city" :key="c.city_id ?? 'x'" class="d-flex justify-content-between border-bottom py-1">
                                <span>{{ c.name || t('watch_earn.stats.unknown_city') }}</span><span class="fw-semibold">{{ c.completed }}</span>
                            </div>
                            <div v-if="!detail.stats.by_city?.length" class="text-muted fs-12">{{ t('wallet.common.empty') }}</div>
                        </div>

                        <div v-if="activeTab === 'reports'">
                            <div v-if="!detail.reports.length" class="text-center text-muted fs-13 py-4">{{ t('wallet.common.empty') }}</div>
                            <div v-else class="table-responsive">
                                <table class="table table-sm align-middle fs-13 mb-0">
                                    <tbody>
                                        <tr v-for="r in detail.reports" :key="r.id">
                                            <td class="text-nowrap text-muted">{{ formatDateTime(r.created_at) }}</td>
                                            <td>{{ r.user?.name || '—' }} <span class="fs-11 text-muted" dir="ltr">{{ r.user?.phone }}</span></td>
                                            <td><span class="badge bg-danger-transparent">{{ t(`watch_earn.report.${r.reason}`) }}</span></td>
                                            <td>{{ r.note }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- The decision: a rejection needs a reason, a suspension may carry one. -->
                <form v-if="canUpdate && needsReason" id="we-ad-review-form" class="border-top mt-3 pt-3" @submit.prevent>
                    <label class="form-label" for="we-review-reason">
                        {{ detail.ad.status === 'pending_review' ? t('watch_earn.ads.reject_reason') : t('watch_earn.ads.suspend_reason') }}
                    </label>
                    <textarea id="we-review-reason" v-model="reason" rows="2" maxlength="500" class="form-control" :class="{ 'is-invalid': reasonError }" @input="reasonError = ''"></textarea>
                    <div v-if="reasonError" class="invalid-feedback d-block">{{ reasonError }}</div>
                </form>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="detail = null">{{ t('close') }}</button>
                <template v-if="detail && canUpdate">
                    <template v-if="detail.ad.status === 'pending_review'">
                        <button type="button" class="btn btn-danger-light" :disabled="busy" @click="reject"><i class="ri-close-line me-1"></i>{{ t('watch_earn.ads.reject') }}</button>
                        <button type="button" class="btn btn-success" :disabled="busy" @click="act('approve')">
                            <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-check-line me-1"></i>{{ t('watch_earn.ads.approve') }}
                        </button>
                    </template>
                    <button v-else-if="detail.ad.status === 'suspended'" type="button" class="btn btn-success" :disabled="busy" @click="act('unsuspend')">
                        <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-play-line me-1"></i>{{ t('watch_earn.ads.unsuspend') }}
                    </button>
                    <button v-else-if="['active', 'paused', 'exhausted'].includes(detail.ad.status)" type="button" class="btn btn-warning" :disabled="busy" @click="act('suspend', { reason: reason.trim() || null })">
                        <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-pause-line me-1"></i>{{ t('watch_earn.ads.suspend') }}
                    </button>
                </template>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import Select from 'primevue/select';
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatDateTime as walletDateTime } from '../../../../../../../utils/walletMoney';
import SplitBar from '../SplitBar.vue';
import { money as fmt } from '../money';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const canUpdate = computed(() => can('watch-earn-ads.update'));
const { rows, loading, pagination, filters, fetch } = useWalletList('watch-earn/ads', { defaults: { search: '', status: '', reported: '' } });
const statuses = ['pending_review', 'active', 'paused', 'exhausted', 'suspended', 'rejected', 'ended', 'stopped'];
const statusOptions = computed(() => statuses.map((s) => ({ value: s, label: t(`watch_earn.status.${s}`) })));
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
const reason = ref('');
const reasonError = ref('');
const busy = ref(false);

const needsReason = computed(() => ['pending_review', 'active', 'paused', 'exhausted'].includes(detail.value?.ad.status));
const money = (minor, currency) => fmt(minor, currency, locale.value);
const percent = (part, whole) => (whole > 0 ? Math.min(100, Math.round((part * 100) / whole)) : 0);
const sizeMb = (bytes) => (bytes ? (bytes / 1048576).toFixed(1) : '—');
const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-US', { year: 'numeric', month: 'short', day: 'numeric' }) : '—');
const formatDateTime = (iso) => (iso ? walletDateTime(iso, locale.value) : '—');
const statusClass = (s) => ({
    pending_review: 'bg-warning-transparent', active: 'bg-success-transparent', paused: 'bg-secondary-transparent', exhausted: 'bg-info-transparent',
    suspended: 'bg-danger-transparent', rejected: 'bg-danger-transparent', ended: 'bg-light text-dark', stopped: 'bg-light text-dark',
}[s] || 'bg-light text-dark');
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
        reason.value = '';
        reasonError.value = '';
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function act(action, body = {}) {
    busy.value = true;
    reasonError.value = '';
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/watch-earn/ads/${detail.value.ad.id}/${action}`, body);
        detail.value.ad = data.data;
        reason.value = '';
        showSuccess(t(`watch_earn.ads.done_${action}`));
        fetch(pagination.value?.current_page ?? 1);
        loadCounts();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        if (bag.reason?.[0]) {
            reasonError.value = bag.reason[0];
        } else {
            showError(extractApiErrorMessage(error));
        }
    } finally {
        busy.value = false;
    }
}

/** A rejection is sent to the advertiser, so it needs a real reason. */
function reject() {
    if (reason.value.trim().length < 3) {
        reasonError.value = t('watch_earn.ads.reason_required');
        return;
    }
    act('reject', { reason: reason.value.trim() });
}

onMounted(() => {
    fetch(1);
    loadCounts();
});
</script>

<style scoped>
.we-tile { transition: transform .15s ease, box-shadow .15s ease; }
.we-tile:hover { transform: translateY(-2px); box-shadow: 0 6px 18px rgba(0, 0, 0, .08); }
.we-phone { aspect-ratio: 9 / 16; max-height: 460px; margin: 0 auto; border-radius: 18px; overflow: hidden; background: #111; display: flex; align-items: center; justify-content: center; box-shadow: 0 10px 30px rgba(0, 0, 0, .25); }
.we-phone img, .we-phone video { width: 100%; height: 100%; object-fit: contain; }
.we-body { white-space: pre-wrap; }
.we-bars { display: flex; align-items: flex-end; gap: 3px; height: 90px; }
.we-bar { flex: 1; height: 100%; display: flex; align-items: flex-end; }
.we-bar span { display: block; width: 100%; border-radius: 3px 3px 0 0; background: var(--primary-color); animation: we-grow .5s ease-out; }
@keyframes we-grow { from { transform: scaleY(0); transform-origin: bottom; } to { transform: scaleY(1); } }
</style>
