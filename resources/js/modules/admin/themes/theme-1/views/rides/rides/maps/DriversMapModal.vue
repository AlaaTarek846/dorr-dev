<template>
    <WalletModal :show="show" :title="t('rides.maps.drivers_title')" size="xl" @close="$emit('close')">
        <!-- O-07 (Jawad): every captain online or on a ride — a car per captain, its card on hover. -->
        <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
            <span class="fw-semibold">{{ t('rides.maps.drivers_count') }} <span class="badge bg-primary rounded-pill ms-1">{{ drivers.length }}</span></span>
            <span v-for="s in ['online', 'on_trip', 'paused']" :key="s" class="d-inline-flex align-items-center gap-1 fs-13">
                <span class="rides-map__dot" :style="{ background: COLORS[s] }"></span>{{ t(`rides.presence.${s}`) }} ({{ drivers.filter((d) => d.status === s).length }})
            </span>
            <button type="button" class="btn btn-sm btn-light ms-auto" :disabled="loading" @click="load"><i class="ri-refresh-line me-1"></i>{{ t('rides.maps.refresh') }}</button>
        </div>
        <div v-if="noToken" class="alert alert-warning fs-13 mb-0">{{ t('rides.maps.no_token') }}</div>
        <div v-else class="position-relative">
            <div ref="mapEl" class="rides-map"></div>
            <div v-if="loading" class="rides-map__loading"><span class="spinner-border text-primary"></span></div>
            <div v-if="!loading && !drivers.length" class="rides-map__empty">{{ t('rides.maps.no_drivers') }}</div>
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

const COLORS = { online: '#26bf94', on_trip: '#fa7552', paused: '#94a3b8' };
const mapEl = ref(null);
const drivers = ref([]);
const loading = ref(false);
const noToken = ref(false);
let map = null;
let lib = null;
let markers = [];

const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const stars = (r) => (r ? '★★★★★'.slice(0, Math.round(r)) + '☆☆☆☆☆'.slice(0, 5 - Math.round(r)) : '☆☆☆☆☆');
const ago = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '—');

// The card Jawad shows: the name, the stars and rating, the phone, when the phone last reported.
function card(d) {
    return `<div style="min-width:210px;font-family:inherit">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px">
            ${d.avatar ? `<img src="${esc(d.avatar)}" style="width:34px;height:34px;border-radius:50%;object-fit:cover">` : ''}
            <div><div style="font-weight:700;font-size:14px">${esc(d.name)}</div>
            <div style="font-size:11px;color:${COLORS[d.status]}">● ${esc(t(`rides.presence.${d.status}`))}</div></div>
        </div>
        <div style="margin-bottom:4px"><span style="color:#f5a623">${stars(d.rating)}</span>
            <span style="font-size:12px;color:#666;margin-inline-start:4px">(${d.rating ?? '—'}) · ${d.trips} ${esc(t('rides.maps.trips'))}</span></div>
        <div style="font-size:13px;margin-bottom:3px">📞 <span dir="ltr">${esc(d.phone ?? '—')}</span></div>
        <div style="font-size:12px;color:#888">🕐 ${esc(ago(d.last_seen_at))}</div>
    </div>`;
}

// A car, coloured by the captain's state, turned the way the car is heading.
function carElement(d) {
    const el = document.createElement('div');
    el.className = 'rides-car';
    el.innerHTML = `<div style="transform:rotate(${d.heading ?? 0}deg);width:34px;height:34px;border-radius:50%;background:${COLORS[d.status]};display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(0,0,0,.25);border:2px solid #fff">
        <i class="ri-taxi-fill" style="color:#fff;font-size:18px"></i></div>`;
    return el;
}

async function load() {
    loading.value = true;
    try {
        const { data: res } = await adminAxios.get('/api/admin/v1/rides-map/drivers');
        drivers.value = res.data?.drivers ?? [];
        noToken.value = !res.data?.map?.mapbox_token;
        if (noToken.value) return;
        await nextTick();
        if (!map) ({ map, mapboxgl: lib } = await makeMap(mapEl.value, res.data.map, locale.value));
        draw();
    } finally {
        loading.value = false;
    }
}

function draw() {
    markers.forEach((m) => m.remove());
    markers = drivers.value.map((d) => {
        const popup = new lib.Popup({ offset: 20, closeButton: false }).setHTML(card(d));
        const marker = new lib.Marker(carElement(d)).setLngLat([d.lng, d.lat]).setPopup(popup).addTo(map);
        const el = marker.getElement();
        el.addEventListener('mouseenter', () => popup.addTo(map));
        el.addEventListener('mouseleave', () => popup.remove());
        return marker;
    });
    if (drivers.value.length) {
        const lngs = drivers.value.map((d) => d.lng);
        const lats = drivers.value.map((d) => d.lat);
        map.fitBounds([[Math.min(...lngs), Math.min(...lats)], [Math.max(...lngs), Math.max(...lats)]], { padding: 70, maxZoom: 15, duration: 600 });
    }
    setTimeout(() => map.resize(), 250);
}

watch(() => props.show, (open) => { if (open) load(); });
</script>

<style scoped>
.rides-map { height: 68vh; width: 100%; border-radius: 14px; overflow: hidden; }
.rides-map__loading { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: rgba(255, 255, 255, 0.5); }
.rides-map__empty { position: absolute; top: 14px; left: 50%; transform: translateX(-50%); background: #fff; padding: 6px 14px; border-radius: 999px; font-size: 13px; box-shadow: 0 4px 14px rgba(0, 0, 0, 0.12); }
.rides-map__dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
</style>
