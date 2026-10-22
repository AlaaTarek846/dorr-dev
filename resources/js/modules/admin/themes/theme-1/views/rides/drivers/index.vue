<template>
    <div>
        <WalletPageHeader :title="t('rides.drivers.title')" :section="t('sidebar.rides')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 260px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('rides.drivers.search')">
                </div>
                <select v-model="filters.residency" class="form-select form-select-sm" style="max-width: 160px;">
                    <option value="">{{ t('rides.drivers.all_residencies') }}</option>
                    <option v-for="r in options.residencies" :key="r" :value="r">{{ t(`rides.residency.${r}`) }}</option>
                </select>
                <select v-model="filters.country_id" class="form-select form-select-sm" style="max-width: 180px;">
                    <option value="">{{ t('rides.drivers.all_countries') }}</option>
                    <option v-for="c in options.countries" :key="c.id" :value="c.id">{{ c.name }}</option>
                </select>
                <div class="form-check form-switch mb-0 ms-1">
                    <input id="pending-docs" v-model="filters.pending_documents" class="form-check-input" type="checkbox" :true-value="1" :false-value="''">
                    <label class="form-check-label fs-13" for="pending-docs">{{ t('rides.drivers.pending_documents') }}</label>
                </div>
                <button v-if="can('drivers.create')" type="button" class="btn btn-primary btn-sm btn-wave ms-auto" @click="openCreate">
                    <i class="ri-user-add-line me-1 align-middle"></i>{{ t('rides.drivers.add') }}
                </button>
            </div>
            <div class="px-3 pt-3">
                <div class="btn-group btn-group-sm flex-wrap" role="group">
                    <button v-for="tab in statusTabs" :key="tab.value" type="button" class="btn" :class="filters.status === tab.value ? 'btn-primary' : 'btn-outline-primary'" @click="filters.status = tab.value">{{ tab.label }}</button>
                </div>
            </div>
            <div class="card-body p-0 mt-3">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('rides.drivers.driver') }}</th>
                                <th>{{ t('rides.drivers.country') }}</th>
                                <th>{{ t('rides.drivers.residency') }}</th>
                                <th>{{ t('rides.drivers.vehicle') }}</th>
                                <th>{{ t('rides.drivers.status') }}</th>
                                <th>{{ t('rides.drivers.presence') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="7" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="7" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" @click="open(row)">
                                    <td class="ps-4">
                                        <div class="d-flex align-items-center gap-2">
                                            <span class="avatar avatar-md avatar-rounded bg-primary-transparent fw-semibold">
                                                <img v-if="row.avatar_url" :src="row.avatar_url" alt="">
                                                <template v-else>{{ initials(row.name) }}</template>
                                            </span>
                                            <div>
                                                <div class="fw-semibold">{{ row.name || '—' }}</div>
                                                <div class="fs-12 text-muted" dir="ltr">{{ row.phone }}</div>
                                            </div>
                                            <span v-if="row.pending_documents_count" class="badge bg-warning-transparent ms-1" :title="t('rides.drivers.pending_documents')">
                                                <i class="ri-file-warning-line me-1"></i>{{ row.pending_documents_count }}
                                            </span>
                                        </div>
                                    </td>
                                    <td>{{ row.country?.name || row.country?.code || '—' }}</td>
                                    <td><span class="badge" :class="row.residency === 'resident' ? 'bg-info-transparent' : 'bg-secondary-transparent'">{{ t(`rides.residency.${row.residency}`) }}</span></td>
                                    <td>
                                        <template v-if="row.active_vehicle">
                                            <span class="d-inline-block rounded-circle border me-1 align-middle" :style="{ width: '10px', height: '10px', background: row.active_vehicle.color_hex || '#ccc' }"></span>
                                            {{ row.active_vehicle.make }} {{ row.active_vehicle.model }}
                                            <div class="fs-12 text-muted">{{ row.active_vehicle.plate_letters }} {{ row.active_vehicle.plate_number }}</div>
                                        </template>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`rides.driver_status.${row.status}`) }}</span></td>
                                    <td>
                                        <span class="d-inline-flex align-items-center gap-1 fs-13">
                                            <span class="presence-dot" :class="`presence-dot--${row.presence}`"></span>{{ t(`rides.presence.${row.presence}`) }}
                                        </span>
                                    </td>
                                    <td class="text-end pe-4" @click.stop>
                                        <!-- O-04: edit right here, in the same modal as adding — like the rest of the dashboard. -->
                                        <button v-if="can('drivers.update')" type="button" class="btn btn-sm btn-icon btn-primary-light me-1" :title="t('rides.drivers.edit')" :disabled="editingId === row.id" @click="openEdit(row)">
                                            <span v-if="editingId === row.id" class="spinner-border spinner-border-sm"></span><i v-else class="ri-pencil-line"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-icon btn-light" :title="t('rides.drivers.details')" @click="open(row)"><i class="ri-eye-line"></i></button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <DriverFormModal :show="showCreate" :options="options" @close="showCreate = false" @saved="onCreated" />
        <DriverFormModal :show="editDriver !== null" :options="options" :driver="editDriver" @close="editDriver = null" @saved="onEdited" />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import DriverFormModal from './DriverFormModal.vue';

// Admin → Rides → Drivers: everyone who drives for DORR, citizens and residents, in every country.
const { t } = useI18n();
const { can } = usePermission();
const { showError } = useToast();
const router = useRouter();

const { rows, loading, pagination, filters, fetch } = useWalletList('drivers', {
    defaults: { search: '', status: '', residency: '', country_id: '', pending_documents: '' },
});
const options = reactive({ countries: [], cities: [], statuses: [], residencies: [], id_types: [], genders: [] });
const statusTabs = computed(() => [{ value: '', label: t('rides.drivers.all') }, ...options.statuses.map((s) => ({ value: s, label: t(`rides.driver_status.${s}`) }))]);
const showCreate = ref(false);
const editDriver = ref(null);
const editingId = ref(null);

const statusClass = (status) => ({
    active: 'bg-success-transparent',
    pending_documents: 'bg-warning-transparent',
    under_review: 'bg-info-transparent',
    suspended: 'bg-danger-transparent',
    restricted: 'bg-orange-transparent',
    blocked: 'bg-dark text-white',
}[status] ?? 'bg-light');

const initials = (name) => (name || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase();

function open(row) {
    router.push({ name: 'admin.rides.drivers.show', params: { id: row.id } });
}

function openCreate() {
    showCreate.value = true;
}

// The full driver (every field the modal edits), then the modal — the list rows carry only a summary.
async function openEdit(row) {
    editingId.value = row.id;
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/drivers/${row.id}`);
        editDriver.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        editingId.value = null;
    }
}

function onEdited() {
    editDriver.value = null;
    fetch(pagination.value?.current_page ?? 1);
}

function onCreated(driver) {
    showCreate.value = false;
    open(driver);
}

onMounted(async () => {
    fetch(1);
    try {
        const { data } = await adminAxios.get('/api/admin/v1/drivers/options');
        Object.assign(options, data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
});
</script>

<style scoped>
.presence-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #adb5bd;
}

.presence-dot--online {
    background: #26bf94;
    box-shadow: 0 0 0 3px rgba(38, 191, 148, 0.2);
}

.presence-dot--on_trip {
    background: #fa7552;
    box-shadow: 0 0 0 3px rgba(250, 117, 82, 0.2);
}

.presence-dot--paused {
    background: #f5b849;
}

[dir='rtl'] .rtl-flip {
    display: inline-block;
}

[dir='ltr'] .rtl-flip {
    transform: scaleX(-1);
    display: inline-block;
}
</style>
