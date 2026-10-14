<template>
    <div>
        <WalletPageHeader :title="driver?.name || t('rides.drivers.driver')" :section="t('rides.drivers.title')" />

        <div v-if="loading && !driver" class="text-center py-5"><span class="spinner-border"></span></div>

        <template v-else-if="driver">
            <!-- Hero -->
            <div class="card custom-card driver-hero mb-3">
                <div class="card-body d-flex flex-wrap align-items-center gap-3">
                    <span class="avatar avatar-xl avatar-rounded bg-primary-transparent fs-4 fw-semibold">
                        <img v-if="driver.avatar_url" :src="driver.avatar_url" alt="">
                        <template v-else>{{ initials }}</template>
                    </span>
                    <div class="flex-grow-1">
                        <h5 class="mb-1 fw-semibold">{{ driver.name }}</h5>
                        <div class="text-muted fs-13 mb-2" dir="ltr">{{ driver.phone }}</div>
                        <div class="d-flex flex-wrap gap-1">
                            <span class="badge" :class="statusClass(driver.status)">{{ t(`rides.driver_status.${driver.status}`) }}</span>
                            <span class="badge" :class="driver.residency === 'resident' ? 'bg-info-transparent' : 'bg-secondary-transparent'">{{ t(`rides.residency.${driver.residency}`) }}</span>
                            <span class="badge bg-light text-dark"><i class="ri-map-pin-line me-1"></i>{{ driver.country?.name || driver.country?.code }}</span>
                            <span class="badge bg-light text-dark">{{ t(`rides.registered_by.${driver.registered_by}`) }}</span>
                            <span class="badge bg-light text-dark"><span class="presence-dot me-1" :class="`presence-dot--${driver.presence}`"></span>{{ t(`rides.presence.${driver.presence}`) }}</span>
                        </div>
                        <div v-if="driver.status_reason" class="text-danger fs-12 mt-2"><i class="ri-information-line me-1"></i>{{ driver.status_reason }}</div>
                    </div>
                    <div v-if="canUpdate" class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-primary" @click="showEdit = true"><i class="ri-edit-line me-1"></i>{{ t('rides.drivers.edit') }}</button>
                        <button type="button" class="btn btn-sm btn-primary" @click="openStatus"><i class="ri-shield-user-line me-1"></i>{{ t('rides.drivers.change_status') }}</button>
                    </div>
                </div>
                <!-- Can this driver take rides right now, and if not, why. -->
                <div class="driver-hero__readiness" :class="regulator?.work_problem ? 'is-blocked' : 'is-ready'">
                    <i :class="regulator?.work_problem ? 'ri-close-circle-line' : 'ri-checkbox-circle-line'" class="me-2"></i>
                    <span v-if="regulator?.work_problem">{{ t('rides.drivers.cannot_work') }}: <strong>{{ problemText(regulator.work_problem) }}</strong></span>
                    <span v-else>{{ t('rides.drivers.can_work') }}</span>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-xl-4">
                    <WalletSection :title="t('rides.drivers.details')" icon="ri-user-3-line" class="mb-3">
                        <div class="row g-2">
                            <div v-for="tile in detailTiles" :key="tile.label" class="col-12">
                                <WalletInfoTile :label="tile.label" :icon="tile.icon">{{ tile.value || '—' }}</WalletInfoTile>
                            </div>
                        </div>
                    </WalletSection>

                    <!-- The regulator (Wasl) -->
                    <WalletSection :title="t('rides.wasl.title')" icon="ri-government-line">
                        <template v-if="regulator?.required" #actions>
                            <div v-if="canUpdate" class="d-flex gap-1">
                                <button type="button" class="btn btn-sm btn-light" :disabled="waslBusy" @click="wasl('check')"><i class="ri-refresh-line"></i></button>
                                <button type="button" class="btn btn-sm btn-primary" :disabled="waslBusy || !regulator.can_register" @click="wasl('register')">
                                    <span v-if="waslBusy" class="spinner-border spinner-border-sm me-1"></span>{{ t('rides.wasl.register') }}
                                </button>
                            </div>
                        </template>
                        <div v-if="!regulator" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>
                        <div v-else-if="!regulator.required" class="text-muted fs-13">{{ t('rides.wasl.not_required') }}</div>
                        <template v-else>
                            <div class="row g-2 mb-2">
                                <div class="col-6">
                                    <div class="wasl-chip" :class="`wasl-chip--${regulator.driver_status}`">
                                        <div class="fs-11 text-muted">{{ t('rides.wasl.driver') }}</div>
                                        <div class="fw-semibold">{{ waslWord(regulator.driver_status) }}</div>
                                        <div v-if="regulator.driver_expires_at" class="fs-11">{{ t('rides.wasl.until') }} {{ regulator.driver_expires_at }}</div>
                                    </div>
                                </div>
                                <div class="col-6">
                                    <div class="wasl-chip" :class="`wasl-chip--${regulator.vehicle_status}`">
                                        <div class="fs-11 text-muted">{{ t('rides.wasl.vehicle') }}</div>
                                        <div class="fw-semibold">{{ waslWord(regulator.vehicle_status) }}</div>
                                        <div v-if="regulator.vehicle_expires_at" class="fs-11">{{ t('rides.wasl.until') }} {{ regulator.vehicle_expires_at }}<span v-if="regulator.vehicle_expires_at_hijri" class="text-muted"> ({{ regulator.vehicle_expires_at_hijri }} هـ)</span></div>
                                    </div>
                                </div>
                            </div>
                            <div v-if="regulator.criminal_record_status" class="fs-12 mb-2"><i class="ri-file-shield-2-line me-1"></i>{{ t('rides.wasl.criminal_record') }}: <strong>{{ regulator.criminal_record_status }}</strong></div>
                            <div v-if="regulator.blocked_until" class="alert alert-danger py-2 fs-12 mb-2">{{ t('rides.wasl.blocked_until', { date: regulator.blocked_until }) }}</div>
                            <ul v-if="regulator.reasons.length" class="list-unstyled mb-2">
                                <li v-for="reason in regulator.reasons" :key="reason.about + reason.code" class="fs-12 mb-1 d-flex gap-2">
                                    <span class="badge bg-danger-transparent align-self-start">{{ t(`rides.wasl.about.${reason.about}`) }}</span>
                                    <span>{{ reason.message }} <code class="fs-11 text-muted">{{ reason.code }}</code></span>
                                </li>
                            </ul>
                            <div class="fs-11 text-muted">
                                {{ t('rides.wasl.last_checked') }}: {{ formatDateTime(regulator.last_checked_at) }} · {{ t('rides.wasl.attempts') }}: {{ regulator.registration_attempts }}
                            </div>
                        </template>
                    </WalletSection>
                </div>

                <div class="col-xl-8">
                    <!-- Vehicles -->
                    <WalletSection :title="t('rides.vehicles.title')" icon="ri-car-line" flush class="mb-3">
                        <template v-if="canUpdate" #actions>
                            <button type="button" class="btn btn-sm btn-primary" @click="openVehicle(null)"><i class="ri-add-line me-1"></i>{{ t('rides.vehicles.add') }}</button>
                        </template>
                        <div class="table-responsive">
                            <table class="table text-nowrap mb-0 align-middle">
                                <tbody>
                                    <tr v-if="!driver.vehicles.length"><td class="text-center text-muted py-4">{{ t('rides.vehicles.empty') }}</td></tr>
                                    <tr v-for="v in driver.vehicles" :key="v.id">
                                        <td class="ps-3">
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="vehicle-swatch" :style="{ background: v.color_hex || '#ccc' }"></span>
                                                <div>
                                                    <div class="fw-semibold">{{ v.make }} {{ v.model }} <span class="text-muted fw-normal">{{ v.year }}</span></div>
                                                    <div class="fs-12 text-muted">{{ v.color }} · {{ t(`rides.body_type.${v.body_type}`) }} · {{ v.seats }} {{ t('rides.vehicles.seats') }}</div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div class="plate"><span>{{ v.plate_letters }}</span><span dir="ltr">{{ v.plate_number }}</span></div>
                                            <div v-if="v.sequence_number" class="fs-11 text-muted mt-1">{{ t('rides.vehicles.sequence_number') }}: {{ v.sequence_number }}</div>
                                        </td>
                                        <td>
                                            <span class="badge" :class="vehicleClass(v.status)">{{ t(`rides.vehicle_status.${v.status}`) }}</span>
                                            <span v-if="v.is_active" class="badge bg-success ms-1"><i class="ri-steering-2-line me-1"></i>{{ t('rides.vehicles.active') }}</span>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div v-if="canUpdate" class="btn-group btn-group-sm">
                                                <button v-if="v.status !== 'approved'" type="button" class="btn btn-success-light" :title="t('rides.vehicles.approve')" @click="vehicleStatus(v, 'approved')"><i class="ri-check-line"></i></button>
                                                <button v-if="v.status === 'approved' && !v.is_active" type="button" class="btn btn-primary-light" :title="t('rides.vehicles.make_active')" @click="activate(v)"><i class="ri-steering-2-line"></i></button>
                                                <button v-if="v.status !== 'rejected'" type="button" class="btn btn-danger-light" :title="t('rides.vehicles.reject')" @click="vehicleStatus(v, 'rejected')"><i class="ri-close-line"></i></button>
                                                <button type="button" class="btn btn-light" :title="t('rides.drivers.edit')" @click="openVehicle(v)"><i class="ri-edit-line"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </WalletSection>

                    <!-- Documents -->
                    <WalletSection :title="t('rides.documents.title')" icon="ri-file-list-3-line" flush>
                        <template v-if="canUpdate" #actions>
                            <button type="button" class="btn btn-sm btn-primary" @click="openUpload"><i class="ri-upload-2-line me-1"></i>{{ t('rides.documents.upload') }}</button>
                        </template>
                        <div class="table-responsive">
                            <table class="table text-nowrap mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th class="ps-3">{{ t('rides.documents.type') }}</th>
                                        <th>{{ t('rides.documents.number') }}</th>
                                        <th>{{ t('rides.documents.expires_at') }}</th>
                                        <th>{{ t('rides.documents.files') }}</th>
                                        <th>{{ t('rides.drivers.status') }}</th>
                                        <th class="text-end pe-3"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-if="!driver.documents.length"><td colspan="6" class="text-center text-muted py-4">{{ t('rides.documents.empty') }}</td></tr>
                                    <tr v-for="d in driver.documents" :key="d.id" :class="{ 'opacity-50': d.status === 'replaced' }">
                                        <td class="ps-3">
                                            <div class="fw-semibold">{{ d.type.name || d.type.code }}</div>
                                            <div v-if="d.vehicle_id" class="fs-11 text-muted"><i class="ri-car-line me-1"></i>{{ vehicleName(d.vehicle_id) }}</div>
                                        </td>
                                        <td dir="ltr" class="text-start">{{ d.number || '—' }}</td>
                                        <td><span :class="{ 'text-danger fw-semibold': d.expired }">{{ d.expires_at || '—' }}</span></td>
                                        <td>
                                            <a v-if="d.front_url" :href="d.front_url" target="_blank" rel="noopener" class="btn btn-sm btn-light me-1"><i class="ri-image-line me-1"></i>{{ t('rides.documents.front') }}</a>
                                            <a v-if="d.back_url" :href="d.back_url" target="_blank" rel="noopener" class="btn btn-sm btn-light"><i class="ri-image-line me-1"></i>{{ t('rides.documents.back') }}</a>
                                        </td>
                                        <td>
                                            <span class="badge" :class="documentClass(d.status)">{{ t(`rides.document_status.${d.status}`) }}</span>
                                            <div v-if="d.rejection_reason" class="fs-11 text-danger mt-1 text-wrap" style="max-width: 200px">{{ d.rejection_reason }}</div>
                                        </td>
                                        <td class="text-end pe-3">
                                            <div v-if="canUpdate && d.status !== 'replaced'" class="btn-group btn-group-sm">
                                                <button v-if="d.status !== 'approved'" type="button" class="btn btn-success-light" :title="t('rides.documents.approve')" @click="review(d, true)"><i class="ri-check-line"></i></button>
                                                <button v-if="d.status !== 'rejected'" type="button" class="btn btn-danger-light" :title="t('rides.documents.reject')" @click="review(d, false)"><i class="ri-close-line"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </WalletSection>
                </div>
            </div>
        </template>

        <DriverFormModal :show="showEdit" :options="options" :driver="driver" @close="showEdit = false" @saved="onSaved" />

        <!-- Status -->
        <WalletModal :show="showStatus" :title="t('rides.drivers.change_status')" size="md" @close="showStatus = false">
            <label class="form-label">{{ t('rides.drivers.status') }}</label>
            <select v-model="statusForm.status" class="form-select mb-3">
                <option v-for="s in options.statuses" :key="s" :value="s">{{ t(`rides.driver_status.${s}`) }}</option>
            </select>
            <template v-if="statusForm.status !== 'active'">
                <label class="form-label">{{ t('rides.drivers.reason') }} <span class="text-danger">*</span></label>
                <textarea v-model="statusForm.reason" rows="3" maxlength="255" class="form-control" :placeholder="t('rides.drivers.reason_hint')"></textarea>
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showStatus = false">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy || (statusForm.status !== 'active' && !statusForm.reason)" @click="saveStatus">{{ t('save') }}</button>
            </template>
        </WalletModal>

        <!-- Vehicle -->
        <WalletModal :show="showVehicle" :title="vehicleForm.id ? t('rides.vehicles.edit') : t('rides.vehicles.add')" @close="showVehicle = false">
            <form id="vehicle-form" class="row g-3" @submit.prevent="saveVehicle">
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.make') }} *</label>
                    <select v-model="vehicleForm.make_id" class="form-select" @change="vehicleForm.model_id = null">
                        <option v-for="m in options.makes" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.model') }} *</label>
                    <select v-model="vehicleForm.model_id" class="form-select" @change="pickModel">
                        <option v-for="m in models" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.year') }} *</label>
                    <input v-model.number="vehicleForm.year" type="number" min="1980" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.color') }} *</label>
                    <select v-model="vehicleForm.color_id" class="form-select">
                        <option v-for="c in options.colors" :key="c.id" :value="c.id">{{ c.name }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.body_type') }} *</label>
                    <select v-model="vehicleForm.body_type" class="form-select">
                        <option v-for="b in options.body_types" :key="b" :value="b">{{ t(`rides.body_type.${b}`) }}</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.seats') }} *</label>
                    <input v-model.number="vehicleForm.seats" type="number" min="1" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.plate_letters') }} *</label>
                    <input v-model="vehicleForm.plate_letters" type="text" maxlength="8" class="form-control" :placeholder="t('rides.vehicles.plate_letters_hint')">
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.plate_number') }} *</label>
                    <input v-model="vehicleForm.plate_number" type="text" maxlength="8" dir="ltr" class="form-control" :class="{ 'is-invalid': vehicleErrors.plate_number }">
                    <div class="invalid-feedback">{{ vehicleErrors.plate_number }}</div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">{{ t('rides.vehicles.plate_type') }} *</label>
                    <input v-model.number="vehicleForm.plate_type" type="number" min="0" class="form-control">
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.vehicles.sequence_number') }}</label>
                    <input v-model="vehicleForm.sequence_number" type="text" maxlength="16" dir="ltr" class="form-control" :class="{ 'is-invalid': vehicleErrors.sequence_number }">
                    <div class="invalid-feedback">{{ vehicleErrors.sequence_number }}</div>
                </div>
                <div class="col-md-6">
                    <label class="form-label">{{ t('rides.vehicles.features') }}</label>
                    <div class="d-flex flex-wrap gap-2">
                        <label v-for="f in options.vehicle_features" :key="f" class="form-check-label border rounded px-2 py-1 fs-12">
                            <input v-model="vehicleForm.features" type="checkbox" :value="f" class="form-check-input me-1">{{ t(`rides.vehicle_feature.${f}`) }}
                        </label>
                    </div>
                </div>
                <div v-if="vehicleErrors.general" class="col-12 text-danger fs-13">{{ vehicleErrors.general }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showVehicle = false">{{ t('cancel') }}</button>
                <button type="submit" form="vehicle-form" class="btn btn-primary" :disabled="busy">{{ t('save') }}</button>
            </template>
        </WalletModal>

        <!-- Upload a document -->
        <WalletModal :show="showUpload" :title="t('rides.documents.upload')" size="md" @close="showUpload = false">
            <form id="document-form" @submit.prevent="upload">
                <label class="form-label">{{ t('rides.documents.type') }} *</label>
                <select v-model="uploadForm.document_type_id" class="form-select mb-3">
                    <option v-for="d in options.document_types" :key="d.id" :value="d.id">{{ d.name || d.code }}</option>
                </select>
                <template v-if="uploadType?.applies_to === 'vehicle'">
                    <label class="form-label">{{ t('rides.drivers.vehicle') }} *</label>
                    <select v-model="uploadForm.vehicle_id" class="form-select mb-3">
                        <option v-for="v in driver?.vehicles ?? []" :key="v.id" :value="v.id">{{ v.make }} {{ v.model }} — {{ v.plate_letters }} {{ v.plate_number }}</option>
                    </select>
                </template>
                <div class="row g-3 mb-3">
                    <div v-if="uploadType?.has_number" class="col-6">
                        <label class="form-label">{{ t('rides.documents.number') }} *</label>
                        <input v-model="uploadForm.number" type="text" maxlength="64" dir="ltr" class="form-control">
                    </div>
                    <div v-if="uploadType?.has_expiry" class="col-6">
                        <label class="form-label">{{ t('rides.documents.expires_at') }} *</label>
                        <input v-model="uploadForm.expires_at" type="date" class="form-control">
                    </div>
                </div>
                <label class="form-label">{{ t('rides.documents.front') }} *</label>
                <input type="file" accept="image/*,application/pdf" class="form-control mb-3" @change="uploadForm.front = $event.target.files[0]">
                <template v-if="uploadType?.needs_back_side">
                    <label class="form-label">{{ t('rides.documents.back') }} *</label>
                    <input type="file" accept="image/*,application/pdf" class="form-control mb-3" @change="uploadForm.back = $event.target.files[0]">
                </template>
                <div class="form-check form-switch">
                    <input id="doc-approve" v-model="uploadForm.approve" class="form-check-input" type="checkbox">
                    <label class="form-check-label" for="doc-approve">{{ t('rides.documents.approve_now') }}</label>
                </div>
                <div v-if="uploadError" class="text-danger fs-13 mt-2">{{ uploadError }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" @click="showUpload = false">{{ t('cancel') }}</button>
                <button type="submit" form="document-form" class="btn btn-primary" :disabled="busy || !uploadForm.front">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('rides.documents.upload') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletInfoTile from '../../../../../../../components/wallet/WalletInfoTile.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import DriverFormModal from './DriverFormModal.vue';

// A driver's page: who they are, whether they can work now (and why not), Wasl, vehicles, documents.
const { t, te, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();
const route = useRoute();
const id = route.params.id;

const canUpdate = computed(() => can('drivers.update'));
const driver = ref(null);
const regulator = ref(null);
const loading = ref(false);
const busy = ref(false);
const waslBusy = ref(false);
const options = reactive({ countries: [], cities: [], statuses: [], residencies: [], id_types: [], genders: [], makes: [], colors: [], body_types: [], vehicle_features: [], document_types: [] });

const initials = computed(() => (driver.value?.name || '?').trim().split(/\s+/).slice(0, 2).map((p) => p[0]).join('').toUpperCase());
const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString(locale.value) : '—');
const detailTiles = computed(() => {
    const d = driver.value;
    const nationality = options.countries.find((c) => c.id === d.nationality_country_id)?.name;
    const city = options.cities.find((c) => c.id === d.city_id)?.name;

    return [
        { label: t('rides.drivers.national_id'), icon: 'ri-profile-line', value: d.national_id ? `${d.national_id}${d.id_type ? ` · ${t(`rides.id_type.${d.id_type}`)}` : ''}` : '' },
        { label: t('rides.drivers.date_of_birth'), icon: 'ri-cake-2-line', value: d.date_of_birth },
        { label: t('rides.drivers.nationality'), icon: 'ri-earth-line', value: nationality },
        { label: t('rides.drivers.city'), icon: 'ri-building-line', value: city },
        { label: t('rides.drivers.gender'), icon: 'ri-user-line', value: d.gender ? t(`rides.gender.${d.gender}`) : '' },
        { label: t('rides.drivers.email'), icon: 'ri-mail-line', value: d.email },
        { label: t('rides.drivers.name_from_wasl'), icon: 'ri-government-line', value: [d.name_ar, d.name_en].filter(Boolean).join(' / ') },
        { label: t('rides.drivers.activated_at'), icon: 'ri-calendar-check-line', value: d.activated_at ? formatDateTime(d.activated_at) : '' },
    ];
});

const statusClass = (s) => ({ active: 'bg-success-transparent', pending_documents: 'bg-warning-transparent', under_review: 'bg-info-transparent', suspended: 'bg-danger-transparent', restricted: 'bg-orange-transparent', blocked: 'bg-dark text-white' }[s] ?? 'bg-light');
const vehicleClass = (s) => ({ approved: 'bg-success-transparent', pending: 'bg-warning-transparent', rejected: 'bg-danger-transparent', inactive: 'bg-secondary-transparent' }[s] ?? 'bg-light');
const documentClass = (s) => ({ approved: 'bg-success-transparent', pending: 'bg-warning-transparent', rejected: 'bg-danger-transparent', expired: 'bg-danger-transparent', replaced: 'bg-light text-muted' }[s] ?? 'bg-light');
const vehicleName = (vehicleId) => {
    const v = driver.value?.vehicles.find((x) => x.id === vehicleId);
    return v ? `${v.make ?? ''} ${v.plate_letters} ${v.plate_number}` : `#${vehicleId}`;
};
const waslWord = (word) => (te(`rides.wasl.status.${word}`) ? t(`rides.wasl.status.${word}`) : word);
const problemText = (code) => (te(`rides.work_problem.${code}`) ? t(`rides.work_problem.${code}`) : code);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/drivers/${id}`);
        driver.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        loading.value = false;
    }
    loadRegulator();
}

async function loadRegulator() {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/drivers/${id}/regulator`);
        regulator.value = data.data;
    } catch (error) {
        regulator.value = { required: false, work_problem: null };
    }
}

