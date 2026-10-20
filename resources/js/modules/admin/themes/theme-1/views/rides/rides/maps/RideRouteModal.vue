<template>
    <WalletModal :show="!!ride" :title="ride ? `${t('rides.rides.ride')} ${ride.number}` : ''" size="xl" @close="$emit('close')">
        <!-- O-06 (LeeTaxi's map button on each row): the trip on the map — the pickup, the drop-off, the route. -->
        <template v-if="ride">
            <div class="row g-2 mb-3 fs-13">
                <div class="col-md-6"><i class="ri-map-pin-2-fill text-success me-1"></i>{{ ride.origin_address }}</div>
                <div class="col-md-6"><i class="ri-flag-2-fill text-danger me-1"></i>{{ ride.destination_address || t('rides.rides.open_destination') }}</div>
            </div>
            <div v-if="noToken" class="alert alert-warning fs-13 mb-0">{{ t('rides.maps.no_token') }}</div>
            <div v-else ref="mapEl" class="rides-map"></div>
        </template>
    </WalletModal>
</template>

<script setup>
import { nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../../components/wallet/WalletModal.vue';
import { decodePolyline, makeMap } from './mapbox';

const props = defineProps({ ride: { type: Object, default: null } });
defineEmits(['close']);
const { t, locale } = useI18n();
const mapEl = ref(null);
const noToken = ref(false);
let settings = null;

watch(() => props.ride, async (r) => {
    if (!r) return;
    if (!settings) settings = (await adminAxios.get('/api/admin/v1/rides-map/drivers')).data.data?.map ?? {};
    noToken.value = !settings.mapbox_token;
    if (noToken.value) return;
    await nextTick();
    const { map, mapboxgl } = await makeMap(mapEl.value, settings, locale.value);
    map.on('load', () => {
        const from = [r.origin.lng, r.origin.lat];
        const to = r.destination ? [r.destination.lng, r.destination.lat] : null;
        const line = r.polyline ? decodePolyline(r.polyline) : (to ? [from, to] : []);
        if (line.length > 1) {
            map.addSource('route', { type: 'geojson', data: { type: 'Feature', geometry: { type: 'LineString', coordinates: line } } });
            map.addLayer({ id: 'route-casing', type: 'line', source: 'route', paint: { 'line-color': '#001B53', 'line-width': 9, 'line-opacity': 0.25 }, layout: { 'line-cap': 'round', 'line-join': 'round' } });
            map.addLayer({ id: 'route', type: 'line', source: 'route', paint: { 'line-color': '#001B53', 'line-width': 5 }, layout: { 'line-cap': 'round', 'line-join': 'round' } });
        }
        new mapboxgl.Marker({ color: '#26bf94' }).setLngLat(from).addTo(map);
        if (to) new mapboxgl.Marker({ color: '#fa7552' }).setLngLat(to).addTo(map);
        const all = line.length ? line : [from];
        const lngs = all.map((p) => p[0]);
        const lats = all.map((p) => p[1]);
        map.fitBounds([[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]], { padding: 60, maxZoom: 15, duration: 0 });
        setTimeout(() => map.resize(), 250);
    });
});
</script>

<style scoped>
.rides-map { height: 62vh; width: 100%; border-radius: 14px; overflow: hidden; }
</style>
