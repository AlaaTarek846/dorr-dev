<template>
    <div>
        <WalletPageHeader :title="t('rides.cases.title')" :section="t('sidebar.rides')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm">
                    <button v-for="o in [{ k: '1', l: 'open' }, { k: '', l: 'all' }]" :key="o.l" type="button" class="btn" :class="filters.open === o.k ? 'btn-primary' : 'btn-outline-primary'" @click="filters.open = o.k">
                        {{ t(`rides.cases.filter_${o.l}`) }}
                    </button>
                </div>
                <select v-model="filters.kind" class="form-select form-select-sm w-auto">
                    <option value="">{{ t('rides.cases.all_kinds') }}</option>
                    <option v-for="k in ['complaint', 'dispute', 'unpaid_report']" :key="k" :value="k">{{ t(`rides.cases.kinds.${k}`) }}</option>
                </select>
                <input v-model="filters.search" type="search" class="form-control form-control-sm w-auto" :placeholder="t('rides.cases.search')" @keyup.enter="fetch(1)">
                <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" @click="fetch(1)"><i class="ri-refresh-line me-1"></i>{{ t('rides.safety.refresh') }}</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle fs-13">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('rides.cases.number') }}</th><th>{{ t('rides.cases.kind') }}</th><th>{{ t('rides.cases.reporter') }}</th>
                                <th>{{ t('rides.rides.ride') }}</th><th>{{ t('rides.cases.amount') }}</th><th>{{ t('rides.cases.priority') }}</th><th>{{ t('rides.safety.state') }}</th><th>{{ t('rides.safety.when') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="8" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="8" class="text-center text-muted py-5"><i class="ri-customer-service-2-line fs-24 d-block mb-1"></i>{{ t('rides.cases.empty') }}</td></tr>
                            <tr v-for="c in rows" v-else :key="c.id" class="cursor-pointer" @click="open(c)">
                                <td class="ps-4 fw-semibold">{{ c.number }}</td>
                                <td>{{ c.kind_label }}<div class="fs-11 text-muted">{{ c.category_label }}</div></td>
                                <td>
                                    <span class="badge me-1" :class="c.reporter?.type === 'driver' ? 'bg-info-transparent' : 'bg-primary-transparent'">{{ t(`rides.safety.reporter.${c.reporter?.type}`) }}</span>
                                    {{ c.reporter?.name || '—' }}
                                </td>
                                <td>{{ c.ride?.number || '—' }}</td>
                                <td>{{ c.disputed_minor != null ? money(c) : '—' }}</td>
                                <td><span class="badge" :class="priorityClass(c.priority)">{{ t(`rides.cases.priorities.${c.priority}`) }}</span></td>
                                <td><span class="badge" :class="statusClass(c.status)">{{ c.status_label }}</span><div v-if="c.assigned_to" class="fs-11 text-muted">{{ c.assigned_to }}</div></td>
                                <td>{{ formatDate(c.created_at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>
    </div>
</template>

<script setup>
import { onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useWalletList from '../../../../../../../composables/useWalletList';

// The team's queue of complaints, disputes and unpaid-ride reports: urgent first, then the oldest.
// It reloads when asked (the button / Enter in the search), not on a timer.
const { t, locale } = useI18n();
const router = useRouter();
const { rows, loading, pagination, filters, fetch } = useWalletList('ride-cases', { defaults: { open: '1', kind: '', search: '' }, perPage: 20 });
const formatDate = (iso) => (iso ? new Date(iso).toLocaleString(locale.value, { dateStyle: 'short', timeStyle: 'short' }) : '');
const money = (c) => `${((c.disputed_minor ?? 0) / 10 ** (c.currency?.decimal_places ?? 2)).toFixed(c.currency?.decimal_places ?? 2)} ${c.currency?.symbol ?? ''}`;
const priorityClass = (p) => ({ urgent: 'bg-danger', high: 'bg-warning-transparent', normal: 'bg-light text-dark' })[p];
const statusClass = (s) => ({ submitted: 'bg-primary-transparent', in_review: 'bg-info-transparent', needs_info: 'bg-warning-transparent', confirmed: 'bg-success-transparent', partially_confirmed: 'bg-success-transparent', rejected: 'bg-secondary-transparent' })[s];
const open = (c) => router.push({ name: 'admin.rides.cases.show', params: { id: c.id } });

onMounted(() => fetch(1));
</script>
