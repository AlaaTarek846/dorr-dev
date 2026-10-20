<template>
    <div>
        <WalletPageHeader :title="t('rides.settings.title')" :section="t('sidebar.rides')" />

        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card custom-card">
                    <div class="card-body">
                        <!-- O-01, O-02: one map provider for the server and both apps — Google or Mapbox. -->
                        <h6 class="fw-semibold mb-1"><i class="ri-earth-line me-1 text-primary"></i>{{ t('rides.settings.provider') }}</h6>
                        <p class="text-muted fs-13">{{ t('rides.settings.provider_hint') }}</p>
                        <div class="btn-group w-100 mb-4" role="group">
                            <button v-for="p in ['google', 'mapbox']" :key="p" type="button" class="btn" :class="form.map_provider === p ? 'btn-primary' : 'btn-outline-primary'" :disabled="!can('ride-settings.update')" @click="form.map_provider = p">
                                <i :class="p === 'google' ? 'ri-google-line' : 'ri-map-pin-2-line'" class="me-1"></i>{{ t(`rides.settings.provider_${p}`) }}
                            </button>
                        </div>

                        <div v-if="form.map_provider === 'mapbox'" class="mb-4">
                            <h6 class="fw-semibold mb-1"><i class="ri-map-pin-2-line me-1 text-primary"></i>Mapbox</h6>
                            <p class="text-muted fs-13">{{ t('rides.settings.mapbox_hint') }}</p>
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge" :class="state.mapbox_token_set ? 'bg-success-transparent' : (state.mapbox_env_set ? 'bg-info-transparent' : 'bg-warning-transparent')">
                                    {{ state.mapbox_token_set ? t('rides.settings.key_set', { hint: state.mapbox_token_hint }) : (state.mapbox_env_set ? t('rides.settings.key_env') : t('rides.settings.key_none')) }}
                                </span>
                            </div>
                            <label class="form-label">{{ t('rides.settings.mapbox_token') }}</label>
                            <div class="input-group mb-2">
                                <input v-model="form.mapbox_token" :type="showMapbox ? 'text' : 'password'" dir="ltr" class="form-control" autocomplete="off" placeholder="pk.eyJ1…">
                                <button type="button" class="btn btn-light" @click="showMapbox = !showMapbox"><i :class="showMapbox ? 'ri-eye-off-line' : 'ri-eye-line'"></i></button>
                            </div>
                            <label class="form-label">{{ t('rides.settings.mapbox_style') }}</label>
                            <input v-model="form.mapbox_style" dir="ltr" class="form-control" placeholder="mapbox/streets-v12">
                        </div>

                        <template v-if="form.map_provider === 'google'">
                        <h6 class="fw-semibold mb-1"><i class="ri-map-2-line me-1 text-primary"></i>{{ t('rides.settings.google') }}</h6>
                        <p class="text-muted fs-13">{{ t('rides.settings.google_hint') }}</p>

                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge" :class="state.google_maps_key_set ? 'bg-success-transparent' : (state.env_key_set ? 'bg-info-transparent' : 'bg-warning-transparent')">
                                {{ state.google_maps_key_set ? t('rides.settings.key_set', { hint: state.google_maps_key_hint }) : (state.env_key_set ? t('rides.settings.key_env') : t('rides.settings.key_none')) }}
                            </span>
                        </div>

                        <label class="form-label">{{ t('rides.settings.new_key') }}</label>
                        <div class="input-group mb-1">
                            <input v-model="form.google_maps_key" :type="showKey ? 'text' : 'password'" dir="ltr" class="form-control" autocomplete="off" placeholder="AIza…">
                            <button type="button" class="btn btn-light" @click="showKey = !showKey"><i :class="showKey ? 'ri-eye-off-line' : 'ri-eye-line'"></i></button>
                        </div>
                        <small class="text-muted d-block mb-3">{{ t('rides.settings.key_never_shown') }}</small>

                        <div v-if="can('ride-settings.update')" class="mb-3">
                            <button type="button" class="btn btn-outline-primary btn-sm" :disabled="checking" @click="checkKey()">
                                <span v-if="checking" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-shield-check-line me-1"></i>{{ checking ? t('rides.settings.checking') : t('rides.settings.check') }}
                            </button>
                            <small class="text-muted ms-2">{{ t('rides.settings.check_hint') }}</small>
                        </div>
                        <div v-if="report" class="border rounded p-3 mb-3 fs-13">
                            <div class="fw-semibold mb-2">{{ t('rides.settings.check_title') }}</div>
                            <div v-for="row in report.checks" :key="row.api" class="d-flex gap-2 mb-2">
                                <i :class="row.ok ? 'ri-checkbox-circle-fill text-success' : 'ri-close-circle-fill text-danger'" class="fs-16"></i>
                                <div>
                                    <div class="fw-semibold">{{ t('rides.settings.check_api_' + row.api) }}<span v-if="row.http" class="text-muted fw-normal" dir="ltr"> · HTTP {{ row.http }}<template v-if="row.status"> · {{ row.status }}</template></span></div>
                                    <div>{{ row.hint }}</div>
                                    <div v-if="!row.ok && row.message" class="text-muted" dir="ltr">{{ t('rides.settings.google_said') }} {{ row.message }}</div>
                                </div>
                            </div>
                            <div v-if="report.ok" class="text-success">{{ t('rides.settings.check_ok') }}</div>
                        </div>
                        <div v-for="(reason, api) in pausedNow" :key="api" class="alert alert-warning py-2 fs-13">{{ t('rides.settings.google_paused', { reason }) }} <span class="text-muted" dir="ltr">({{ api }})</span></div>
                        </template>

                        <h6 class="fw-semibold mt-4 mb-1"><i class="ri-route-line me-1 text-primary"></i>{{ t('rides.settings.fallback') }}</h6>
                        <p class="text-muted fs-13">{{ t('rides.settings.fallback_hint') }}</p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">{{ t('rides.settings.speed') }}</label>
                                <div class="input-group"><input v-model.number="form.fallback_speed_kmh" type="number" min="10" max="150" class="form-control"><span class="input-group-text">km/h</span></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ t('rides.settings.road_factor') }}</label>
                                <div class="input-group"><input v-model.number="roadFactor" type="number" min="1" max="3" step="0.05" class="form-control"><span class="input-group-text">×</span></div>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">{{ t('rides.settings.cache_days') }}</label>
                                <input v-model.number="form.geocode_cache_days" type="number" min="1" max="365" class="form-control">
                            </div>
                        </div>
                        <div class="d-flex gap-2 mt-4">
                            <button v-if="can('ride-settings.update')" type="button" class="btn btn-primary" :disabled="saving" @click="save()">
                                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('save') }}
                            </button>
                            <button v-if="can('ride-settings.update') && state.google_maps_key_set" type="button" class="btn btn-outline-danger" :disabled="saving" @click="save(true)">{{ t('rides.settings.clear_key') }}</button>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-xl-5">
                <div class="card custom-card bg-light border-0">
                    <div class="card-body fs-13">
                        <h6 class="fw-semibold">{{ t('rides.settings.how_title') }}</h6>
                        <ul class="mb-0 ps-3">
                            <li class="mb-1">{{ t('rides.settings.how_1') }}</li>
                            <li class="mb-1">{{ t('rides.settings.how_2') }}</li>
                            <li>{{ t('rides.settings.how_3') }}</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The Google key (written here, never shown back) and the free estimate used when Google can't answer.
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const state = reactive({ google_maps_key_set: false, google_maps_key_hint: null, env_key_set: false, map_provider: 'google', mapbox_token_set: false, mapbox_token_hint: null, mapbox_env_set: false, google_paused: { routes: null, geocoding: null } });
const report = ref(null);
const checking = ref(false);
const pausedNow = computed(() => Object.fromEntries(Object.entries(report.value?.paused ?? state.google_paused ?? {}).filter(([, reason]) => reason)));
const form = reactive({ google_maps_key: '', map_provider: 'google', mapbox_token: '', mapbox_style: 'mapbox/streets-v12', fallback_speed_kmh: 60, fallback_road_factor_bp: 13000, geocode_cache_days: 30 });
const saving = ref(false);
const showKey = ref(false);
const showMapbox = ref(false);
const roadFactor = computed({ get: () => form.fallback_road_factor_bp / 10000, set: (v) => { form.fallback_road_factor_bp = Math.round(Number(v) * 10000); } });

