<template>
    <div>
        <WalletPageHeader :title="t('rides.safety.title')" :section="t('sidebar.rides')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm">
                    <button v-for="s in ['', 'open', 'acknowledged', 'resolved']" :key="s" type="button" class="btn" :class="filters.status === s ? 'btn-primary' : 'btn-outline-primary'" @click="filters.status = s">
                        {{ s ? t(`rides.safety.status.${s}`) : t('rides.drivers.all') }}
                    </button>
                </div>
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="fetch(1)"><i class="ri-refresh-line me-1"></i>{{ t('rides.safety.refresh') }}</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle fs-13">
                        <thead><tr><th class="ps-4">{{ t('rides.safety.when') }}</th><th>{{ t('rides.safety.who') }}</th><th>{{ t('rides.rides.ride') }}</th><th>{{ t('rides.safety.where') }}</th><th>{{ t('rides.safety.state') }}</th><th class="text-end pe-4"></th></tr></thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5"><i class="ri-shield-check-line fs-24 d-block mb-1 text-success"></i>{{ t('rides.safety.empty') }}</td></tr>
                            <tr v-for="i in rows" v-else :key="i.id" :class="{ 'sos-open': i.status === 'open' }">
                                <td class="ps-4">
                                    <span v-if="i.status === 'open'" class="sos-dot me-2"></span>
                                    {{ formatDate(i.created_at) }}
                                </td>
                                <td>
                                    <div class="fw-semibold mb-1" :class="i.type === 'sos' ? 'text-danger' : ''"><i class="me-1" :class="i.type === 'sos' ? 'ri-alarm-warning-line' : 'ri-flag-2-line'"></i>{{ i.type_label }}</div>
                                    <span class="badge me-1" :class="i.reporter_type === 'driver' ? 'bg-info-transparent' : 'bg-primary-transparent'">{{ t(`rides.safety.reporter.${i.reporter_type}`) }}</span>
                                    {{ i.reporter?.name || '—' }} <a v-if="i.reporter?.phone" :href="`tel:${i.reporter.phone}`" class="text-muted fs-12" dir="ltr">{{ i.reporter.phone }}</a>
                                </td>
                                <td>
                                    <router-link v-if="i.ride" :to="{ name: 'admin.rides.rides.show', params: { id: i.ride.id } }">{{ i.ride.number }}</router-link>
                                    <div class="fs-11 text-muted">{{ t(`rides.ride_status.${i.ride_status}`) }}</div>
                                </td>
                                <td>
                                    <a v-if="i.map_url" :href="i.map_url" target="_blank" rel="noopener"><i class="ri-map-pin-2-line me-1"></i>{{ t('rides.safety.open_map') }}</a>
                                    <span v-else class="text-muted">—</span>
                                    <div v-if="i.note" class="fs-11 text-muted text-wrap" style="max-width: 260px">{{ i.note }}</div>
                                </td>
                                <td>
                                    <span class="badge" :class="badge(i.status)">{{ t(`rides.safety.status.${i.status}`) }}</span>
                                    <div v-if="i.handled_by" class="fs-11 text-muted">{{ i.handled_by }}</div>
                                    <div v-if="i.resolution_note" class="fs-11 text-muted text-wrap" style="max-width: 220px">{{ i.resolution_note }}</div>
                                </td>
                                <td class="text-end pe-4">
                                    <template v-if="canUpdate">
                                        <button v-if="i.status === 'open'" type="button" class="btn btn-sm btn-warning me-1" :disabled="busy === i.id" @click="acknowledge(i)">{{ t('rides.safety.on_it') }}</button>
                                        <button v-if="i.status !== 'resolved'" type="button" class="btn btn-sm btn-success" :disabled="busy === i.id" @click="resolving = i; note = ''">{{ t('rides.safety.resolve') }}</button>
                                    </template>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="!!resolving" :title="t('rides.safety.resolve')" @close="resolving = null">
            <template v-if="resolving">
                <label class="form-label fs-13">{{ t('rides.safety.resolution_note') }}</label>
                <textarea v-model="note" class="form-control" rows="3" maxlength="1000"></textarea>
                <div class="text-end mt-3">
                    <button type="button" class="btn btn-success" :disabled="busy === resolving.id" @click="resolve">{{ t('rides.safety.resolve') }}</button>
                </div>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';

// Every SOS from a rider or a captain — open ones first, a map link to where it was pressed, the
// people to call, then "on it" and "resolved" with a note. Refreshes itself every 20 s.
const { t, locale } = useI18n();
const { showError, showSuccess } = useToast();
const { can } = usePermission();
const canUpdate = computed(() => can('ride-safety.update'));
const { rows, loading, pagination, filters, fetch } = useWalletList('ride-safety', { defaults: { status: '' }, perPage: 20 });
const busy = ref(null);
const resolving = ref(null);
const note = ref('');
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '');
const badge = (s) => ({ open: 'bg-danger', acknowledged: 'bg-warning-transparent', resolved: 'bg-success-transparent' })[s];

async function acknowledge(i) {
    busy.value = i.id;
    try {
        await adminAxios.post(`/api/admin/v1/ride-safety/${i.id}/acknowledge`);
        await fetch(pagination.value?.current_page || 1);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = null;
    }
}

async function resolve() {
    const i = resolving.value;
    busy.value = i.id;
    try {
        await adminAxios.post(`/api/admin/v1/ride-safety/${i.id}/resolve`, { note: note.value || null });
        showSuccess(t('rides.safety.resolved_ok'));
        resolving.value = null;
        await fetch(pagination.value?.current_page || 1);
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = null;
    }
}

let timer = null;
onMounted(() => {
    fetch(1);
    timer = setInterval(() => { if (!busy.value && !resolving.value) fetch(pagination.value?.current_page || 1); }, 20000);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<style scoped>
.sos-open { background: rgba(220, 53, 69, 0.06); }
.sos-dot { display: inline-block; width: 9px; height: 9px; border-radius: 50%; background: #dc3545; animation: sos-pulse 1.3s infinite; vertical-align: middle; }
@keyframes sos-pulse { 0% { box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.6); } 100% { box-shadow: 0 0 0 9px rgba(220, 53, 69, 0); } }
</style>
