<template>
    <WalletModal :show="show" :title="t('rides.maps.heat_title')" size="xl" @close="$emit('close')">
        <!-- O-08 (LeeTaxi): where rides are asked for — all of them, and the ones waiting for a captain now. -->
        <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
            <nav class="nav nav-pills nav-style-1">
                <a class="nav-link" :class="{ active: tab === 'all' }" role="button" @click="tab = 'all'">
                    {{ t('rides.maps.all_rides') }} <span class="badge bg-secondary ms-1 rounded-pill">{{ data?.all?.length ?? 0 }}</span>
                </a>
                <a class="nav-link" :class="{ active: tab === 'pending' }" role="button" @click="tab = 'pending'">
                    {{ t('rides.maps.waiting_rides') }} <span class="badge bg-danger ms-1 rounded-pill">{{ data?.pending?.length ?? 0 }}</span>
                </a>
            </nav>
            <select v-if="tab === 'all'" v-model.number="days" class="form-select form-select-sm ms-auto" style="max-width: 160px">
                <option v-for="d in [7, 30, 90, 365]" :key="d" :value="d">{{ t('rides.maps.last_days', { n: d }) }}</option>
            </select>
        </div>
        <div v-if="noToken" class="alert alert-warning fs-13 mb-0">{{ t('rides.maps.no_token') }}</div>
        <div v-else class="position-relative">
            <div ref="mapEl" class="rides-map"></div>
            <div v-if="loading" class="rides-map__loading"><span class="spinner-border text-primary"></span></div>
            <div class="rides-map__legend">
                <span>{{ t('rides.maps.less') }}</span>
                <span class="rides-map__ramp" :class="`rides-map__ramp--${tab}`"></span>
                <span>{{ t('rides.maps.more') }}</span>
            </div>
        </div>
    </WalletModal>
</template>

<script setup>
import { nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../../components/wallet/WalletModal.vue';
import { makeMap } from './mapbox';

const props = defineProps({ show: { type: Boolean, default: false } });
defineEmits(['close']);
const { t, locale } = useI18n();

const mapEl = ref(null);
const tab = ref('all');
const days = ref(30);
const data = ref(null);
const loading = ref(false);
const noToken = ref(false);
let map = null;

// LeeTaxi's two colour ramps: blue for every ride, red for the ones waiting now.
const RAMPS = {
    all: ['interpolate', ['linear'], ['heatmap-density'], 0, 'rgba(0,255,255,0)', 0.2, 'rgba(0,255,255,1)', 0.4, 'rgba(0,191,255,1)', 0.6, 'rgba(0,127,255,1)', 0.8, 'rgba(0,63,255,1)', 1, 'rgba(0,0,255,1)'],
    pending: ['interpolate', ['linear'], ['heatmap-density'], 0, 'rgba(255,0,0,0)', 0.2, 'rgba(255,102,102,0.4)', 0.4, 'rgba(255,80,80,0.6)', 0.6, 'rgba(255,50,50,0.8)', 0.8, 'rgba(255,0,0,0.9)', 1, 'rgba(139,0,0,1)'],
};

async function load() {
    loading.value = true;
    try {
        const { data: res } = await adminAxios.get('/api/admin/v1/rides-map/heat', { params: { days: days.value } });
        data.value = res.data;
        noToken.value = !res.data?.map?.mapbox_token;
        if (noToken.value) return;
        await nextTick();
        if (!map) map = (await makeMap(mapEl.value, res.data.map, locale.value)).map;
        if (map.loaded()) draw(); else map.once('load', draw);
    } finally {
        loading.value = false;
    }
}

function draw() {
    const points = (tab.value === 'pending' ? data.value?.pending : data.value?.all) ?? [];
    const geojson = { type: 'FeatureCollection', features: points.map(([lat, lng]) => ({ type: 'Feature', properties: {}, geometry: { type: 'Point', coordinates: [lng, lat] } })) };
    if (map.getSource('rides')) map.getSource('rides').setData(geojson);
    else map.addSource('rides', { type: 'geojson', data: geojson });
    if (map.getLayer('rides-heat')) map.removeLayer('rides-heat');
    map.addLayer({
        id: 'rides-heat', type: 'heatmap', source: 'rides', maxzoom: 20,
        paint: {
            'heatmap-color': RAMPS[tab.value],
            'heatmap-radius': ['interpolate', ['linear'], ['zoom'], 5, 12, 12, 30, 16, 45],
            'heatmap-intensity': ['interpolate', ['linear'], ['zoom'], 5, 0.6, 15, 2],
            'heatmap-opacity': 0.85,
        },
    });
    if (points.length) {
        const lngs = points.map((p) => p[1]);
        const lats = points.map((p) => p[0]);
        map.fitBounds([[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]], { padding: 60, maxZoom: 13, duration: 600 });
    }
    setTimeout(() => map.resize(), 250);
}

watch(() => props.show, (open) => { if (open) load(); });
watch(tab, () => { if (map) draw(); });
watch(days, () => { if (props.show) load(); });
</script>

<style scoped>
.rides-map { height: 68vh; width: 100%; border-radius: 14px; overflow: hidden; }
.rides-map__loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.5); }
.rides-map__legend { position: absolute; bottom: 14px; inset-inline-start: 14px; display: flex; align-items: center; gap: 8px; background: rgba(255, 255, 255, 0.92); padding: 6px 12px; border-radius: 999px; font-size: 12px; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12); }
.rides-map__ramp { width: 110px; height: 8px; border-radius: 999px; }
.rides-map__ramp--all { background: linear-gradient(90deg, rgba(0, 255, 255, 0.3), #00bfff, #003fff, #0000ff); }
.rides-map__ramp--pending { background: linear-gradient(90deg, rgba(255, 102, 102, 0.4), #ff3232, #ff0000, #8b0000); }
</style>
