<template>
    <div>
        <WalletPageHeader :title="t('sports.settings.title')" :section="t('sidebar.sports')" />
        <div v-if="!loading && !form.configured" class="alert alert-warning fs-13">{{ t('sports.settings.not_configured') }}</div>
        <div class="alert alert-info fs-13">{{ t('sports.settings.intro') }}</div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>
        <form v-else @submit.prevent="save">
            <div class="row g-3">
                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-primary-transparent"><i class="ri-football-line"></i></span>
                            <div class="card-title mb-0">{{ t('sports.settings.section_general') }}</div>
                            <div class="form-check form-switch ms-auto mb-0">
                                <input id="sp-on" v-model="form.enabled" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                <label class="form-check-label" for="sp-on">{{ t('sports.settings.enabled') }}</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <label class="form-label fs-13">{{ t('sports.settings.countries') }}</label>
                            <MultiSelect v-model="form.enabled_countries" :options="countries" option-label="name" option-value="id" filter display="chip" class="w-100" :placeholder="t('sports.settings.everywhere')" :disabled="!canUpdate" />
                            <div class="text-muted fs-11 mt-1">{{ t('sports.settings.hint_countries') }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-danger-transparent"><i class="ri-timer-flash-line"></i></span>
                            <div class="card-title mb-0">{{ t('sports.settings.section_live') }}</div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div v-for="tier in ['big', 'normal', 'minor']" :key="tier" class="col-4">
                                    <label class="form-label fs-12">{{ t(`sports.tiers.${tier}`) }}</label>
                                    <div class="input-group input-group-sm">
                                        <input v-model.number="form.tier_seconds[tier]" type="number" min="30" max="3600" dir="ltr" class="form-control" :disabled="!canUpdate">
                                        <span class="input-group-text">{{ t('sports.settings.sec') }}</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-muted fs-11 mt-2">{{ t('sports.settings.hint_tiers') }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-warning-transparent"><i class="ri-scales-3-line"></i></span>
                            <div class="card-title mb-0">{{ t('sports.settings.section_budget') }}</div>
                        </div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label fs-12">{{ t('sports.settings.daily_limit') }}</label>
                                    <input v-model.number="form.daily_limit" type="number" min="10" dir="ltr" class="form-control form-control-sm" :placeholder="t('sports.settings.from_provider')" :disabled="!canUpdate">
                                </div>
                                <div class="col-6">
                                    <label class="form-label fs-12">{{ t('sports.settings.reserve') }}</label>
                                    <div class="input-group input-group-sm">
                                        <input v-model.number="form.reserve_percent" type="number" min="0" max="50" dir="ltr" class="form-control" :disabled="!canUpdate">
                                        <span class="input-group-text">%</span>
                                    </div>
                                </div>
                            </div>
                            <div class="text-muted fs-11 mt-2">{{ t('sports.settings.hint_budget') }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-info-transparent"><i class="ri-medal-line"></i></span>
                            <div class="card-title mb-0">{{ t('sports.settings.section_predictions') }}</div>
                            <div class="form-check form-switch ms-auto mb-0">
                                <input id="sp-pred" v-model="form.predictions_enabled" class="form-check-input" type="checkbox" :disabled="!canUpdate">
                                <label class="form-check-label" for="sp-pred">{{ t('sports.settings.enabled') }}</label>
                            </div>
                        </div>
                        <div class="card-body">
                            <label class="form-label fs-13">{{ t('sports.settings.prizes_countries') }}</label>
                            <MultiSelect v-model="form.prizes_countries" :options="countries" option-label="name" option-value="id" filter display="chip" class="w-100" :placeholder="t('sports.settings.prizes_nowhere')" :disabled="!canUpdate" />
                            <div class="text-muted fs-11 mt-1">{{ t('sports.settings.hint_prizes') }}</div>
                            <label class="form-label fs-13 mt-3">{{ t('sports.settings.odds_countries') }}</label>
                            <MultiSelect v-model="form.odds_countries" :options="countries" option-label="name" option-value="id" filter display="chip" class="w-100" :placeholder="t('sports.settings.prizes_nowhere')" :disabled="!canUpdate" />
                            <div class="text-muted fs-11 mt-1">{{ t('sports.settings.hint_odds') }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-xl-6">
                    <div class="card custom-card h-100 mb-0">
                        <div class="card-header py-3 d-flex align-items-center gap-2">
                            <span class="avatar avatar-sm avatar-rounded bg-success-transparent"><i class="ri-basketball-line"></i></span>
                            <div class="card-title mb-0">{{ t('sports.settings.section_sports') }}</div>
                        </div>
                        <div class="card-body p-0">
                            <table class="table mb-0">
                                <tbody>
                                    <tr v-for="s in form.sports" :key="s.key">
                                        <td class="ps-3"><span class="fs-16 me-1">{{ s.emoji }}</span>{{ s.name }} <span class="text-muted fs-11">· {{ t('sports.settings.active_comps', { n: s.competitions }) }}</span></td>
                                        <td style="width: 140px;">
                                            <div class="input-group input-group-sm" :title="t('sports.settings.share')">
                                                <input v-model.number="s.min_share_percent" type="number" min="0" max="100" dir="ltr" class="form-control" :disabled="!canUpdate">
                                                <span class="input-group-text">%</span>
                                            </div>
                                        </td>
                                        <td class="text-end pe-3" style="width: 70px;"><div class="form-check form-switch mb-0 d-inline-block"><input v-model="s.status" class="form-check-input" type="checkbox" :disabled="!canUpdate"></div></td>
                                    </tr>
                                </tbody>
                            </table>
                            <div class="text-muted fs-11 px-3 py-2">{{ t('sports.settings.hint_share') }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div v-if="canUpdate" class="d-flex justify-content-end mt-3 mb-4">
                <button type="submit" class="btn btn-primary" :disabled="saving"><span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}</button>
            </div>
        </form>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import MultiSelect from 'primevue/multiselect';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const canUpdate = computed(() => can('sports-settings.update'));
const loading = ref(true);
const saving = ref(false);
const countries = ref([]);
const form = reactive({ predictions_enabled: true, prizes_countries: [], odds_countries: [], enabled: true, enabled_countries: [], tier_seconds: { big: 60, normal: 180, minor: 480 }, reserve_percent: 10, daily_limit: null, configured: true, sports: [] });

async function load() {
    loading.value = true;
    try {
        const [settings, list] = await Promise.all([
            adminAxios.get('/api/admin/v1/sports-settings'),
            adminAxios.get('/api/admin/v1/countries/dropdown').catch(() => ({ data: { data: [] } })),
        ]);
        Object.assign(form, settings.data.data ?? {});
        countries.value = list.data?.data ?? [];
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

async function save() {
    saving.value = true;
    try {
        const { data } = await adminAxios.put('/api/admin/v1/sports-settings', {
            enabled: form.enabled, enabled_countries: form.enabled_countries, tier_seconds: form.tier_seconds, reserve_percent: form.reserve_percent,
            daily_limit: form.daily_limit || null, predictions_enabled: form.predictions_enabled, prizes_countries: form.prizes_countries, odds_countries: form.odds_countries, sports: form.sports.map((s) => ({ key: s.key, status: s.status, min_share_percent: s.min_share_percent })),
        });
        Object.assign(form, data.data ?? {});
        showSuccess(t('sports.common.saved'));
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        saving.value = false;
    }
}

onMounted(load);
</script>