function apply(data) {
    Object.assign(state, data);
    Object.assign(form, {
        google_maps_key: '', mapbox_token: '', map_provider: data.map_provider ?? 'google', mapbox_style: data.mapbox_style ?? 'mapbox/streets-v12',
        fallback_speed_kmh: data.fallback_speed_kmh, fallback_road_factor_bp: data.fallback_road_factor_bp, geocode_cache_days: data.geocode_cache_days,
    });
}

async function checkKey() {
    checking.value = true;
    try {
        const { data } = await adminAxios.post('/api/admin/v1/ride-settings/check-google');
        report.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        checking.value = false;
    }
}

async function save(clear = false) {
    saving.value = true;
    const payload = { map_provider: form.map_provider, mapbox_style: form.mapbox_style || 'mapbox/streets-v12', fallback_speed_kmh: form.fallback_speed_kmh, fallback_road_factor_bp: form.fallback_road_factor_bp, geocode_cache_days: form.geocode_cache_days };
    if (form.mapbox_token.trim()) payload.mapbox_token = form.mapbox_token.trim();
    if (clear) payload.google_maps_key = '';
    else if (form.google_maps_key.trim()) payload.google_maps_key = form.google_maps_key.trim();
    try {
        const { data } = await adminAxios.put('/api/admin/v1/ride-settings', payload);
        apply(data.data);
        report.value = null;
        showSuccess(t('rides.drivers.saved'));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        saving.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-settings');
        apply(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>
