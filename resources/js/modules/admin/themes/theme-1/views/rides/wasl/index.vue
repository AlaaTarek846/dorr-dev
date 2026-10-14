<template>
    <div>
        <WalletPageHeader :title="t('rides.wasl_logs.title')" :section="t('sidebar.rides')" :total="pagination?.total ?? null" />

        <div class="d-flex gap-2 mb-3">
            <router-link :to="{ name: 'admin.rides.rides', query: { wasl_status: 'failed' } }" class="btn btn-sm btn-outline-danger"><i class="ri-error-warning-line me-1"></i>{{ t('rides.wasl_logs.failed_trips') }}</router-link>
            <router-link :to="{ name: 'admin.rides.rides', query: { wasl_status: 'pending' } }" class="btn btn-sm btn-outline-warning"><i class="ri-time-line me-1"></i>{{ t('rides.wasl_logs.pending_trips') }}</router-link>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm">
                    <button v-for="tp in ['', 'registration', 'eligibility', 'trip', 'locations']" :key="tp" type="button" class="btn" :class="filters.type === tp ? 'btn-primary' : 'btn-outline-primary'" @click="filters.type = tp">
                        {{ tp ? t(`rides.wasl_logs.types.${tp}`) : t('rides.drivers.all') }}
                    </button>
                </div>
                <select v-model="filters.success" class="form-select form-select-sm ms-auto" style="max-width: 160px;">
                    <option value="">{{ t('rides.wasl_logs.any_result') }}</option>
                    <option :value="1">{{ t('rides.wasl_logs.ok') }}</option>
                    <option :value="0">{{ t('rides.wasl_logs.failed') }}</option>
                </select>
                <input v-model="filters.result_code" type="search" class="form-control form-control-sm" style="max-width: 200px;" :placeholder="t('rides.wasl_logs.result_code')" dir="ltr">
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle fs-13">
                        <thead><tr><th class="ps-4">#</th><th>{{ t('rides.wasl_logs.type') }}</th><th>{{ t('rides.wasl_logs.subject') }}</th><th>HTTP</th><th>{{ t('rides.wasl_logs.result') }}</th><th>{{ t('rides.wasl_logs.time') }}</th></tr></thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="l in rows" v-else :key="l.id" role="button" @click="open(l)">
                                <td class="ps-4 text-muted">{{ l.id }}</td>
                                <td>{{ t(`rides.wasl_logs.types.${l.type}`) }}</td>
                                <td>{{ l.subject || '—' }}</td>
                                <td>{{ l.status_code ?? '—' }}</td>
                                <td><span class="badge" :class="l.success ? 'bg-success-transparent' : 'bg-danger-transparent'">{{ l.success ? 'OK' : (l.result_code || '—') }}</span></td>
                                <td class="text-muted">{{ formatDate(l.created_at) }} <span v-if="l.duration_ms" class="fs-11">· {{ l.duration_ms }}ms</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <WalletModal :show="!!detail" :title="detail ? `${t(`rides.wasl_logs.types.${detail.type}`)} #${detail.id}` : ''" @close="detail = null">
            <template v-if="detail">
                <h6 class="fs-13">{{ t('rides.wasl_logs.request') }}</h6>
                <pre class="bg-light p-2 rounded fs-12" dir="ltr">{{ JSON.stringify(detail.request, null, 2) }}</pre>
                <h6 class="fs-13">{{ t('rides.wasl_logs.response') }}</h6>
                <pre class="bg-light p-2 rounded fs-12 mb-0" dir="ltr">{{ JSON.stringify(detail.response, null, 2) }}</pre>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';

// Every call to Wasl and its answer (Jawad's logs screen), plus shortcuts to the trips still waiting.
const { t, locale } = useI18n();
const { showError } = useToast();
const { rows, loading, pagination, filters, fetch } = useWalletList('wasl-logs', { defaults: { type: '', success: '', result_code: '' }, perPage: 20 });
const detail = ref(null);
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'medium' }) : '');

async function open(l) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/wasl-logs/${l.id}`);
        detail.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

onMounted(() => fetch(1));
</script>