async function act(request, message) {
    busy.value = true;
    try {
        const { data } = await request();
        driver.value = data.data;
        showSuccess(message ?? t('rides.drivers.saved'));
        loadRegulator();

        return true;
    } catch (error) {
        showError(extractApiErrorMessage(error));

        return false;
    } finally {
        busy.value = false;
    }
}

function onSaved(saved) {
    showEdit.value = false;
    driver.value = saved;
    loadRegulator();
}

// --- status
const showEdit = ref(false);
const showStatus = ref(false);
const statusForm = reactive({ status: 'active', reason: '' });
function openStatus() {
    Object.assign(statusForm, { status: driver.value.status, reason: driver.value.status_reason ?? '' });
    showStatus.value = true;
}
async function saveStatus() {
    if (await act(() => adminAxios.patch(`/api/admin/v1/drivers/${id}/status`, { ...statusForm, reason: statusForm.reason || null }))) {
        showStatus.value = false;
    }
}

// --- Wasl
async function wasl(action) {
    waslBusy.value = true;
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/drivers/${id}/regulator/${action}`);
        regulator.value = data.data;
        showSuccess(t('rides.wasl.done'));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        waslBusy.value = false;
    }
}

// --- vehicles
const showVehicle = ref(false);
const vehicleErrors = reactive({});
const emptyVehicle = () => ({ id: null, make_id: options.makes[0]?.id ?? null, model_id: null, color_id: options.colors[0]?.id ?? null, year: new Date().getFullYear(), body_type: 'sedan', seats: 4, plate_letters: '', plate_number: '', plate_type: 1, sequence_number: '', features: [] });
const vehicleForm = reactive(emptyVehicle());
const models = computed(() => options.makes.find((m) => m.id === vehicleForm.make_id)?.models ?? []);
function pickModel() {
    const model = models.value.find((m) => m.id === vehicleForm.model_id);
    if (model) {
        Object.assign(vehicleForm, { body_type: model.body_type ?? vehicleForm.body_type, seats: model.seats ?? vehicleForm.seats });
    }
}
function openVehicle(v) {
    Object.keys(vehicleErrors).forEach((k) => delete vehicleErrors[k]);
    Object.assign(vehicleForm, emptyVehicle(), v ? { ...v, sequence_number: v.sequence_number ?? '', features: [...(v.features ?? [])] } : {});
    showVehicle.value = true;
}
async function saveVehicle() {
    Object.keys(vehicleErrors).forEach((k) => delete vehicleErrors[k]);
    busy.value = true;
    const payload = { ...vehicleForm, sequence_number: vehicleForm.sequence_number || null };
    try {
        const { data } = vehicleForm.id
            ? await adminAxios.put(`/api/admin/v1/drivers/${id}/vehicles/${vehicleForm.id}`, payload)
            : await adminAxios.post(`/api/admin/v1/drivers/${id}/vehicles`, payload);
        driver.value = data.data;
        showVehicle.value = false;
        showSuccess(t('rides.drivers.saved'));
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        Object.entries(bag).forEach(([key, messages]) => { vehicleErrors[key] ??= messages[0]; });
        vehicleErrors.general = Object.keys(bag).length ? Object.values(bag)[0][0] : extractApiErrorMessage(error);
    } finally {
        busy.value = false;
    }
}
const vehicleStatus = (v, status) => act(() => adminAxios.patch(`/api/admin/v1/drivers/${id}/vehicles/${v.id}/status`, { status }));
const activate = (v) => act(() => adminAxios.post(`/api/admin/v1/drivers/${id}/vehicles/${v.id}/activate`));

// --- documents
const showUpload = ref(false);
const uploadError = ref('');
const uploadForm = reactive({ document_type_id: null, vehicle_id: null, number: '', expires_at: '', front: null, back: null, approve: true });
const uploadType = computed(() => options.document_types.find((d) => d.id === uploadForm.document_type_id));
function openUpload() {
    uploadError.value = '';
    Object.assign(uploadForm, { document_type_id: options.document_types[0]?.id ?? null, vehicle_id: driver.value.vehicles.find((v) => v.is_active)?.id ?? driver.value.vehicles[0]?.id ?? null, number: '', expires_at: '', front: null, back: null, approve: true });
    showUpload.value = true;
}
async function upload() {
    uploadError.value = '';
    const body = new FormData();
    Object.entries(uploadForm).forEach(([key, value]) => {
        if (key === 'approve') {
            body.append(key, value ? '1' : '0');
        } else if (value !== null && value !== '' && !(key === 'vehicle_id' && uploadType.value?.applies_to !== 'vehicle')) {
            body.append(key, value);
        }
    });
    busy.value = true;
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/drivers/${id}/documents`, body);
        driver.value = data.data;
        showUpload.value = false;
        showSuccess(t('rides.documents.uploaded'));
        loadRegulator();
    } catch (error) {
        const bag = error?.response?.data?.errors ?? {};
        uploadError.value = Object.keys(bag).length ? Object.values(bag)[0][0] : extractApiErrorMessage(error);
    } finally {
        busy.value = false;
    }
}
async function review(d, approve) {
    let reason = null;
    if (!approve) {
        reason = window.prompt(t('rides.documents.reject_reason'));
        if (!reason) {
            return;
        }
    }
    await act(() => adminAxios.patch(`/api/admin/v1/drivers/${id}/documents/${d.id}/review`, { approve, reason }));
}

