<template>
    <div>
        <WalletPageHeader :title="t('chat.verification.requests.title')" :section="t('sidebar.chat')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm flex-wrap" role="group">
                    <button v-for="s in statusTabs" :key="s" type="button" class="btn" :class="filters.status === s ? 'btn-primary' : 'btn-outline-light text-default'" @click="filters.status = s">
                        {{ s === 'open' ? t('chat.verification.requests.open') : t(`chat.verification.statuses.${s}`) }}
                        <span v-if="s !== 'open' && counts[s]" class="badge bg-light text-default ms-1">{{ counts[s] }}</span>
                    </button>
                </div>
                <select v-model="filters.track" class="form-select form-select-sm ms-auto" style="max-width: 200px;">
                    <option value="">{{ t('chat.verification.documents.all_tracks') }}</option>
                    <option v-for="tr in ['paid', 'celebrity']" :key="tr" :value="tr">{{ t(`chat.verification.tracks.${tr}`) }}</option>
                </select>
                <div class="form-check form-switch mb-0">
                    <input id="vr-overdue" v-model="filters.overdue" class="form-check-input" type="checkbox" :true-value="1" :false-value="''">
                    <label class="form-check-label fs-13" for="vr-overdue">{{ t('chat.verification.requests.overdue_only') }}</label>
                </div>
                <input v-model="filters.search" type="search" class="form-control form-control-sm" style="max-width: 220px;" :placeholder="t('search_placeholder')">
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.verification.requests.account') }}</th>
                                <th>{{ t('chat.verification.requests.track') }}</th>
                                <th>{{ t('wallet.common.status') }}</th>
                                <th>{{ t('chat.verification.requests.deadline') }}</th>
                                <th>{{ t('chat.verification.requests.submitted') }}</th>
                                <th class="text-end pe-4"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <tr v-for="row in rows" v-else :key="row.uuid" role="button" @click="router.push({ name: 'admin.chat.verification.request', params: { uuid: row.uuid } })">
                                <td class="ps-4">
                                    <div class="fw-semibold"><UserName :user="row.user">{{ row.full_name }}</UserName></div>
                                    <div class="fs-11 text-muted" dir="ltr">{{ row.user?.phone }}</div>
                                </td>
                                <td><span class="badge" :class="row.track === 'celebrity' ? 'bg-warning-transparent' : 'bg-info-transparent'">{{ t(`chat.verification.tracks.${row.track}`) }}</span></td>
                                <td>
                                    <span class="badge" :class="statusClass(row.status)">{{ t(`chat.verification.statuses.${row.status}`) }}</span>
                                    <i v-if="row.identity_checked" class="ri-shield-check-line text-success ms-1" :title="t('chat.verification.requests.identity_checked')"></i>
                                </td>
                                <td class="fs-12">
                                    <span v-if="row.deadline_paused" class="text-muted"><i class="ri-pause-circle-line me-1"></i>{{ t('chat.verification.requests.paused') }}</span>
                                    <span v-else-if="row.deadline_at" :class="row.overdue ? 'text-danger fw-semibold' : ''">
                                        {{ fmtDate(row.deadline_at) }}
                                        <span class="text-muted">· {{ t(`chat.verification.requests.owner_${row.deadline_owner}`) }}</span>
                                    </span>
                                    <span v-else class="text-muted">—</span>
                                </td>
                                <td class="fs-12">{{ fmtDate(row.submitted_at) }}</td>
                                <td class="text-end pe-4"><i class="ri-arrow-right-s-line text-muted rtl-flip"></i></td>
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
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import UserName from '../../../../../../../../components/ui/UserName.vue';
import WalletPageHeader from '../../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../../components/wallet/WalletPagination.vue';
import useWalletList from '../../../../../../../../composables/useWalletList';
import { statusClass } from './status';

// Verification requests of both tracks (docs/verification_tasks.md AV-090): the nearest deadline first, overdue in red.
const { t } = useI18n();
const router = useRouter();

const statusTabs = ['open', 'new', 'under_review', 'needs_completion', 'suspended', 'preliminary_approved', 'completed', 'rejected', 'cancelled', 'expired'];
const { rows, loading, pagination, filters, fetch } = useWalletList('chat-verification/requests', { defaults: { status: 'open', track: '', overdue: '', search: '' } });
const counts = computed(() => pagination.value?.counts ?? {});
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) : '—');

onMounted(() => fetch(1));
</script>
