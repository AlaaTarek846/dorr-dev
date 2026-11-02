<template>
    <div>
        <WalletPageHeader :title="t('watch_earn.settings.title')" :section="t('sidebar.watch_earn')" />

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
        <div v-else class="row g-3">
            <!-- Countries -->
            <div class="col-lg-3">
                <div class="card custom-card">
                    <div class="list-group list-group-flush">
                        <button v-for="row in rows" :key="row.country.id" type="button" class="list-group-item list-group-item-action d-flex align-items-center justify-content-between"
                            :class="{ active: selected?.country.id === row.country.id }" @click="select(row)">
                            <span>{{ row.country.name || row.country.code }}</span>
                            <span class="badge" :class="row.enabled ? 'bg-success' : 'bg-light text-muted'">{{ row.enabled ? t('watch_earn.settings.on') : t('watch_earn.settings.off') }}</span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- The country's settings -->
            <div v-if="form" class="col-lg-9">
                <form class="card custom-card" @submit.prevent="save">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <div class="card-title mb-0">{{ selected.country.name }} <span class="text-muted fs-12">({{ selected.currency.code }})</span></div>
                        <div class="form-check form-switch mb-0">
                            <input id="we-enabled" v-model="form.enabled" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="we-enabled">{{ t('watch_earn.settings.enabled') }}</label>
                        </div>
                    </div>
                    <div class="card-body">
                        <h6 class="fw-semibold mb-3"><i class="ri-price-tag-3-line me-1 text-primary"></i>{{ t('watch_earn.settings.pricing') }}</h6>
                        <div class="row g-3 mb-2">
                            <div v-for="key in moneyKeys" :key="key" class="col-md-3">
                                <label class="form-label fs-12">{{ t(`watch_earn.settings.${key}`) }}</label>
                                <div class="input-group input-group-sm">
                                    <input v-model="form[key]" type="number" min="0" :step="step" class="form-control">
                                    <span class="input-group-text">{{ selected.currency.code }}</span>
                                </div>
                            </div>
                        </div>
                        <SplitBar class="mb-2" :split="splitMinor" :price="priceMinor" :currency="selected.currency" />
                        <div v-if="!splitOk" class="alert alert-danger py-2 fs-12">{{ t('watch_earn.settings.split_mismatch') }}</div>

                        <div class="row g-3 mb-4 mt-1">
                            <div class="col-md-6">
                                <label class="form-label fs-12">{{ t('watch_earn.settings.unclaimed_interaction') }}</label>
                                <select v-model="form.unclaimed_interaction" class="form-select form-select-sm">
                                    <option value="platform">{{ t('watch_earn.settings.unclaimed_platform') }}</option>
                                    <option value="not_charged">{{ t('watch_earn.settings.unclaimed_not_charged') }}</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label fs-12">{{ t('watch_earn.settings.min_ad_wallet') }}</label>
                                <div class="input-group input-group-sm">
                                    <input v-model="form.min_ad_wallet" type="number" min="0" :step="step" class="form-control">
                                    <span class="input-group-text">{{ selected.currency.code }}</span>
                                </div>
                            </div>
                        </div>

                        <h6 class="fw-semibold mb-3"><i class="ri-play-circle-line me-1 text-primary"></i>{{ t('watch_earn.settings.player') }}</h6>
                        <div class="row g-3 mb-4">
                            <div class="col-md-6">
                                <label class="form-label fs-12 d-block">{{ t('watch_earn.settings.viewing_mode') }}</label>
                                <div class="btn-group btn-group-sm w-100">
                                    <button v-for="mode in ['auto', 'manual']" :key="mode" type="button" class="btn" :class="form.viewing_mode === mode ? 'btn-primary' : 'btn-outline-primary'" @click="form.viewing_mode = mode">
                                        {{ t(`watch_earn.settings.mode_${mode}`) }}
                                    </button>
                                </div>
                                <div class="fs-11 text-muted mt-1">{{ t(`watch_earn.settings.mode_${form.viewing_mode}_hint`) }}</div>
                            </div>
                            <div v-for="key in playerKeys" :key="key" class="col-md-3 col-6">
                                <label class="form-label fs-12">{{ t(`watch_earn.settings.${key}`) }}</label>
                                <input v-model.number="form[key]" type="number" min="0" class="form-control form-control-sm">
                            </div>
                        </div>

                        <h6 class="fw-semibold mb-3"><i class="ri-shield-check-line me-1 text-primary"></i>{{ t('watch_earn.settings.rules') }}</h6>
                        <div class="row g-3">
                            <div v-for="key in ruleKeys" :key="key" class="col-md-4 col-6">
                                <label class="form-label fs-12">{{ t(`watch_earn.settings.${key}`) }}</label>
                                <input v-model.number="form[key]" type="number" min="0" class="form-control form-control-sm">
                            </div>
                        </div>
                    </div>
                    <div v-if="canUpdate" class="card-footer text-end">
                        <button type="submit" class="btn btn-primary btn-sm" :disabled="saving || !splitOk">
                            <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import SplitBar from '../SplitBar.vue';
