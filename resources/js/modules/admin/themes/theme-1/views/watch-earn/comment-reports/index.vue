<template>
    <div>
        <WalletPageHeader :title="t('watch_earn.comment_reports.title')" :section="t('sidebar.watch_earn')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center gap-2 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="s in statuses" :key="s" type="button" class="btn" :class="filters.status === s ? 'btn-primary' : 'btn-outline-primary'" @click="filters.status = s">
                        {{ s ? t(`watch_earn.comment_reports.status_${s}`) : t('watch_earn.common.all') }}
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('watch_earn.comment_reports.comment') }}</th>
                                <th>{{ t('watch_earn.comment_reports.author') }}</th>
                                <th>{{ t('watch_earn.comment_reports.ad') }}</th>
                                <th>{{ t('watch_earn.comment_reports.reason') }}</th>
                                <th>{{ t('watch_earn.comment_reports.status') }}</th>
                                <th v-if="canUpdate"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="ps-4" style="max-width: 320px;">
                                        <div class="we-quote" :class="{ 'text-decoration-line-through text-muted': row.comment?.deleted }">{{ row.comment?.body }}</div>
                                        <div class="fs-11 text-muted">{{ formatDateTime(row.created_at) }}</div>
                                    </td>
                                    <td>
                                        <div>{{ row.comment?.author?.name || '—' }}</div>
                                        <div class="fs-12 text-muted" dir="ltr">{{ row.comment?.author?.phone }}</div>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 180px;">{{ row.ad?.title }}</div>
                                        <div class="fs-11 text-muted">{{ t('watch_earn.comment_reports.by') }} {{ row.reporter?.name }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger-transparent">{{ t(`watch_earn.comment_reports.reason_${row.reason}`) }}</span>
                                        <div v-if="row.note" class="fs-12 text-muted mt-1">{{ row.note }}</div>
                                    </td>
                                    <td>
                                        <span class="badge" :class="statusClass(row.status)">{{ t(`watch_earn.comment_reports.status_${row.status}`) }}</span>
                                        <div v-if="row.decision_note" class="fs-11 text-muted mt-1">{{ row.decision_note }}</div>
                                    </td>
                                    <td v-if="canUpdate" class="text-nowrap">
                                        <template v-if="row.status === 'pending'">
                                            <button type="button" class="btn btn-sm btn-danger me-1" :disabled="busy === row.id" @click="decide(row, 'removed')"><i class="ri-delete-bin-line me-1"></i>{{ t('watch_earn.comment_reports.remove') }}</button>
                                            <button type="button" class="btn btn-sm btn-light" :disabled="busy === row.id" @click="decide(row, 'kept')"><i class="ri-check-line me-1"></i>{{ t('watch_earn.comment_reports.keep') }}</button>
                                        </template>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const canUpdate = computed(() => can('watch-earn-ads.update'));
const { rows, loading, pagination, filters, fetch } = useWalletList('watch-earn/comment-reports', { defaults: { status: 'pending' } });
const statuses = ['pending', 'removed', 'kept', ''];
const busy = ref(null);

const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString(locale.value) : '—');
const statusClass = (s) => ({ pending: 'bg-warning-transparent', removed: 'bg-danger-transparent', kept: 'bg-success-transparent' }[s] || 'bg-light');

/** Both the advertiser and the comment's author get a push with the decision and this note. */
async function decide(row, action) {
    const note = window.prompt(t('watch_earn.comment_reports.note_prompt'));
    if (note === null) return;
    busy.value = row.id;
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/watch-earn/comment-reports/${row.id}/decide`, { action, note: note.trim() || null });
        Object.assign(row, data.data);
        showSuccess(t(`watch_earn.comment_reports.done_${action}`));
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = null;
    }
}

onMounted(() => fetch(1));
</script>

<style scoped>
.we-quote { white-space: pre-wrap; word-break: break-word; }
</style>
