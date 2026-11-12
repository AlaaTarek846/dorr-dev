<template>
    <div>
        <WalletPageHeader :title="t('watch_earn.comment_reports.title')" :section="t('sidebar.watch_earn')" :total="pagination?.total ?? null" />

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="s in statuses" :key="s" type="button" class="btn" :class="filters.status === s ? 'btn-primary' : 'btn-outline-primary'" @click="filters.status = s">
                        {{ s ? t(`watch_earn.comment_reports.status_${s}`) : t('watch_earn.common.all') }}
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('watch_earn.comment_reports.comment') }}</th>
                                <th>{{ t('watch_earn.comment_reports.author') }}</th>
                                <th>{{ t('watch_earn.comment_reports.ad') }}</th>
                                <th>{{ t('watch_earn.comment_reports.reason') }}</th>
                                <th>{{ t('watch_earn.comment_reports.status') }}</th>
                                <th v-if="canUpdate" class="text-end pe-4">{{ t('wallet.common.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="6" :layout="['two-lines', 'two-lines', 'two-lines', 'badge', 'badge', ...(canUpdate ? ['actions'] : [])]" />
                            <tr v-else-if="!rows.length">
                                <td :colspan="canUpdate ? 6 : 5" class="text-center text-muted py-5"><i class="ri-chat-delete-line fs-24 d-block mb-1"></i>{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id">
                                    <td class="ps-4" style="max-width: 320px;">
                                        <div class="we-quote" :class="{ 'text-decoration-line-through text-muted': row.comment?.deleted }">{{ row.comment?.body }}</div>
                                        <div class="fs-11 text-muted">{{ formatDateTime(row.created_at) }}</div>
                                    </td>
                                    <td class="text-nowrap">
                                        <div>{{ row.comment?.author?.name || '—' }}</div>
                                        <div class="fs-12 text-muted" dir="ltr">{{ row.comment?.author?.phone }}</div>
                                    </td>
                                    <td>
                                        <div class="text-truncate" style="max-width: 180px;">{{ row.ad?.title }}</div>
                                        <div class="fs-11 text-muted">{{ t('watch_earn.comment_reports.by') }} {{ row.reporter?.name || '—' }}</div>
                                    </td>
                                    <td>
                                        <span class="badge bg-danger-transparent">{{ t(`watch_earn.comment_reports.reason_${row.reason}`) }}</span>
                                        <div v-if="row.note" class="fs-12 text-muted mt-1">{{ row.note }}</div>
                                    </td>
                                    <td>
                                        <span class="badge" :class="statusClass(row.status)">{{ t(`watch_earn.comment_reports.status_${row.status}`) }}</span>
                                        <div v-if="row.decision_note" class="fs-11 text-muted mt-1">{{ row.decision_note }}</div>
                                    </td>
                                    <td v-if="canUpdate" class="text-nowrap text-end pe-4">
                                        <template v-if="row.status === 'pending'">
                                            <button type="button" class="btn btn-sm btn-danger-light me-1" @click="openDecision(row, 'removed')"><i class="ri-delete-bin-line me-1"></i>{{ t('watch_earn.comment_reports.remove') }}</button>
                                            <button type="button" class="btn btn-sm btn-light" @click="openDecision(row, 'kept')"><i class="ri-check-line me-1"></i>{{ t('watch_earn.comment_reports.keep') }}</button>
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

        <!-- The decision, with an optional note: both the advertiser and the comment's author get it in a push. -->
        <WalletModal
            :show="!!decision"
            :title="decision?.action === 'removed' ? t('watch_earn.comment_reports.remove') : t('watch_earn.comment_reports.keep')"
            size="md"
            @close="decision = null"
        >
            <form v-if="decision" id="we-comment-decision-form" @submit.prevent="decide">
                <div v-if="decision.row.comment?.body" class="alert alert-light border fs-13 we-quote">{{ decision.row.comment.body }}</div>
                <label class="form-label" for="we-decision-note">{{ t('wallet.common.note') }}</label>
                <textarea id="we-decision-note" v-model="decision.note" rows="3" maxlength="500" class="form-control" :class="{ 'is-invalid': noteError }" @input="noteError = ''"></textarea>
                <div v-if="noteError" class="invalid-feedback d-block">{{ noteError }}</div>
                <div v-else class="form-text">{{ t('watch_earn.comment_reports.note_prompt') }}</div>
            </form>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="decision = null">{{ t('cancel') }}</button>
                <button type="submit" form="we-comment-decision-form" class="btn" :class="decision?.action === 'removed' ? 'btn-danger' : 'btn-primary'" :disabled="busy">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('wallet.common.confirm') }}
                </button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';
import { formatDateTime as walletDateTime } from '../../../../../../../utils/walletMoney';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const canUpdate = computed(() => can('watch-earn-ads.update'));
const { rows, loading, pagination, filters, fetch } = useWalletList('watch-earn/comment-reports', { defaults: { status: 'pending' } });
const statuses = ['pending', 'removed', 'kept', ''];
const decision = ref(null);
const noteError = ref('');
const busy = ref(false);

const formatDateTime = (iso) => (iso ? walletDateTime(iso, locale.value) : '—');
const statusClass = (s) => ({ pending: 'bg-warning-transparent', removed: 'bg-danger-transparent', kept: 'bg-success-transparent' }[s] || 'bg-light text-dark');

function openDecision(row, action) {
    noteError.value = '';
    decision.value = { row, action, note: '' };
}

/** Both the advertiser and the comment's author get a push with the decision and this note. */
async function decide() {
    const { row, action, note } = decision.value;
    busy.value = true;
    noteError.value = '';
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/watch-earn/comment-reports/${row.id}/decide`, { action, note: note.trim() || null });
        Object.assign(row, data.data);
        decision.value = null;
        showSuccess(t(`watch_earn.comment_reports.done_${action}`));
    } catch (error) {
        const message = error?.response?.data?.errors?.note?.[0];
        if (message) {
            noteError.value = message;
        } else {
            showError(extractApiErrorMessage(error));
        }
    } finally {
        busy.value = false;
    }
}

onMounted(() => fetch(1));
</script>

<style scoped>
.we-quote { white-space: pre-wrap; word-break: break-word; }
</style>
