<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.reports.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.reports.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.reports.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <input v-model="from" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.reports.from')" />
                <input v-model="to" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.reports.to')" />
                <button type="button" class="btn btn-sm btn-primary btn-wave" :disabled="loading" @click="load">
                    <i class="ri-refresh-line me-1"></i>{{ t('freelance.reports.show') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.reports.country') }}</th>
                                <th v-for="c in columns" :key="c">{{ t(`freelance.reports.cols.${c}`) }}</th>
                                <th class="pe-4">{{ t('freelance.reports.cols.counts') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="3" :layout="['text', ...columns.map(() => 'text'), 'two-lines']" />
                            <tr v-else-if="!rows.length"><td :colspan="columns.length + 2" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.country.id">
                                <td class="ps-4 fw-semibold">{{ row.country.code }} <span class="text-muted fs-11">{{ row.currency }}</span></td>
                                <td v-for="c in columns" :key="c" dir="ltr">{{ money(row[`${c}_minor`]) }}</td>
                                <td class="pe-4 fs-12">
                                    <span class="d-block">{{ t('freelance.reports.counts.projects', { n: row.counts.projects }) }} · {{ t('freelance.reports.counts.active', { n: row.counts.agreements_active }) }}</span>
                                    <span class="text-muted">{{ t('freelance.reports.counts.completed', { n: row.counts.agreements_completed }) }} · {{ t('freelance.reports.counts.disputes', { n: row.counts.disputes_open }) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- The reconciliation: the same money counted from two places; a difference is shown, never fixed by itself. -->
        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-between py-3">
                <div class="card-title">
                    {{ t('freelance.reports.reconcile') }}
                    <span v-if="check" class="badge ms-2" :class="check.count ? 'bg-danger-transparent' : 'bg-success-transparent'">
                        {{ check.count ? t('freelance.reports.differences', { n: check.count }) : t('freelance.reports.all_match') }}
                    </span>
                </div>
                <button type="button" class="btn btn-sm btn-light btn-wave" :disabled="checking" @click="reconcile">
                    <i class="ri-scales-line me-1"></i>{{ t('freelance.reports.check') }}
                </button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.reports.issue') }}</th>
                                <th>{{ t('freelance.reports.where') }}</th>
                                <th>{{ t('freelance.reports.expected') }}</th>
                                <th class="pe-4">{{ t('freelance.reports.found') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="checking" :rows="3" :layout="['text', 'two-lines', 'text', 'text']" />
                            <tr v-else-if="!check?.issues?.length"><td colspan="4" class="text-center text-muted py-4">{{ t('freelance.reports.no_issues') }}</td></tr>
                            <tr v-for="(i, n) in check?.issues ?? []" v-else :key="n">
                                <td class="ps-4"><span class="badge bg-danger-transparent">{{ t(`freelance.reports.kinds.${i.kind}`) }}</span></td>
                                <td>
                                    <template v-if="i.project">
                                        <span class="d-block fw-semibold">{{ i.project.title }}</span>
                                        <span class="text-muted fs-11">{{ i.title }}</span>
                                    </template>
                                    <span v-else>{{ i.country }}</span>
                                </td>
                                <td dir="ltr">{{ i.missing ? i.missing.join(', ') : money(i.expected_minor) }}</td>
                                <td class="pe-4" dir="ltr">{{ i.missing ? '—' : money(i.found_minor) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';

// The project money (FL-512): per country, what is held now and what moved in the period; and the reconciliation — every difference
// between the parts, the wallet holds, the receipts, the ledger and the earnings, for a person to look at.
const { t } = useI18n();
const { showError } = useToast();
const endpoint = '/api/admin/v1/freelance-reports';
const columns = ['held', 'funded', 'paid', 'refunded', 'earned', 'pending_earnings', 'client_fees', 'commissions'];

const today = new Date();
const iso = (d) => d.toISOString().slice(0, 10);
const from = ref(iso(new Date(today.getFullYear(), today.getMonth(), 1)));
const to = ref(iso(today));
const rows = ref([]);
const loading = ref(false);
const check = ref(null);
const checking = ref(false);
const money = (minor) => (minor == null ? '—' : (minor / 100).toLocaleString('en', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/summary`, { params: { from: from.value || undefined, to: to.value || undefined } });
        rows.value = data.data?.countries ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function reconcile() {
    checking.value = true;
    try {
        const { data } = await adminAxios.get(`${endpoint}/reconcile`);
        check.value = data.data ?? null;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        checking.value = false;
    }
}

onMounted(() => {
    load();
    reconcile();
});
</script>
