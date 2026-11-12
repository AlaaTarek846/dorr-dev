<template>
    <div>
        <WalletPageHeader :title="est?.name || t('moving.establishments.title')" :section="t('sidebar.moving')" />

        <div v-if="loading" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></div>

        <div v-else-if="!est" class="card custom-card">
            <div class="card-body text-center text-muted py-5">
                <i class="ri-store-2-line fs-24 d-block mb-1"></i>{{ t('wallet.common.empty') }}
                <div class="mt-3"><router-link :to="{ name: 'admin.moving.establishments' }" class="btn btn-sm btn-light">{{ t('moving.establishments.back') }}</router-link></div>
            </div>
        </div>

        <template v-else-if="est">
            <!-- The establishment and the decision on it. -->
            <div class="card custom-card">
                <div class="card-body d-flex flex-wrap gap-3 align-items-center">
                    <div class="flex-grow-1">
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <h5 class="mb-0">{{ est.name }}</h5>
                            <span class="badge bg-light text-dark">{{ t(`moving.establishment_type.${est.type}`) }}</span>
                            <span class="badge" :class="statusClass(est.status)">{{ t(`moving.establishment_status.${est.status}`) }}</span>
                        </div>
                        <div class="fs-13 text-muted">
                            {{ owner?.name || '—' }} · <span dir="ltr">{{ owner?.phone }}</span>
                            <template v-if="est.commercial_register_no"> · {{ t('moving.establishments.commercial_register') }}: {{ est.commercial_register_no }}</template>
                        </div>
                        <div v-if="est.status_reason" class="fs-13 text-danger mt-1">{{ est.status_reason }}</div>
                        <div class="mt-2">
                            <span v-for="s in est.services" :key="s.id" class="badge bg-primary-transparent me-1">{{ s.name }}</span>
                        </div>
                    </div>
                    <div v-if="can('provider-establishments.update')" class="d-flex gap-2">
                        <button v-if="est.status !== 'approved'" type="button" class="btn btn-success btn-sm" :disabled="busy" @click="decide('approved')"><i class="ri-check-line me-1"></i>{{ t('moving.establishments.approve') }}</button>
                        <button v-if="est.status === 'under_review'" type="button" class="btn btn-danger-light btn-sm" :disabled="busy" @click="decide('rejected')"><i class="ri-close-line me-1"></i>{{ t('moving.establishments.reject') }}</button>
                        <button v-if="est.status === 'approved'" type="button" class="btn btn-danger-light btn-sm" :disabled="busy" @click="decide('suspended')"><i class="ri-pause-circle-line me-1"></i>{{ t('moving.establishments.suspend') }}</button>
                    </div>
                </div>
            </div>

            <div class="row">
                <!-- The establishment's own papers. -->
                <div class="col-xl-6">
                    <div class="card custom-card">
                        <div class="card-header"><h6 class="mb-0">{{ t('moving.establishments.documents') }}</h6></div>
                        <div class="card-body">
                            <DocumentRows :documents="documents" :can-review="can('provider-establishments.update')" @review="reviewDocument" />
                        </div>
                    </div>
                </div>
                <!-- Branches. -->
                <div class="col-xl-6">
                    <div class="card custom-card">
                        <div class="card-header"><h6 class="mb-0">{{ t('moving.establishments.branches') }}</h6></div>
                        <div class="card-body p-0">
                            <div v-if="!branches.length" class="text-muted fs-13 p-3">{{ t('wallet.common.empty') }}</div>
                            <table v-else class="table mb-0 fs-13">
                                <tbody>
                                    <tr v-for="b in branches" :key="b.uuid">
                                        <td class="ps-3">{{ b.name }} <span v-if="b.is_main" class="badge bg-light text-dark ms-1">{{ t('moving.establishments.main_branch') }}</span></td>
                                        <td class="text-muted">{{ b.address || `${b.lat}, ${b.lng}` }}</td>
                                        <td class="text-end pe-3">{{ b.radius_km ? `${b.radius_km} ${t('moving.common.km')}` : '—' }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Vehicles with their photos and papers. -->
            <div class="card custom-card">
                <div class="card-header"><h6 class="mb-0">{{ t('moving.establishments.vehicles') }}</h6></div>
                <div class="card-body">
                    <div v-if="!vehicles.length" class="text-muted fs-13">{{ t('wallet.common.empty') }}</div>
                    <div v-for="v in vehicles" :key="v.uuid" class="border rounded-3 p-3 mb-3">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <i class="ri-truck-line fs-18 text-primary"></i>
                            <b>{{ v.vehicle_type?.name }}</b>
                            <span class="badge bg-dark" dir="ltr">{{ [v.plate_letters, v.plate_number].filter(Boolean).join(' ') }}</span>
                            <span class="text-muted fs-12">{{ [v.make, v.model, v.color, v.year].filter(Boolean).join(' · ') }}</span>
                            <span class="badge" :class="statusClass(v.status === 'pending' ? 'under_review' : v.status)">{{ t(`moving.vehicle_status.${v.status}`) }}</span>
                            <div v-if="can('provider-establishments.update')" class="ms-auto d-flex gap-2">
                                <button v-if="v.status !== 'approved'" type="button" class="btn btn-success btn-sm" :disabled="busy" @click="reviewVehicle(v, 'approve')">{{ t('moving.establishments.approve') }}</button>
                                <button v-if="v.status !== 'rejected'" type="button" class="btn btn-danger-light btn-sm" :disabled="busy" @click="reviewVehicle(v, 'reject')">{{ t('moving.establishments.reject') }}</button>
                            </div>
                        </div>
                        <div v-if="v.status_reason" class="fs-12 text-danger mb-2">{{ v.status_reason }}</div>
                        <div v-if="v.missing?.length" class="fs-12 text-warning mb-2"><i class="ri-error-warning-line me-1"></i>{{ t('moving.establishments.missing') }}: {{ v.missing.join(listSeparator) }}</div>
                        <DocumentRows :documents="v.documents" :can-review="can('provider-establishments.update')" @review="reviewDocument" />
                    </div>
                </div>
            </div>

            <!-- The team, and each driver's licence. -->
            <div class="card custom-card">
                <div class="card-header"><h6 class="mb-0">{{ t('moving.establishments.team') }}</h6></div>
                <div class="card-body">
                    <div v-if="!members.length" class="text-muted fs-13">{{ t('wallet.common.empty') }}</div>
                    <div v-for="m in members" :key="m.uuid" class="border rounded-3 p-3 mb-3">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <b>{{ m.person?.name || m.phone }}</b>
                            <span class="text-muted fs-12" dir="ltr">{{ m.person?.phone || m.phone }}</span>
                            <span v-if="m.is_owner" class="badge bg-primary-transparent">{{ t('moving.establishments.owner') }}</span>
                            <span v-if="m.is_exclusive" class="badge bg-warning-transparent">{{ t('moving.establishments.exclusive') }}</span>
                            <span v-if="m.status === 'invited'" class="badge bg-light text-dark">{{ t('moving.establishments.invited') }}</span>
                        </div>
                        <div class="fs-12 text-muted mb-2">{{ m.is_owner ? t('moving.establishments.all_permissions') : m.permissions.join(' · ') || '—' }}</div>
                        <DocumentRows v-if="m.documents.length" :documents="m.documents" :can-review="can('provider-establishments.update')" @review="reviewDocument" />
                    </div>
                </div>
            </div>
        </template>

        <!-- A rejection or a suspension always carries a reason; the provider sees it. -->
        <WalletModal :show="!!pending" :title="pending?.title ?? ''" size="md" @close="pending = null">
            <form v-if="pending" id="moving-reason-form" @submit.prevent="submitReason">
                <label class="form-label" for="moving-reason">{{ t('moving.establishments.reason') }} <span class="text-danger">*</span></label>
                <textarea id="moving-reason" v-model="pending.reason" rows="3" maxlength="500" class="form-control" :class="{ 'is-invalid': reasonError }" @input="reasonError = ''"></textarea>
                <div v-if="reasonError" class="invalid-feedback d-block">{{ reasonError }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="pending = null">{{ t('cancel') }}</button>
                <button type="submit" form="moving-reason-form" class="btn btn-danger" :disabled="busy">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ pending?.title }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// One establishment under review (docs/moving_tasks.md A-11): approve, reject or suspend it, and approve or reject
// each vehicle and each paper or photo — like the captains' page. A rejection always asks for the reason.
const { t, locale } = useI18n();
const route = useRoute();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const data = ref(null);
const loading = ref(true);
const est = computed(() => data.value?.establishment ?? null);
const owner = computed(() => data.value?.owner ?? null);
const branches = computed(() => data.value?.branches ?? []);
const documents = computed(() => data.value?.documents ?? []);
const vehicles = computed(() => data.value?.vehicles ?? []);
const members = computed(() => data.value?.members ?? []);
const base = computed(() => `/api/admin/v1/provider-establishments/${route.params.uuid}`);
const listSeparator = computed(() => (locale.value === 'ar' ? '، ' : ', '));
const busy = ref(false);
// The decision waiting for its reason: { title, url, body, reason }.
const pending = ref(null);
const reasonError = ref('');

const statusClass = (status) => ({
    approved: 'bg-success-transparent', under_review: 'bg-warning-transparent', pending: 'bg-warning-transparent', rejected: 'bg-danger-transparent',
    suspended: 'bg-danger-transparent', expired: 'bg-danger-transparent', inactive: 'bg-light text-dark', draft: 'bg-light text-dark',
}[status] ?? 'bg-light text-dark');

// A paper or a vehicle photo: a thumbnail (or a link for a PDF), its number and expiry, its status, and the decision.
const DocumentRows = defineComponent({
    props: { documents: { type: Array, default: () => [] }, canReview: Boolean },
    emits: ['review'],
    setup(props, { emit }) {
        const thumb = (url) => url && !url.toLowerCase().endsWith('.pdf')
            ? h('a', { href: url, target: '_blank', rel: 'noopener noreferrer' }, [h('img', { src: url, alt: '', class: 'rounded border', style: 'width:64px;height:48px;object-fit:cover' })])
            : url ? h('a', { href: url, target: '_blank', rel: 'noopener noreferrer', class: 'btn btn-sm btn-light' }, [h('i', { class: 'ri-file-pdf-line' })]) : null;

        return () => props.documents.length === 0
            ? h('div', { class: 'text-muted fs-12' }, t('moving.establishments.no_documents'))
            : h('div', { class: 'd-flex flex-column gap-2' }, props.documents.map((d) => h('div', { key: d.uuid, class: 'd-flex flex-wrap align-items-center gap-2 border-top pt-2' }, [
                thumb(d.front), thumb(d.back),
                h('div', { class: 'fs-13 flex-grow-1' }, [
                    h('div', { class: 'fw-semibold' }, d.name || d.code),
                    h('div', { class: 'text-muted fs-12' }, [d.number, d.expires_on && `${t('moving.establishments.expires')} ${d.expires_on}`].filter(Boolean).join(' · ')),
                    d.rejection_reason ? h('div', { class: 'text-danger fs-12' }, d.rejection_reason) : null,
                ]),
                h('span', { class: `badge ${statusClass(d.status)}` }, t(`moving.document_status.${d.status}`)),
                props.canReview && d.status !== 'approved' && d.status !== 'replaced'
                    ? h('button', { type: 'button', class: 'btn btn-success btn-sm', disabled: busy.value, onClick: () => emit('review', d, 'approve') }, t('moving.establishments.approve')) : null,
                props.canReview && d.status === 'pending'
                    ? h('button', { type: 'button', class: 'btn btn-danger-light btn-sm', disabled: busy.value, onClick: () => emit('review', d, 'reject') }, t('moving.establishments.reject')) : null,
            ])));
    },
});

/** Sends one decision; a 422 on the reason is shown under the field when the reason dialog is open. */
async function send(url, body) {
    busy.value = true;
    try {
        await adminAxios.patch(url, body);
        pending.value = null;
        showSuccess(t('moving.common.saved'));
        load();
    } catch (e) {
        const message = e?.response?.data?.errors?.reason?.[0];
        if (message && pending.value) {
            reasonError.value = message;
        } else {
            showError(extractApiErrorMessage(e));
        }
    } finally {
        busy.value = false;
    }
}

/** An approval goes straight away; a rejection or a suspension opens the reason dialog first. */
function ask(title, url, body) {
    reasonError.value = '';
    pending.value = { title, url, body, reason: '' };
}

function submitReason() {
    const reason = pending.value.reason.trim();
    if (!reason) {
        reasonError.value = t('moving.establishments.reason_required');
        return;
    }
    send(pending.value.url, { ...pending.value.body, reason });
}

function decide(status) {
    const url = `${base.value}/status`;
    if (status === 'approved') {
        send(url, { status, reason: null });
        return;
    }
    ask(t(`moving.establishments.${status === 'rejected' ? 'reject' : 'suspend'}`), url, { status });
}

function reviewVehicle(vehicle, decision) {
    const url = `${base.value}/vehicles/${vehicle.uuid}/review`;
    if (decision === 'approve') {
        send(url, { decision, reason: null });
        return;
    }
    ask(t('moving.establishments.reject'), url, { decision });
}

function reviewDocument(document, decision) {
    const url = `${base.value}/documents/${document.uuid}/review`;
    if (decision === 'approve') {
        send(url, { decision, reason: null });
        return;
    }
    ask(t('moving.establishments.reject'), url, { decision });
}

async function load() {
    try {
        const { data: res } = await adminAxios.get(base.value);
        data.value = res.data;
    } catch (e) {
        showError(extractApiErrorMessage(e));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>
