<template>
    <div>
        <WalletPageHeader :title="t('rides.zones.title')" :section="t('sidebar.rides')" />

        <div class="alert alert-light border mb-3 fs-13">{{ t('rides.zones.hint') }}</div>

        <div class="d-flex flex-wrap gap-2 align-items-end mb-3">
            <div>
                <label class="form-label fs-12 mb-1">{{ t('rides.drivers.country') }}</label>
                <select v-model="countryId" class="form-select form-select-sm" style="min-width: 180px">
                    <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
            </div>
            <button v-if="can('ride-zones.create')" type="button" class="btn btn-primary btn-sm ms-auto" @click="openZone(null)">
                <i class="ri-add-line me-1"></i>{{ t('rides.zones.add_zone') }}
            </button>
        </div>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border"></span></div>
        <div v-else-if="!visibleZones.length" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</div>
        <div v-else class="row g-3">
            <div v-for="zone in visibleZones" :key="zone.id" class="col-xl-6">
                <div class="card custom-card h-100" :class="{ 'opacity-50': !zone.status }">
                    <div class="card-header d-flex align-items-center gap-2 py-3">
                        <div>
                            <h6 class="mb-0">{{ zone.name }}</h6>
                            <span class="badge bg-info-transparent fs-11">{{ t(`rides.zones.type.${zone.type}`) }}</span>
                            <span v-if="zone.config.pickup_required || zone.type === 'pickup_only'" class="badge bg-warning-transparent fs-11 ms-1">{{ t('rides.zones.for_pickup') }} ✓</span>
                            <span v-if="zone.config.dropoff_required" class="badge bg-warning-transparent fs-11 ms-1">{{ t('rides.zones.for_dropoff') }} ✓</span>
                            <span v-if="zone.config.queue_enabled" class="badge bg-success-transparent fs-11 ms-1"><i class="ri-team-line me-1"></i>{{ t('rides.zones.queue_size', { n: zone.queue_size }) }}</span>
                        </div>
                        <div class="ms-auto d-flex gap-1">
                            <router-link v-if="can('ride-policies.view')" class="btn btn-sm btn-light" :to="{ name: 'admin.rides.policies', query: { country_id: zone.country_id, zone_id: zone.id } }">
                                <i class="ri-price-tag-3-line me-1"></i>{{ t('rides.zones.prices') }}
                            </router-link>
                            <button v-if="can('ride-zones.update')" type="button" class="btn btn-sm btn-light btn-icon" @click="openZone(zone)"><i class="ri-edit-line"></i></button>
                            <button v-if="can('ride-zones.delete')" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="removeZone(zone)"><i class="ri-delete-bin-line"></i></button>
                        </div>
                    </div>
                    <div class="card-body p-0">
                        <div class="d-flex align-items-center px-3 py-2 border-bottom">
                            <span class="fw-semibold fs-13">{{ t('rides.zones.points') }} · {{ zone.points.length }}</span>
                            <button v-if="can('ride-zones.create')" type="button" class="btn btn-sm btn-primary-light ms-auto" @click="openPoint(zone, null)">
                                <i class="ri-add-line me-1"></i>{{ t('rides.zones.add_point') }}
                            </button>
                        </div>
                        <div v-if="!zone.points.length" class="text-muted fs-12 px-3 py-3">{{ t('rides.zones.no_points') }}</div>
                        <table v-else class="table mb-0 align-middle fs-13">
                            <tbody>
                                <tr v-for="point in zone.points" :key="point.id" :class="{ 'opacity-50': !point.status }">
                                    <td class="ps-3">
                                        <div class="fw-semibold">{{ point.name }} <span class="badge bg-light text-default fs-10">{{ t(`rides.zones.kind.${point.kind}`) }}</span></div>
                                        <div class="fs-11 text-muted">
                                            <span v-if="point.for_pickup">{{ t('rides.zones.for_pickup') }}</span>
                                            <span v-if="point.for_pickup && point.for_dropoff"> · </span>
                                            <span v-if="point.for_dropoff">{{ t('rides.zones.for_dropoff') }}</span>
                                            · <span dir="ltr">{{ point.lat }}, {{ point.lng }}</span>
                                        </div>
                                    </td>
                                    <td class="text-end pe-3">
                                        <button v-if="can('ride-zones.update')" type="button" class="btn btn-sm btn-light btn-icon me-1" @click="openPoint(zone, point)"><i class="ri-edit-line"></i></button>
                                        <button v-if="can('ride-zones.delete')" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="removePoint(zone, point)"><i class="ri-delete-bin-line"></i></button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <WalletModal :show="!!zoneForm" :title="zoneForm?.id ? t('rides.zones.edit_zone') : t('rides.zones.add_zone')" size="lg" @close="zoneForm = null">
            <template v-if="zoneForm">
                <div class="row g-2 mb-2">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.document_types.name_ar') }}</label>
                        <input v-model="zoneForm.name.ar" class="form-control" dir="rtl">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.document_types.name_en') }}</label>
                        <input v-model="zoneForm.name.en" class="form-control" dir="ltr">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="form-label">{{ t('rides.zones.type_label') }}</label>
                        <select v-model="zoneForm.type" class="form-select">
                            <option v-for="x in options.types" :key="x" :value="x">{{ t(`rides.zones.type.${x}`) }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('rides.drivers.country') }}</label>
                        <select v-model="zoneForm.country_id" class="form-select" @change="zoneForm.city_id = null">
                            <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">{{ t('rides.drivers.city') }}</label>
                        <select v-model="zoneForm.city_id" class="form-select">
                            <option :value="null">—</option>
                            <option v-for="c in options.cities.filter((x) => x.country_id === zoneForm.country_id)" :key="c.id" :value="c.id">{{ c.name }}</option>
                        </select>
                    </div>
                </div>
                <label class="form-label">{{ t('rides.zones.polygon') }}</label>
                <textarea v-model="zoneForm.polygonText" class="form-control mb-1" rows="5" dir="ltr" :placeholder="t('rides.zones.polygon_hint')"></textarea>
                <div class="form-text mb-3">{{ t('rides.zones.polygon_hint') }}</div>
                <div class="form-check form-switch"><input id="pr" v-model="zoneForm.pickup_required" class="form-check-input" type="checkbox" :disabled="zoneForm.type === 'pickup_only'"><label class="form-check-label" for="pr">{{ t('rides.zones.pickup_required') }}</label></div>
                <div v-if="zoneForm.type === 'pickup_only'" class="form-text mb-1">{{ t('rides.zones.pickup_only_note') }}</div>
                <div class="form-check form-switch"><input id="dr" v-model="zoneForm.dropoff_required" class="form-check-input" type="checkbox"><label class="form-check-label" for="dr">{{ t('rides.zones.dropoff_required') }}</label></div>
                <div v-if="zoneForm.type === 'airport'" class="form-check form-switch"><input id="qe" v-model="zoneForm.queue_enabled" class="form-check-input" type="checkbox"><label class="form-check-label" for="qe">{{ t('rides.zones.queue_enabled') }}</label></div>
                <div v-if="zoneForm.type === 'airport'" class="form-text mb-1">{{ t('rides.zones.queue_hint') }}</div>
                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.zones.priority') }}</label>
                        <input v-model.number="zoneForm.priority" type="number" min="0" class="form-control" dir="ltr">
                    </div>
                </div>
                <div class="form-check form-switch mt-2"><input id="zs" v-model="zoneForm.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="zs">{{ t('wallet.common.active') }}</label></div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="zoneForm = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" @click="saveZone">{{ t('save') }}</button>
            </template>
        </WalletModal>

        <WalletModal :show="!!pointForm" :title="pointForm?.id ? t('rides.zones.edit_point') : t('rides.zones.add_point')" size="lg" @close="pointForm = null">
            <template v-if="pointForm">
                <div class="row g-2 mb-2">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.document_types.name_ar') }}</label>
                        <input v-model="pointForm.name.ar" class="form-control" dir="rtl">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.document_types.name_en') }}</label>
                        <input v-model="pointForm.name.en" class="form-control" dir="ltr">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.zones.instructions_ar') }}</label>
                        <input v-model="pointForm.instructions.ar" class="form-control" dir="rtl" maxlength="255">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">{{ t('rides.zones.instructions_en') }}</label>
                        <input v-model="pointForm.instructions.en" class="form-control" dir="ltr" maxlength="255">
                    </div>
                </div>
                <div class="row g-2 mb-2">
                    <div class="col-md-4">
                        <label class="form-label">{{ t('rides.zones.kind_label') }}</label>
                        <select v-model="pointForm.kind" class="form-select">
                            <option v-for="k in options.point_kinds" :key="k" :value="k">{{ t(`rides.zones.kind.${k}`) }}</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('rides.zones.lat') }}</label>
                        <input v-model.number="pointForm.lat" type="number" step="any" class="form-control" dir="ltr">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">{{ t('rides.zones.lng') }}</label>
                        <input v-model.number="pointForm.lng" type="number" step="any" class="form-control" dir="ltr">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">{{ t('rides.zones.sort_order') }}</label>
                        <input v-model.number="pointForm.sort_order" type="number" min="0" class="form-control" dir="ltr">
                    </div>
                </div>
                <div class="form-check form-switch"><input id="fp" v-model="pointForm.for_pickup" class="form-check-input" type="checkbox"><label class="form-check-label" for="fp">{{ t('rides.zones.for_pickup') }}</label></div>
                <div class="form-check form-switch"><input id="fd" v-model="pointForm.for_dropoff" class="form-check-input" type="checkbox"><label class="form-check-label" for="fd">{{ t('rides.zones.for_dropoff') }}</label></div>
                <div class="form-check form-switch"><input id="ps" v-model="pointForm.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="ps">{{ t('wallet.common.active') }}</label></div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="pointForm = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" @click="savePoint">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// Zones with a meaning (airport, pickup-only…) and, inside each, the approved gates / terminals riders
