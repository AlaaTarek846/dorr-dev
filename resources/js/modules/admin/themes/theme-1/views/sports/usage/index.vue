<template>
    <div>
        <WalletPageHeader :title="t('sports.usage.title')" :section="t('sidebar.sports')" />

        <div v-if="loading && !data" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
        <template v-else-if="data">
            <div v-if="!data.configured" class="alert alert-warning fs-13">{{ t('sports.settings.not_configured') }}</div>
            <div class="row g-3 mb-3">
                <div v-for="k in kpis" :key="k.key" class="col-6 col-xl">
                    <div class="card custom-card mb-0 h-100">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <span class="avatar avatar-sm avatar-rounded" :class="`bg-${k.color}-transparent`"><i :class="k.icon"></i></span>
                                <span class="text-muted fs-12">{{ t(`sports.usage.${k.key}`) }}</span>
                            </div>
                            <div class="fs-22 fw-bold" dir="ltr">{{ k.value.toLocaleString() }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card custom-card">
                <div class="card-body">
                    <div class="d-flex justify-content-between fs-12 mb-1"><span>{{ t('sports.usage.today_bar') }}</span><span dir="ltr">{{ pct }}%</span></div>
                    <div class="progress progress-lg" style="height: 14px;">
                        <div class="progress-bar bg-primary" :style="{ width: `${usedPct}%` }"></div>
                        <div class="progress-bar bg-info opacity-50" :style="{ width: `${plannedPct}%` }"></div>
                        <div class="progress-bar bg-warning" :style="{ width: `${reservePct}%`, marginInlineStart: 'auto' }"></div>
                    </div>
                    <div class="d-flex gap-3 fs-11 text-muted mt-1">
                        <span><i class="ri-checkbox-blank-circle-fill text-primary"></i> {{ t('sports.usage.used') }}</span>
                        <span><i class="ri-checkbox-blank-circle-fill text-info"></i> {{ t('sports.usage.planned') }}</span>
                        <span><i class="ri-checkbox-blank-circle-fill text-warning"></i> {{ t('sports.usage.reserve') }}</span>
                    </div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-xl-8">
                    <div class="card custom-card h-100">
                        <div class="card-header d-flex align-items-center py-3">
                            <div class="card-title mb-0">{{ t('sports.usage.by_sport') }}</div>
                            <div v-if="canSync" class="ms-auto btn-list">
                                <button v-for="w in ['schedule', 'standings', 'tick']" :key="w" type="button" class="btn btn-sm btn-light" :disabled="syncing" @click="sync(w)">
                                    <i class="ri-refresh-line me-1"></i>{{ t(`sports.usage.sync_${w}`) }}
                                </button>
                            </div>
                        </div>
                        <div class="card-body p-0">
                            <table class="table mb-0 text-nowrap">
                                <thead><tr><th class="ps-3">{{ t('sports.usage.sport') }}</th><th>{{ t('sports.usage.used') }}</th><th>{{ t('sports.usage.planned') }}</th><th>{{ t('sports.usage.factor') }}</th><th>{{ t('sports.usage.intervals') }}</th><th>{{ t('sports.usage.live_now') }}</th><th>{{ t('sports.usage.today_matches') }}</th></tr></thead>
                                <tbody>
                                    <tr v-for="s in data.by_sport" :key="s.key" :class="{ 'opacity-50': !s.status }">
                                        <td class="ps-3"><span class="fs-16 me-1">{{ s.emoji }}</span>{{ s.name }}</td>
                                        <td dir="ltr">{{ s.used }}</td>
                                        <td dir="ltr">{{ s.planned }}</td>
                                        <td><span class="badge" :class="s.factor > 1 ? 'bg-warning-transparent' : 'bg-success-transparent'" dir="ltr">×{{ s.factor }}</span></td>
                                        <td class="fs-12" dir="ltr"><template v-if="s.intervals">{{ fmt(s.intervals.big) }} · {{ fmt(s.intervals.normal) }} · {{ fmt(s.intervals.minor) }}</template><span v-else>—</span></td>
                                        <td><span v-if="s.live_now" class="badge bg-danger"><span class="live-dot"></span>{{ s.live_now }}</span><span v-else class="text-muted">0</span></td>
                                        <td>{{ s.today }}</td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="text-muted fs-11 px-3 py-2">{{ t('sports.usage.hint_factor') }}</div>
                        </div>
                    </div>
                </div>
                <div class="col-xl-4">
                    <div class="card custom-card h-100">
                        <div class="card-header py-3"><div class="card-title mb-0">{{ t('sports.usage.last_days') }}</div></div>
                        <div class="card-body">
                            <div class="d-flex align-items-end gap-1" style="height: 120px;">
                                <div v-for="d in data.last_days" :key="d.date" class="flex-fill bg-primary rounded-top" :title="`${d.date}: ${d.requests}`" :style="{ height: `${Math.max(3, (d.requests / maxDay) * 100)}%`, opacity: 0.35 + 0.65 * (d.requests / maxDay) }"></div>
                            </div>
                            <div class="mt-3">
                                <div v-for="e in data.by_endpoint.slice(0, 8)" :key="e.sport_key + e.endpoint" class="d-flex justify-content-between fs-12 py-1 border-bottom">
                                    <span dir="ltr">{{ e.sport_key }} / {{ e.endpoint }}</span><span class="fw-semibold">{{ e.requests }}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const canSync = computed(() => can('sports-usage.update'));
const data = ref(null);
const loading = ref(false);
const syncing = ref(false);
let timer = null;

const kpis = computed(() => data.value ? [
    { key: 'limit', value: data.value.limit, icon: 'ri-database-2-line', color: 'primary' },
    { key: 'used', value: data.value.used, icon: 'ri-send-plane-line', color: 'info' },
    { key: 'left', value: data.value.left, icon: 'ri-battery-2-charge-line', color: 'success' },
    { key: 'planned', value: data.value.planned_rest_of_day, icon: 'ri-calendar-schedule-line', color: 'secondary' },
    { key: 'reserve', value: data.value.reserve, icon: 'ri-shield-line', color: 'warning' },
] : []);
const limit = computed(() => Math.max(1, data.value?.limit || 1));
const usedPct = computed(() => Math.min(100, (data.value.used / limit.value) * 100));
const plannedPct = computed(() => Math.min(100 - usedPct.value, (data.value.planned_rest_of_day / limit.value) * 100));
const reservePct = computed(() => Math.min(100, (data.value.reserve / limit.value) * 100));
const pct = computed(() => Math.round(usedPct.value));
const maxDay = computed(() => Math.max(1, ...(data.value?.last_days ?? []).map((d) => Number(d.requests))));

function fmt(sec) {
    return sec >= 60 ? `${Math.round(sec / 6) / 10}m` : `${sec}s`;
}

async function load() {
    loading.value = true;
    try {
        const { data: res } = await adminAxios.get('/api/admin/v1/sports-usage');
        data.value = res.data;
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

async function sync(what) {
    syncing.value = true;
    try {
        await adminAxios.post('/api/admin/v1/sports-usage/sync', { what });
        showSuccess(t('sports.common.saved'));
        load();
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        syncing.value = false;
    }
}

onMounted(() => {
    load();
    timer = setInterval(load, 60000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.live-dot { display: inline-block; width: 6px; height: 6px; border-radius: 50%; background: #fff; margin-inline-end: 4px; animation: pulse 1.2s infinite; }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.2; } }
</style>
