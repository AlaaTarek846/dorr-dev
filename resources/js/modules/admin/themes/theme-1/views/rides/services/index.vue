<template>
    <div>
        <WalletPageHeader :title="t('rides.driver_services.title')" :section="t('sidebar.rides')" />
        <p class="text-muted fs-13 mb-3">{{ t('rides.driver_services.hint') }}</p>

        <ul class="nav nav-tabs mb-3">
            <li v-for="tab in ['matrix', 'services']" :key="tab" class="nav-item">
                <a href="#" class="nav-link" :class="{ active: current === tab }" @click.prevent="current = tab">{{ t(`rides.driver_services.tabs.${tab}`) }}</a>
            </li>
        </ul>

        <!-- One country: which kind of vehicle works in which service. -->
        <div v-if="current === 'matrix'" class="card custom-card">
            <div class="card-header d-flex flex-wrap gap-2 align-items-center py-3">
                <select v-model="countryId" class="form-select form-select-sm" style="max-width: 260px">
                    <option v-for="c in countries" :key="c.id" :value="c.id">{{ c.name }} ({{ c.code }})</option>
                </select>
                <span v-if="matrix && !saved" class="badge bg-warning-transparent">{{ t('rides.driver_services.defaults') }}</span>
                <button v-if="can('driver-services.update')" type="button" class="btn btn-primary btn-sm ms-auto" :disabled="!matrix || busy" @click="saveMatrix">
                    <i class="ri-save-line me-1"></i>{{ t('save') }}
                </button>
            </div>
            <div class="table-responsive">
                <table v-if="matrix" class="table mb-0 align-middle fs-13 text-center">
                    <thead>
                        <tr>
                            <th class="text-start">{{ t('rides.driver_services.service') }}</th>
                            <th v-for="k in kinds" :key="k"><i :class="kindIcon(k)" class="d-block fs-18 mb-1"></i>{{ t(`rides.driver_services.kinds.${k}`) }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in services" :key="s.key" :class="{ 'opacity-50': !s.status }">
                            <td class="text-start">
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle me-2" :style="{ width: '28px', height: '28px', background: s.color + '22', color: s.color }"><i :class="iconClass(s.icon)"></i></span>
                                <b>{{ word(s.name) }}</b>
                                <span v-if="s.coming_soon" class="badge bg-light text-dark ms-1">{{ t('rides.driver_services.coming_soon') }}</span>
                            </td>
                            <td v-for="k in kinds" :key="k">
                                <div class="form-check form-switch d-inline-block m-0">
                                    <input v-model="matrix[s.key][k]" class="form-check-input" type="checkbox" :disabled="!can('driver-services.update')">
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <div v-else class="text-muted text-center py-4">{{ t('rides.driver_services.pick_country') }}</div>
            </div>
            <div class="card-footer fs-12 text-muted">{{ t('rides.driver_services.matrix_note') }}</div>
        </div>

        <!-- The services: names, icons, colours, order, coming soon. -->
        <div v-else class="card custom-card">
            <div class="card-header d-flex align-items-center py-3">
                <h6 class="mb-0">{{ t('rides.driver_services.tabs.services') }}</h6>
                <button v-if="can('driver-services.create')" type="button" class="btn btn-primary btn-sm ms-auto" @click="open(null)"><i class="ri-add-line me-1"></i>{{ t('rides.lists.add') }}</button>
            </div>
            <div class="table-responsive">
                <table class="table mb-0 align-middle fs-13">
                    <thead><tr><th>{{ t('rides.driver_services.service') }}</th><th>{{ t('rides.driver_services.key') }}</th><th>{{ t('rides.driver_services.description') }}</th><th>{{ t('rides.loyalty.sort') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="s in services" :key="s.id" :class="{ 'opacity-50': !s.status }">
                            <td>
                                <span class="d-inline-flex align-items-center justify-content-center rounded-circle me-2" :style="{ width: '28px', height: '28px', background: s.color + '22', color: s.color }"><i :class="iconClass(s.icon)"></i></span>
                                <b>{{ word(s.name) }}</b>
                                <span v-if="s.coming_soon" class="badge bg-light text-dark ms-1">{{ t('rides.driver_services.coming_soon') }}</span>
                            </td>
                            <td dir="ltr"><code>{{ s.key }}</code></td>
                            <td class="text-muted">{{ word(s.description) }}</td>
                            <td>{{ s.sort_order }}</td>
                            <td class="text-end">
                                <button v-if="can('driver-services.update')" type="button" class="btn btn-sm btn-light btn-icon me-1" @click="open(s)"><i class="ri-edit-line"></i></button>
                                <button v-if="can('driver-services.delete')" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(s)"><i class="ri-delete-bin-line"></i></button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <WalletModal :show="!!form" :title="t('rides.driver_services.service')" size="lg" @close="form = null">
            <template v-if="form">
                <div class="row g-2">
                    <div class="col-md-4"><label class="form-label">{{ t('rides.driver_services.key') }}</label><input v-model="form.key" class="form-control" dir="ltr" :disabled="!!form.id"></div>
                    <div class="col-md-4"><label class="form-label">{{ t('rides.driver_services.service') }} (ع)</label><input v-model="form.name.ar" class="form-control" dir="rtl"></div>
                    <div class="col-md-4"><label class="form-label">{{ t('rides.driver_services.service') }} (EN)</label><input v-model="form.name.en" class="form-control" dir="ltr"></div>
                    <div class="col-md-6"><label class="form-label">{{ t('rides.driver_services.description') }} (ع)</label><input v-model="form.description.ar" class="form-control" dir="rtl"></div>
                    <div class="col-md-6"><label class="form-label">{{ t('rides.driver_services.description') }} (EN)</label><input v-model="form.description.en" class="form-control" dir="ltr"></div>
                    <div class="col-12">
                        <label class="form-label">{{ t('rides.driver_services.icon') }}</label>
                        <div class="d-flex flex-wrap gap-2">
                            <button v-for="i in icons" :key="i" type="button" class="btn btn-sm" :class="form.icon === i ? 'btn-primary' : 'btn-light'" @click="form.icon = i"><i :class="iconClass(i)" class="fs-16"></i></button>
                        </div>
                    </div>
                    <div class="col-md-2"><label class="form-label">{{ t('rides.loyalty.color') }}</label><input v-model="form.color" type="color" class="form-control form-control-color w-100"></div>
                    <div class="col-md-2"><label class="form-label">{{ t('rides.loyalty.sort') }}</label><input v-model.number="form.sort_order" type="number" min="0" class="form-control"></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch"><input id="ds-soon" v-model="form.coming_soon" class="form-check-input" type="checkbox"><label class="form-check-label" for="ds-soon">{{ t('rides.driver_services.coming_soon') }}</label></div></div>
                    <div class="col-md-4 d-flex align-items-end"><div class="form-check form-switch"><input id="ds-status" v-model="form.status" class="form-check-input" type="checkbox"><label class="form-check-label" for="ds-status">{{ t('rides.driver_services.active') }}</label></div></div>
                </div>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="form = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" @click="save">{{ t('save') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// O-89: captain services — per country, which kind of vehicle works in which service; and the services themselves.
const { t, locale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();
const current = ref('matrix');
const services = ref([]);
const kinds = ref([]);
const icons = ref([]);
const countries = ref([]);
const countryId = ref(null);
const matrix = ref(null);
const saved = ref(false);
const busy = ref(false);
const form = ref(null);
const word = (v) => (v ? v[locale.value] || v.ar || v.en || '' : '');

const ICONS = {
    car: 'ri-car-line', timer: 'ri-timer-line', calendar: 'ri-calendar-event-line', group: 'ri-group-line', flight: 'ri-flight-takeoff-line',
    bus: 'ri-bus-line', boat: 'ri-sailboat-line', person: 'ri-user-search-line', school: 'ri-graduation-cap-line', package: 'ri-box-3-line',
    restaurant: 'ri-restaurant-line', moto: 'ri-motorbike-line', star: 'ri-star-line',
};
const iconClass = (i) => ICONS[i] || 'ri-apps-line';
const kindIcon = (k) => ({ car: 'ri-car-line', motorcycle: 'ri-motorbike-line', minibus: 'ri-bus-2-line', bus: 'ri-bus-line', boat: 'ri-sailboat-line', yacht: 'ri-ship-line', none: 'ri-user-line' }[k] || 'ri-question-line');

async function load() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/driver-services');
        services.value = data.data.services;
        kinds.value = data.data.kinds;
        icons.value = data.data.icons;
        countries.value = data.data.countries;
        if (countryId.value === null && countries.value.length) countryId.value = countries.value[0].id;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function loadMatrix() {
    if (!countryId.value) return;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/driver-service-rules', { params: { country_id: countryId.value } });
        matrix.value = data.data.matrix;
        saved.value = data.data.saved;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function saveMatrix() {
    busy.value = true;
    try {
        const { data } = await adminAxios.put('/api/admin/v1/driver-service-rules', { country_id: countryId.value, matrix: matrix.value });
        matrix.value = data.data.matrix;
        saved.value = data.data.saved;
        showSuccess(t('rides.driver_services.saved'));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = false;
    }
}

function open(s) {
    form.value = {
        id: s?.id ?? null, key: s?.key ?? '', name: { ar: s?.name?.ar ?? '', en: s?.name?.en ?? '' },
        description: { ar: s?.description?.ar ?? '', en: s?.description?.en ?? '' }, icon: s?.icon ?? 'car', color: s?.color ?? '#1E88E5',
        sort_order: s?.sort_order ?? (services.value.length ? Math.max(...services.value.map((x) => x.sort_order)) + 1 : 0),
        coming_soon: s?.coming_soon ?? false, status: s?.status ?? true,
    };
}

async function save() {
    const f = form.value;
    const body = { key: f.key, name: f.name, description: f.description, icon: f.icon, color: f.color, sort_order: f.sort_order, coming_soon: f.coming_soon, status: f.status };
    try {
        if (f.id) await adminAxios.put(`/api/admin/v1/driver-services/${f.id}`, body);
        else await adminAxios.post('/api/admin/v1/driver-services', body);
        form.value = null;
        await load();
        await loadMatrix();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function remove(s) {
    if (!window.confirm(t('rides.document_types.confirm_delete'))) return;
    try {
        await adminAxios.delete(`/api/admin/v1/driver-services/${s.id}`);
        await load();
        await loadMatrix();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

watch(countryId, loadMatrix);
onMounted(load);
</script>