// pick from. Both languages everywhere; the boundary is typed one corner per line.
const { t } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const options = reactive({ countries: [], cities: [], types: [], point_kinds: [] });
const zones = ref([]);
const countryId = ref(null);
const loading = ref(false);
const zoneForm = ref(null);
const pointForm = ref(null);

const visibleZones = computed(() => zones.value.filter((z) => z.country_id === countryId.value));

const polygonToText = (polygon) => (polygon ?? []).map(([lat, lng]) => `${lat}, ${lng}`).join('\n');
const textToPolygon = (text) => text.split('\n').map((line) => line.trim()).filter(Boolean).map((line) => line.split(/[,\s]+/).map(Number));

function openZone(zone) {
    zoneForm.value = {
        id: zone?.id ?? null,
        country_id: zone?.country_id ?? countryId.value,
        city_id: zone?.city_id ?? null,
        type: zone?.type ?? 'airport',
        name: { ar: zone?.names?.ar ?? '', en: zone?.names?.en ?? '' },
        polygonText: polygonToText(zone?.polygon),
        pickup_required: !!zone?.config?.pickup_required || zone?.type === 'pickup_only',
        dropoff_required: !!zone?.config?.dropoff_required,
        queue_enabled: !!zone?.config?.queue_enabled,
        priority: zone?.priority ?? 0,
        status: zone?.status ?? true,
    };
}