onMounted(async () => {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/drivers/options');
        Object.assign(options, data.data ?? {});
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
    load();
});
</script>

<style scoped>
.driver-hero {
    overflow: hidden;
}

.driver-hero__readiness {
    padding: 0.6rem 1.25rem;
    font-size: 0.85rem;
    border-top: 1px solid var(--default-border, #e9edf2);
}

.driver-hero__readiness.is-ready {
    background: rgba(38, 191, 148, 0.08);
    color: #1a8f6e;
}

.driver-hero__readiness.is-blocked {
    background: rgba(230, 83, 60, 0.07);
    color: #c0392b;
}

.presence-dot {
    display: inline-block;
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: #adb5bd;
}

.presence-dot--online {
    background: #26bf94;
}

.presence-dot--on_trip {
    background: #fa7552;
}

.presence-dot--paused {
    background: #f5b849;
}

.wasl-chip {
    padding: 0.55rem 0.7rem;
    border-radius: 0.6rem;
    border: 1px solid var(--default-border, #e9edf2);
    background: #f8f9fb;
}

.wasl-chip--valid {
    border-color: rgba(38, 191, 148, 0.4);
    background: rgba(38, 191, 148, 0.07);
}

.wasl-chip--invalid,
.wasl-chip--rejected,
.wasl-chip--expired {
    border-color: rgba(230, 83, 60, 0.35);
    background: rgba(230, 83, 60, 0.06);
}

.wasl-chip--pending {
    border-color: rgba(245, 184, 73, 0.45);
    background: rgba(245, 184, 73, 0.08);
}

.vehicle-swatch {
    width: 2rem;
    height: 2rem;
    border-radius: 0.5rem;
    border: 1px solid rgba(0, 0, 0, 0.1);
    flex-shrink: 0;
}

/* A plate drawn like the real one: letters | numbers. */
.plate {
    display: inline-flex;
    border: 2px solid #1f2937;
    border-radius: 0.35rem;
    overflow: hidden;
    font-weight: 700;
    font-size: 0.8rem;
    letter-spacing: 0.08em;
}

.plate span {
    padding: 0.1rem 0.45rem;
    background: #fff;
    color: #111827;
}

.plate span + span {
    border-inline-start: 2px solid #1f2937;
}
</style>
