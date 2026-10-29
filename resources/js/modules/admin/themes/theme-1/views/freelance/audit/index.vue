<template>
    <div>
        <WalletPageHeader :title="t('freelance.audit.title')" :section="t('sidebar.freelance')" :total="pagination.total ?? null" />

        <div class="alert alert-light border fs-13">{{ t('freelance.audit.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <Select v-model="area" :options="areaOptions" option-label="label" option-value="value" class="wallet-filter-select" />
                <input v-model="from" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.reports.from')">
                <input v-model="to" type="date" class="form-control form-control-sm w-auto" :aria-label="t('freelance.reports.to')">
                <button v-if="area !== 'all' || from || to" type="button" class="btn btn-sm btn-light border" @click="reset">
                    <i class="ri-close-line me-1"></i>{{ t('users.clear_search') }}
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.audit.action') }}</th>
                                <th>{{ t('freelance.audit.who') }}</th>
                                <th>{{ t('freelance.audit.on') }}</th>
                                <th>{{ t('freelance.audit.when') }}</th>
                                <th class="text-end pe-4">{{ t('freelance.audit.details') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['two-lines', 'text', 'text', 'text', 'actions']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="5" class="border-0">
                                    <FlEmpty icon="ri-history-line" :title="t('freelance.audit.empty_title')" :text="t('freelance.audit.empty')" />
                                </td>
                            </tr>
                            <template v-for="row in rows" v-else :key="row.id">
                                <tr>
                                    <td class="ps-4">
                                        <span class="fw-semibold d-block">{{ actionLabel(row.action) }}</span>
                                        <span class="text-muted fs-11" dir="ltr">{{ row.action }}</span>
                                    </td>
                                    <td>
                                        <span v-if="row.actor.type === 'admin'" class="d-block">{{ row.actor.name || `#${row.actor.id}` }}</span>
                                        <span v-else class="badge bg-secondary-transparent">{{ t('freelance.audit.system') }}</span>
                                        <span v-if="row.ip" class="text-muted fs-11" dir="ltr">{{ row.ip }}</span>
                                    </td>
                                    <td class="fs-12">
                                        <template v-if="row.subject.type">{{ row.subject.type }} #{{ row.subject.id }}</template>
                                        <span v-else class="text-muted">—</span>
                                    </td>
                                    <td class="fs-12">{{ formatCatalogDate(row.at, locale) }}</td>
                                    <td class="text-end pe-4">
                                        <button v-if="row.before || row.after" type="button" class="btn btn-sm btn-light btn-icon" :title="t('freelance.audit.details')" @click="toggle(row.id)">
                                            <i :class="open === row.id ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line'"></i>
                                        </button>
                                    </td>
                                </tr>
                                <tr v-if="open === row.id" class="table-light">
                                    <td colspan="5" class="px-4">
                                        <div class="row g-3">
                                            <div v-if="row.before" class="col-md-6">
                                                <div class="fs-12 fw-semibold mb-1">{{ t('freelance.audit.before') }}</div>
                                                <pre class="audit-json" dir="ltr">{{ pretty(row.before) }}</pre>
                                            </div>
                                            <div v-if="row.after" class="col-md-6">
                                                <div class="fs-12 fw-semibold mb-1">{{ t('freelance.audit.after') }}</div>
                                                <pre class="audit-json" dir="ltr">{{ pretty(row.after) }}</pre>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" :per-page="perPage" @change="page = $event" @update:per-page="perPage = $event" />
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../../api/adminAxios';
import FlEmpty from '../../../../../../../components/freelance/FlEmpty.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// What staff did in Freelance (FL-806): the audit log of the freelance actions, newest first, with who did it and the values before
// and after. Read only.
const { t, locale } = useI18n();
const { showError } = useToast();

// The start of the action names, one per area of the service.
const AREAS = ['freelance.dispute', 'freelance.message_report', 'freelance.review_objection', 'freelance.support', 'freelance.sanction', 'freelance_project', 'freelancer.', 'freelance_policy', 'freelance_feature_rule', 'freelance_document_type'];

const area = ref('all');
const from = ref('');
const to = ref('');
const page = ref(1);
const perPage = ref(30);
const rows = ref([]);
const pagination = ref({});
const loading = ref(false);
const open = ref(null);

const areaOptions = computed(() => [{ value: 'all', label: t('freelance.audit.all_areas') }, ...AREAS.map((a) => ({ value: a, label: t(`freelance.audit.areas.${a.replace(/\W+$/, '').replace(/\./g, '_')}`, a) }))]);
const actionLabel = (a) => t(`freelance.audit.actions.${a.replace(/\./g, '_')}`, a);
const pretty = (v) => JSON.stringify(v, null, 2);
const toggle = (id) => (open.value = open.value === id ? null : id);

function reset() {
    area.value = 'all';
    from.value = '';
    to.value = '';
}

watch([area, from, to, perPage], () => (page.value === 1 ? load() : (page.value = 1)));
watch(page, load);

async function load() {
    loading.value = true;
    open.value = null;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-audit', {
            params: { page: page.value, per_page: perPage.value, action: area.value === 'all' ? undefined : area.value, from: from.value || undefined, to: to.value || undefined },
        });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(load);
</script>

<style scoped>
.audit-json {
    background: var(--default-body-bg-color, #f6f7f9);
    border-radius: 0.5rem;
    font-size: 0.75rem;
    margin: 0;
    max-height: 220px;
    overflow: auto;
    padding: 0.75rem;
    white-space: pre-wrap;
}
</style>