async function saveZone() {
    const { id, polygonText, pickup_required: pickup, dropoff_required: dropoff, queue_enabled: queue, ...rest } = zoneForm.value;
    const body = { ...rest, polygon: textToPolygon(polygonText), config: { pickup_required: pickup, dropoff_required: dropoff, queue_enabled: queue } };
    try {
        if (id) await adminAxios.put(`/api/admin/v1/ride-zones/${id}`, body);
        else await adminAxios.post('/api/admin/v1/ride-zones', body);
        zoneForm.value = null;
        showSuccess(t('rides.drivers.saved'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function removeZone(zone) {
    if (!window.confirm(t('rides.zones.confirm_delete'))) return;
    try {
        await adminAxios.delete(`/api/admin/v1/ride-zones/${zone.id}`);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

function openPoint(zone, point) {
    pointForm.value = {
        zone_id: zone.id,
        id: point?.id ?? null,
        name: { ar: point?.names?.ar ?? '', en: point?.names?.en ?? '' },
        instructions: { ar: point?.instructions?.ar ?? '', en: point?.instructions?.en ?? '' },
        kind: point?.kind ?? 'gate',
        lat: point?.lat ?? null,
        lng: point?.lng ?? null,
        for_pickup: point?.for_pickup ?? true,
        for_dropoff: point?.for_dropoff ?? true,
        status: point?.status ?? true,
        sort_order: point?.sort_order ?? 0,
    };
}

async function savePoint() {
    const { zone_id: zoneId, id, ...body } = pointForm.value;
    try {
        if (id) await adminAxios.put(`/api/admin/v1/ride-zones/${zoneId}/points/${id}`, body);
        else await adminAxios.post(`/api/admin/v1/ride-zones/${zoneId}/points`, body);
        pointForm.value = null;
        showSuccess(t('rides.drivers.saved'));
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function removePoint(zone, point) {
    if (!window.confirm(t('rides.zones.confirm_delete'))) return;
    try {
        await adminAxios.delete(`/api/admin/v1/ride-zones/${zone.id}/points/${point.id}`);
        load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-zones');
        zones.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ride-zones/options');
        Object.assign(options, data.data ?? {});
        countryId.value = options.countries[0]?.id ?? null;
        await load();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>
