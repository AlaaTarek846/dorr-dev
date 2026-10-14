<template>
    <div>
        <WalletPageHeader :title="t('rides.settings.title')" :section="t('sidebar.rides')" />

        <div class="row g-3">
            <div class="col-xl-7">
                <div class="card custom-card">
                    <div class="card-body">
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

const state = reactive({ google_maps_key_set: false, google_maps_key_hint: null, env_key_set: false });
const form = reactive({ google_maps_key: '', fallback_speed_kmh: 60, fallback_road_factor_bp: 13000, geocode_cache_days: 30 });
const saving = ref(false);
const showKey = ref(false);
const roadFactor = computed({ get: () => form.fallback_road_factor_bp / 10000, set: (v) => { form.fallback_road_factor_bp = Math.round(Number(v) * 10000); } });

function apply(data) {
    Object.assign(state, data);
    Object.assign(form, { google_maps_key: '', fallback_speed_kmh: data.fallback_speed_kmh, fallback_road_factor_bp: data.fallback_road_factor_bp, geocode_cache_days: data.geocode_cache_days });
}

async function save(clear = false) {
    saving.value = true;
    const payload = { fallback_speed_kmh: form.fallback_speed_kmh, fallback_road_factor_bp: form.fallback_road_factor_bp, geocode_cache_days: form.geocode_cache_days };
    if (clear) payload.google_maps_key = '';
    else if (form.google_maps_key.trim()) payload.google_maps_key = form.google_maps_key.trim();
    try {
        const { data } = await adminAxios.put('/api/admin/v1/ride-settings', payload);
        apply(data.data);
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