import { fromMinor, toMinor } from '../money';

const { t } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const canUpdate = computed(() => can('watch-earn-settings.update'));
const moneyKeys = ['price_per_view', 'platform_share', 'view_reward', 'interaction_reward'];
const playerKeys = ['image_seconds', 'video_max_seconds', 'video_max_mb', 'interaction_window_minutes'];
const ruleKeys = ['low_balance_percent', 'report_threshold', 'view_reward_wait_hours', 'interaction_reward_wait_hours'];

const rows = ref([]);
const selected = ref(null);
const form = ref(null);
const loading = ref(true);
const saving = ref(false);

const decimals = computed(() => selected.value?.currency.decimals ?? 2);
const step = computed(() => (decimals.value > 0 ? 1 / 10 ** decimals.value : 1));
const priceMinor = computed(() => toMinor(form.value?.price_per_view, decimals.value));
const splitMinor = computed(() => ({
    platform_share_minor: toMinor(form.value?.platform_share, decimals.value),
    view_reward_minor: toMinor(form.value?.view_reward, decimals.value),
    interaction_reward_minor: toMinor(form.value?.interaction_reward, decimals.value),
}));
const splitOk = computed(() => {
    const s = splitMinor.value;
    return s.platform_share_minor + s.view_reward_minor + s.interaction_reward_minor === priceMinor.value;
});

function select(row) {
    selected.value = row;
    const d = row.currency.decimals ?? 2;
    form.value = {
        ...row,
        price_per_view: fromMinor(row.price_per_view_minor, d),
        platform_share: fromMinor(row.platform_share_minor, d),
        view_reward: fromMinor(row.view_reward_minor, d),
        interaction_reward: fromMinor(row.interaction_reward_minor, d),
        min_ad_wallet: fromMinor(row.min_ad_wallet_minor, d),
    };
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/watch-earn/settings');
        rows.value = data.data ?? [];
        if (rows.value.length) select(rows.value.find((r) => r.country.id === selected.value?.country.id) ?? rows.value[0]);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    const f = form.value;
    const d = decimals.value;
    try {
        await adminAxios.put(`/api/admin/v1/watch-earn/settings/${selected.value.country.id}`, {
            enabled: !!f.enabled,
            price_per_view_minor: toMinor(f.price_per_view, d),
            platform_share_minor: toMinor(f.platform_share, d),
            view_reward_minor: toMinor(f.view_reward, d),
            interaction_reward_minor: toMinor(f.interaction_reward, d),
            unclaimed_interaction: f.unclaimed_interaction,
            viewing_mode: f.viewing_mode,
            image_seconds: f.image_seconds,
            video_max_seconds: f.video_max_seconds,
            video_max_mb: f.video_max_mb,
            min_ad_wallet_minor: toMinor(f.min_ad_wallet, d),
            low_balance_percent: f.low_balance_percent,
            report_threshold: f.report_threshold,
            interaction_window_minutes: f.interaction_window_minutes,
            view_reward_wait_hours: f.view_reward_wait_hours,
            interaction_reward_wait_hours: f.interaction_reward_wait_hours,
        });
        showSuccess(t('watch_earn.settings.saved'));
        await load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
