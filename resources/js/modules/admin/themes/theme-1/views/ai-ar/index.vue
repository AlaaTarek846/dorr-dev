<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_ar.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_ar.subtitle') }}</span>
            </div>
            <nav>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                    <li class="breadcrumb-item active" aria-current="page">{{ t('ai_ar.title') }}</li>
                </ol>
            </nav>
        </div>

        <ul class="nav nav-tabs mb-3">
            <li v-for="tab in tabs" :key="tab" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: current === tab }" @click="open(tab)">{{ t('ai_ar.tabs.' + tab) }}</button>
            </li>
        </ul>

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm me-2"></span>{{ t('ai_ar.loading') }}</div>

        <!-- Settings -->
        <div v-else-if="current === 'settings' && settings" class="card custom-card"><div class="card-body row g-3">
            <div class="col-12 form-check form-switch ms-2">
                <input id="ar-enabled" v-model="settings.is_enabled" class="form-check-input" type="checkbox">
                <label for="ar-enabled" class="form-check-label">{{ t('ai_ar.enabled') }}</label>
            </div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_ar.max_asset_mb') }}</label><input v-model.number="settings.max_asset_mb" type="number" min="1" max="100" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_ar.max_triangles') }}</label><input v-model.number="settings.max_triangles" type="number" min="1000" max="2000000" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_ar.capture_retention_days') }}</label><input v-model.number="settings.capture_retention_days" type="number" min="1" max="365" class="form-control"></div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_ar.commission_percent') }}</label><input v-model.number="settings.commission_percent" type="number" min="0" max="50" step="0.5" class="form-control"><small class="text-muted">{{ t('ai_ar.commission_hint') }}</small></div>
            <div class="col-md-4"><label class="form-label">{{ t('ai_ar.escrow_release_days') }}</label><input v-model.number="settings.escrow_release_days" type="number" min="1" max="30" class="form-control"><small class="text-muted">{{ t('ai_ar.escrow_hint') }}</small></div>
            <div class="col-12"><button class="btn btn-primary" :disabled="saving" @click="saveSettings">{{ t('ai_ar.save') }}</button></div>
        </div></div>

        <!-- Model library -->
        <div v-else-if="current === 'library'">
            <div class="card custom-card"><div class="card-body row g-3">
                <p class="fw-semibold mb-0">{{ t('ai_ar.add_model') }}</p>
                <div class="col-md-3"><input v-model="form.name_en" class="form-control" :placeholder="t('ai_ar.name_en')"></div>
                <div class="col-md-3"><input v-model="form.name_ar" class="form-control" :placeholder="t('ai_ar.name_ar')"></div>
                <div class="col-md-2"><input v-model="form.category" class="form-control" :placeholder="t('ai_ar.category')"></div>
                <div class="col-md-2">
                    <select v-model="form.license" class="form-select">
                        <option value="">{{ t('ai_ar.no_license') }}</option>
                        <option v-for="l in licenses" :key="l" :value="l">{{ l }}</option>
                    </select>
                </div>
                <div class="col-md-2"><input ref="fileInput" type="file" accept=".glb" class="form-control"></div>
                <div class="col-12"><button class="btn btn-primary" :disabled="saving" @click="addModel">{{ t('ai_ar.upload') }}</button></div>
            </div></div>

            <div class="card custom-card"><div class="card-body"><div class="table-responsive">
                <table class="table text-nowrap align-middle">
                    <thead><tr>
                        <th>{{ t('ai_ar.name_en') }}</th><th>{{ t('ai_ar.category') }}</th><th>{{ t('ai_ar.license') }}</th>
                        <th>{{ t('ai_ar.status') }}</th><th>{{ t('ai_ar.versions') }}</th><th>{{ t('ai_ar.public') }}</th><th></th>
                    </tr></thead>
                    <tbody>
                        <tr v-for="a in assets" :key="a.id">
                            <td>{{ a.name_en }}<div class="text-muted fs-12">{{ a.name_ar }}</div></td>
                            <td>{{ a.category }}</td>
                            <td>
                                <select :value="a.license || ''" class="form-select form-select-sm" @change="setLicense(a, $event.target.value)">
                                    <option value="">-</option>
                                    <option v-for="l in licenses" :key="l" :value="l">{{ l }}</option>
                                </select>
                            </td>
                            <td><span class="badge bg-light text-dark">{{ a.status }}</span></td>
                            <td>
                                <select :value="a.current_version_id || ''" class="form-select form-select-sm" @change="activate(a, $event.target.value)">
                                    <option value="" disabled>-</option>
                                    <option v-for="v in (a.versions || [])" :key="v.id" :value="v.id">v{{ v.version_no }}</option>
                                </select>
                            </td>
                            <td>
                                <div class="form-check form-switch"><input class="form-check-input" type="checkbox" :checked="a.is_public" @change="publish(a, $event.target.checked)"></div>
                            </td>
                            <td><button class="btn btn-sm btn-outline-danger" @click="reject(a)">{{ t('ai_ar.reject') }}</button></td>
                        </tr>
                        <tr v-if="! assets.length"><td colspan="7" class="text-center text-muted">{{ t('ai_ar.empty') }}</td></tr>
                    </tbody>
                </table>
            </div></div></div>
        </div>

        <!-- Design reviews -->
        <div v-else-if="current === 'reviews'" class="card custom-card"><div class="card-body"><div class="table-responsive">
            <table class="table text-nowrap align-middle">
                <thead><tr><th>{{ t('ai_ar.design') }}</th><th>{{ t('ai_ar.provider') }}</th><th>{{ t('ai_ar.status') }}</th><th>{{ t('ai_ar.customer_note') }}</th><th>{{ t('ai_ar.provider_note') }}</th><th>{{ t('ai_ar.date') }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in reviews" :key="r.id">
                        <td>{{ r.title }}<div class="text-muted fs-12">{{ r.type }}</div></td>
                        <td>#{{ r.provider_id }}</td>
                        <td><span class="badge bg-light text-dark">{{ t('ai_ar.review_status.' + r.status) }}</span></td>
                        <td class="text-wrap">{{ r.customer_note || '-' }}</td>
                        <td class="text-wrap">{{ r.provider_note || '-' }}</td>
                        <td>{{ (r.created_at || '').slice(0, 10) }}</td>
                    </tr>
                    <tr v-if="! reviews.length"><td colspan="6" class="text-center text-muted">{{ t('ai_ar.empty') }}</td></tr>
                </tbody>
            </table>
        </div></div></div>

        <!-- Paid orders (escrow) -->
        <div v-else-if="current === 'orders'">
            <div class="mb-3"><select v-model="orderFilter" class="form-select w-auto" @change="open('orders')">
                <option value="">{{ t('ai_ar.all') }}</option>
                <option v-for="x in orderStatuses" :key="x" :value="x">{{ t('ai_ar.order_status.' + x) }}</option>
            </select></div>
            <div class="card custom-card"><div class="card-body"><div class="table-responsive">
                <table class="table text-nowrap align-middle">
                    <thead><tr><th>{{ t('ai_ar.design') }}</th><th>{{ t('ai_ar.amount') }}</th><th>{{ t('ai_ar.commission') }}</th><th>{{ t('ai_ar.status') }}</th><th>{{ t('ai_ar.dispute_reason') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="o in orders" :key="o.id">
                            <td>{{ o.title }}<div class="text-muted fs-12">#{{ o.id }} · {{ t('ai_ar.provider') }} #{{ o.provider_id }}</div></td>
                            <td>{{ money(o.amount_minor) }} {{ o.currency }}</td>
                            <td>{{ money(o.commission_minor) }} ({{ o.commission_percent }}%)</td>
                            <td><span class="badge bg-light text-dark">{{ t('ai_ar.order_status.' + o.status) }}</span><div v-if="o.resolution_note" class="text-muted fs-12 text-wrap">{{ o.resolution_note }}</div></td>
                            <td class="text-wrap">{{ o.dispute_reason || '-' }}</td>
                            <td>
                                <div v-if="o.status === 'disputed'" class="d-flex gap-2 align-items-center">
                                    <input v-model="notes[o.id]" class="form-control form-control-sm" maxlength="500" :placeholder="t('ai_ar.resolution_note')">
                                    <button class="btn btn-sm btn-success" :disabled="saving" @click="resolve(o, 'release')">{{ t('ai_ar.release') }}</button>
                                    <button class="btn btn-sm btn-outline-danger" :disabled="saving" @click="resolve(o, 'refund')">{{ t('ai_ar.refund') }}</button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="! orders.length"><td colspan="6" class="text-center text-muted">{{ t('ai_ar.empty') }}</td></tr>
                    </tbody>
                </table>
            </div></div></div>
        </div>

        <!-- Tested devices -->
        <div v-else-if="current === 'devices'">
            <div class="card custom-card"><div class="card-body row g-3">
                <p class="fw-semibold mb-0">{{ t('ai_ar.add_device') }}</p>
                <div class="col-md-3"><input v-model="device.manufacturer" class="form-control" :placeholder="t('ai_ar.manufacturer')"></div>
                <div class="col-md-3"><input v-model="device.model_pattern" class="form-control" :placeholder="t('ai_ar.model_pattern')"></div>
                <div class="col-md-2">
                    <select v-model="device.tier" class="form-select"><option v-for="x in tiers" :key="x" :value="x">{{ t('ai_ar.tier.' + x) }}</option></select>
                </div>
                <div class="col-md-2 form-check form-switch ms-2 pt-2">
                    <input id="ar-dev-supported" v-model="device.arcore_supported" class="form-check-input" type="checkbox">
                    <label for="ar-dev-supported" class="form-check-label">ARCore</label>
                </div>
                <div class="col-12"><button class="btn btn-primary" :disabled="saving" @click="addDevice">{{ t('ai_ar.save') }}</button></div>
            </div></div>
            <div class="card custom-card"><div class="card-body"><div class="table-responsive">
                <table class="table text-nowrap align-middle">
                    <thead><tr><th>{{ t('ai_ar.manufacturer') }}</th><th>{{ t('ai_ar.model_pattern') }}</th><th>ARCore</th><th>{{ t('ai_ar.tier_label') }}</th><th></th></tr></thead>
                    <tbody>
                        <tr v-for="d in devices" :key="d.id">
                            <td>{{ d.manufacturer }}</td><td>{{ d.model_pattern }}</td><td>{{ d.arcore_supported ? '✓' : '✗' }}</td>
                            <td>{{ t('ai_ar.tier.' + d.tier) }}</td>
                            <td><button class="btn btn-sm btn-outline-danger" @click="removeDevice(d)">{{ t('ai_ar.delete') }}</button></td>
                        </tr>
                        <tr v-if="! devices.length"><td colspan="5" class="text-center text-muted">{{ t('ai_ar.empty') }}</td></tr>
                    </tbody>
                </table>
            </div></div></div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showSuccess, showError } = useToast();
const base = '/api/admin/v1/ai-ar';

const tabs = ['settings', 'library', 'reviews', 'orders', 'devices'];
const orderFilter = ref('disputed');
const orders = ref([]);
const notes = ref({});
const tiers = ['full', 'basic', 'fallback_only'];
const licenses = ['own', 'cc0', 'cc_by', 'purchased', 'generated'];

const current = ref('settings');
const loading = ref(true);
const saving = ref(false);
const settings = ref(null);
const assets = ref([]);
const devices = ref([]);
const reviews = ref([]);
const fileInput = ref(null);
const form = ref({ name_en: '', name_ar: '', category: 'furniture', license: '' });
const orderStatuses = ['paid', 'delivered', 'disputed', 'released', 'refunded'];
const money = (m) => (Number(m || 0) / 100).toFixed(2);
const device = ref({ manufacturer: '', model_pattern: '', tier: 'basic', arcore_supported: true });

async function run(call) {
    try { return await call(); } catch (error) { showError(extractApiErrorMessage(error, t('toast.error'))); return null; }
}

async function save(call) {
    saving.value = true;
    const res = await run(call);
    saving.value = false;
    if (res) showSuccess(extractApiMessage(res, t('toast.success')));
    return res;
}

async function open(tab) {
    current.value = tab;
    loading.value = true;
    if (tab === 'settings') settings.value = (await run(() => adminAxios.get(`${base}/settings`)))?.data.data ?? settings.value;
    if (tab === 'library') assets.value = (await run(() => adminAxios.get(`${base}/assets`)))?.data.data ?? [];
    if (tab === 'reviews') reviews.value = (await run(() => adminAxios.get(`${base}/reviews`)))?.data.data ?? [];
    if (tab === 'orders') orders.value = (await run(() => adminAxios.get(`${base}/orders`, { params: orderFilter.value ? { status: orderFilter.value } : {} })))?.data.data ?? [];
    if (tab === 'devices') devices.value = (await run(() => adminAxios.get(`${base}/devices`)))?.data.data ?? [];
    loading.value = false;
}

const saveSettings = () => save(() => adminAxios.put(`${base}/settings`, {
    is_enabled: !! settings.value.is_enabled,
    max_asset_mb: settings.value.max_asset_mb,
    max_triangles: settings.value.max_triangles,
    capture_retention_days: settings.value.capture_retention_days,
    commission_percent: settings.value.commission_percent,
    escrow_release_days: settings.value.escrow_release_days,
}));

async function addModel() {
    const file = fileInput.value?.files?.[0];
    if (! file || ! form.value.name_en) { showError(t('ai_ar.need_file')); return; }
    const body = new FormData();
    body.append('name_en', form.value.name_en);
    if (form.value.name_ar) body.append('name_ar', form.value.name_ar);
    body.append('category', form.value.category || 'furniture');
    if (form.value.license) body.append('license', form.value.license);
    body.append('file', file);
    if (await save(() => adminAxios.post(`${base}/assets`, body))) {
        form.value.name_en = ''; form.value.name_ar = '';
        if (fileInput.value) fileInput.value.value = '';
        await open('library');
    }
}

async function setLicense(asset, value) {
    if (await save(() => adminAxios.put(`${base}/assets/${asset.id}`, { license: value || null }))) await open('library');
}
async function activate(asset, versionId) {
    if (await save(() => adminAxios.post(`${base}/assets/${asset.id}/activate`, { version_id: Number(versionId) }))) await open('library');
    else await open('library');
}
async function publish(asset, isPublic) {
    await save(() => adminAxios.post(`${base}/assets/${asset.id}/publish`, { is_public: isPublic }));
    await open('library');
}
async function reject(asset) {
    if (await save(() => adminAxios.post(`${base}/assets/${asset.id}/reject`))) await open('library');
}

async function resolve(o, resolution) {
    const note = (notes.value[o.id] || '').trim();
    if (note.length < 3) { showError(t('ai_ar.need_note')); return; }
    if (await save(() => adminAxios.post(`${base}/orders/${o.id}/resolve`, { resolution, note }))) await open('orders');
}

async function addDevice() {
    if (! device.value.manufacturer || ! device.value.model_pattern) return;
    if (await save(() => adminAxios.post(`${base}/devices`, { ...device.value }))) {
        device.value.manufacturer = ''; device.value.model_pattern = '';
        await open('devices');
    }
}
async function removeDevice(d) {
    if (await save(() => adminAxios.delete(`${base}/devices/${d.id}`))) await open('devices');
}

onMounted(() => open('settings'));
</script>
