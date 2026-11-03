<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.community.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.community.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border fs-13">{{ t('freelance.community.hint') }}</div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <FlStatusFilter v-model="tab" :options="tabs" />
            </div>

            <!-- The numbers at a glance. -->
            <div v-if="tab === 'overview'" class="card-body">
                <div class="row g-3">
                    <div v-for="k in kpis" :key="k.key" class="col-12 col-sm-6 col-xl-4">
                        <div class="card custom-card border mb-0 h-100">
                            <div class="card-body d-flex align-items-center gap-3 py-3">
                                <span class="avatar avatar-md rounded-3" :class="k.tone"><i :class="k.icon" class="fs-18"></i></span>
                                <div>
                                    <div class="fs-20 fw-semibold lh-1">{{ k.value }}</div>
                                    <div class="text-muted fs-12 mt-1">{{ t(`freelance.community.kpi.${k.key}`) }}</div>
                                    <div v-if="k.sub" class="text-muted fs-11 mt-1">{{ k.sub }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="overview" class="mt-4">
                    <div class="fw-semibold fs-13 mb-2">{{ t('freelance.community.reports_by_status') }}</div>
                    <div class="d-flex flex-wrap gap-2">
                        <span v-for="s in reportStatuses" :key="s" class="badge fs-12 px-3 py-2" :class="badge[s]">{{ t(`freelance.community.report_status.${s}`) }} · {{ overview.reports?.[s] ?? 0 }}</span>
                    </div>
                </div>
            </div>

            <!-- Reports from members, open first. -->
            <template v-else-if="tab === 'reports'">
                <div class="card-body border-bottom py-2">
                    <FlStatusFilter v-model="reportStatus" :options="reportStatusOptions" />
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.community.reason') }}</th>
                                    <th>{{ t('freelance.community.reported_text') }}</th>
                                    <th>{{ t('freelance.community.author') }}</th>
                                    <th>{{ t('freelance.community.reporter') }}</th>
                                    <th class="text-center">{{ t('freelance.community.reports_n') }}</th>
                                    <th>{{ t('freelance.community.status') }}</th>
                                    <th class="text-end pe-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="reportsLoading" :rows="5" :layout="['two-lines', 'two-lines', 'text', 'text', 'text', 'badge', 'actions']" />
                                <tr v-else-if="!reports.length"><td colspan="7" class="text-center text-muted py-5">{{ t('freelance.community.reports_empty') }}</td></tr>
                                <tr v-for="r in reports" v-else :key="r.uuid">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold">{{ r.reason_label || r.reason }}</span>
                                        <span class="text-muted fs-11">{{ when(r.created_at) }}</span>
                                    </td>
                                    <td>
                                        <span class="d-block cell-text text-truncate">{{ r.subject?.body || t('freelance.community.deleted_text') }}</span>
                                        <span class="badge bg-light text-default fs-10">{{ t(`freelance.community.subject_type.${r.subject?.type || 'post'}`) }}</span>
                                        <span class="badge fs-10 ms-1" :class="itemBadge[r.subject?.status] || 'bg-light text-default'">{{ t(`freelance.community.item_status.${r.subject?.status || 'deleted'}`) }}</span>
                                    </td>
                                    <td>
                                        <span class="d-block">{{ r.author?.name || '—' }}</span>
                                        <span v-if="r.author?.muted_until" class="badge bg-danger-transparent fs-10">{{ t('freelance.community.muted_until', { date: day(r.author.muted_until) }) }}</span>
                                    </td>
                                    <td>{{ r.reporter?.name || '—' }}</td>
                                    <td class="text-center"><span class="badge bg-secondary-transparent">{{ r.subject?.reports ?? 0 }}</span></td>
                                    <td><span class="badge" :class="badge[r.status]">{{ t(`freelance.community.report_status.${r.status}`) }}</span></td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-wave" :class="r.status === 'open' && canUpdate ? 'btn-primary' : 'btn-light'" @click="openReport(r)">
                                            <i :class="r.status === 'open' && canUpdate ? 'ri-scales-3-line' : 'ri-eye-line'" class="me-1"></i>{{ r.status === 'open' && canUpdate ? t('freelance.community.decide') : t('freelance.community.open') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <Pager v-if="!reportsLoading && reports.length" :pagination="reportsPagination" :page="reportsPage" @page="reportsPage = $event" />
            </template>

            <!-- All posts, with a search. -->
            <template v-else-if="tab === 'posts'">
                <div class="card-body border-bottom py-3">
                    <div class="d-flex flex-wrap align-items-end gap-2">
                        <div class="flex-grow-1" style="min-width: 220px; max-width: 360px">
                            <input v-model="search" type="search" class="form-control form-control-sm" maxlength="80" :placeholder="t('freelance.community.search')">
                        </div>
                        <select v-model="postStatus" class="form-select form-select-sm w-auto">
                            <option value="">{{ t('freelance.community.all_statuses') }}</option>
                            <option value="published">{{ t('freelance.community.item_status.published') }}</option>
                            <option value="hidden">{{ t('freelance.community.item_status.hidden') }}</option>
                        </select>
                        <select v-model="postKind" class="form-select form-select-sm w-auto">
                            <option value="">{{ t('freelance.community.all_kinds') }}</option>
                            <option v-for="k in kinds" :key="k" :value="k">{{ t(`freelance.community.kinds.${k}`) }}</option>
                        </select>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.community.post') }}</th>
                                    <th>{{ t('freelance.community.author') }}</th>
                                    <th>{{ t('freelance.community.kind') }}</th>
                                    <th class="text-center">{{ t('freelance.community.activity') }}</th>
                                    <th>{{ t('freelance.community.status') }}</th>
                                    <th class="text-end pe-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="postsLoading" :rows="5" :layout="['two-lines', 'text', 'badge', 'text', 'badge', 'actions']" />
                                <tr v-else-if="!posts.length"><td colspan="6" class="text-center text-muted py-5">{{ t('freelance.community.posts_empty') }}</td></tr>
                                <tr v-for="p in posts" v-else :key="p.uuid">
                                    <td class="ps-4">
                                        <span class="d-block cell-text text-truncate">{{ p.body }}</span>
                                        <span class="text-muted fs-11">{{ when(p.created_at) }}<template v-if="p.specialty"> · {{ p.specialty }}</template></span>
                                    </td>
                                    <td>{{ p.author?.name || '—' }}</td>
                                    <td><span class="badge bg-light text-default">{{ t(`freelance.community.kinds.${p.kind}`) }}</span></td>
                                    <td class="text-center fs-12 text-muted">
                                        <span class="me-2" :title="t('freelance.community.reactions')"><i class="ri-heart-3-line me-1"></i>{{ p.reactions ?? 0 }}</span>
                                        <span class="me-2" :title="t('freelance.community.comments')"><i class="ri-chat-3-line me-1"></i>{{ p.comments ?? 0 }}</span>
                                        <span :title="t('freelance.community.reports_n')" :class="{ 'text-danger': p.reports }"><i class="ri-flag-line me-1"></i>{{ p.reports ?? 0 }}</span>
                                    </td>
                                    <td>
                                        <span class="badge" :class="itemBadge[p.status]">{{ t(`freelance.community.item_status.${p.status}`) }}</span>
                                        <span v-if="p.hidden_by_reports" class="badge bg-warning-transparent fs-10 ms-1">{{ t('freelance.community.by_reports') }}</span>
                                    </td>
                                    <td class="text-end pe-4">
                                        <button type="button" class="btn btn-sm btn-light btn-wave" @click="openPost(p.uuid)"><i class="ri-eye-line me-1"></i>{{ t('freelance.community.open') }}</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <Pager v-if="!postsLoading && posts.length" :pagination="postsPagination" :page="postsPage" @page="postsPage = $event" />
            </template>

            <!-- Members who cannot post or comment for now. -->
            <template v-else>
                <div v-if="canUpdate" class="card-body border-bottom">
                    <div class="fw-semibold fs-13 mb-2">{{ t('freelance.community.mute_member') }}</div>
                    <div class="row g-2 align-items-end">
                        <div class="col-6 col-md-2">
                            <label class="form-label fs-12 mb-1">{{ t('freelance.community.freelancer_id') }}</label>
                            <input v-model.number="muteForm.freelancer_id" type="number" min="1" class="form-control form-control-sm">
                        </div>
                        <div class="col-6 col-md-2">
                            <label class="form-label fs-12 mb-1">{{ t('freelance.community.days') }}</label>
                            <input v-model.number="muteForm.days" type="number" min="1" :max="maxMuteDays" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fs-12 mb-1">{{ t('freelance.community.mute_reason') }}</label>
                            <input v-model="muteForm.reason" type="text" maxlength="500" class="form-control form-control-sm">
                        </div>
                        <div class="col-12 col-md-2">
                            <button type="button" class="btn btn-sm btn-danger w-100" :disabled="muting || !muteValid" @click="muteMember">
                                <span v-if="muting" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-volume-mute-line me-1"></i>{{ t('freelance.community.mute') }}
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th class="ps-4">{{ t('freelance.community.freelancer') }}</th>
                                    <th>{{ t('freelance.community.ends_at') }}</th>
                                    <th>{{ t('freelance.community.mute_reason') }}</th>
                                    <th>{{ t('freelance.community.muted_at') }}</th>
                                    <th class="text-end pe-4"></th>
                                </tr>
                            </thead>
                            <tbody>
                                <TableSkeleton v-if="mutesLoading" :rows="4" :layout="['two-lines', 'text', 'text', 'text', 'actions']" />
                                <tr v-else-if="!mutes.length"><td colspan="5" class="text-center text-muted py-5">{{ t('freelance.community.mutes_empty') }}</td></tr>
                                <tr v-for="m in mutes" v-else :key="m.id">
                                    <td class="ps-4">
                                        <span class="d-block fw-semibold">{{ m.freelancer?.name || '—' }}</span>
                                        <span class="text-muted fs-11">#{{ m.freelancer?.id }}<template v-if="m.freelancer?.phone"> · <span dir="ltr">{{ m.freelancer.phone }}</span></template></span>
                                    </td>
                                    <td class="fs-12">{{ when(m.ends_at) }}</td>
                                    <td><span class="d-block cell-text text-truncate" :title="m.reason">{{ m.reason || '—' }}</span></td>
                                    <td class="fs-12 text-muted">{{ when(m.created_at) }}</td>
                                    <td class="text-end pe-4">
                                        <button v-if="canUpdate" type="button" class="btn btn-sm btn-light btn-wave" :disabled="lifting === m.id" @click="lift(m)">
                                            <span v-if="lifting === m.id" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-volume-up-line me-1"></i>{{ t('freelance.community.lift') }}
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </template>
        </div>

        <!-- One report: what was reported, by whom, then the decision. -->
        <WalletModal :show="!!report" :title="report?.reason_label || ''" @close="report = null">
            <div v-if="report">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                    <span class="badge" :class="badge[report.status]">{{ t(`freelance.community.report_status.${report.status}`) }}</span>
                    <span class="badge bg-light text-default">{{ t(`freelance.community.subject_type.${report.subject?.type || 'post'}`) }}</span>
                    <span class="badge" :class="itemBadge[report.subject?.status] || 'bg-light text-default'">{{ t(`freelance.community.item_status.${report.subject?.status || 'deleted'}`) }}</span>
                    <span class="text-muted fs-12">{{ when(report.created_at) }}</span>
                </div>
                <ul class="list-group fs-13 mb-3">
                    <li class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">{{ t('freelance.community.author') }}</span>
                        <span>{{ report.author?.name || '—' }}<span v-if="report.author?.id" class="text-muted"> #{{ report.author.id }}</span></span>
                    </li>
                    <li v-if="report.author?.muted_until" class="list-group-item d-flex justify-content-between">
                        <span class="text-muted">{{ t('freelance.community.muted_now') }}</span><span class="text-danger">{{ when(report.author.muted_until) }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{ t('freelance.community.reporter') }}</span><span>{{ report.reporter?.name || '—' }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">{{ t('freelance.community.reports_n') }}</span><span>{{ report.subject?.reports ?? 0 }}</span></li>
                </ul>
                <div class="d-flex align-items-center justify-content-between mb-1">
                    <h6 class="fw-semibold fs-13 mb-0">{{ t('freelance.community.reported_text') }}</h6>
                    <button v-if="report.subject?.post" type="button" class="btn btn-sm btn-link p-0" @click="openPost(report.subject.post, true)">{{ t('freelance.community.view_post') }}</button>
                </div>
                <div class="border rounded p-3 fs-13 mb-3 text-break" style="white-space: pre-line">{{ report.subject?.body || t('freelance.community.deleted_text') }}</div>
                <template v-if="report.details">
                    <h6 class="fw-semibold fs-13 mb-1">{{ t('freelance.community.details') }}</h6>
                    <div class="border rounded p-3 fs-13 mb-3" style="white-space: pre-line">{{ report.details }}</div>
                </template>

                <template v-if="report.status === 'open' && canUpdate">
                    <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.community.your_decision') }}</h6>
                    <div class="row g-2 mb-2">
                        <div v-for="d in ['uphold', 'dismiss']" :key="d" class="col-6">
                            <label class="decision border rounded-3 p-3 d-block h-100" :class="{ 'decision-on': decision === d }">
                                <input v-model="decision" type="radio" :value="d" class="form-check-input me-2">
                                <span class="fw-semibold">{{ t(`freelance.community.decision.${d}`) }}</span>
                                <span class="d-block text-muted fs-12 mt-1">{{ t(`freelance.community.decision_hint.${d}`) }}</span>
                            </label>
                        </div>
                    </div>
                    <div v-if="decision === 'uphold'" class="mb-2">
                        <label class="form-label fs-12 mb-1">{{ t('freelance.community.mute_days') }}</label>
                        <input v-model.number="muteDays" type="number" min="0" :max="maxMuteDays" class="form-control form-control-sm" style="max-width: 160px">
                        <div class="text-muted fs-11 mt-1">{{ t('freelance.community.mute_days_hint', { max: maxMuteDays }) }}</div>
                    </div>
                    <textarea v-model="note" class="form-control" rows="2" maxlength="1000" :placeholder="t('freelance.community.note_hint')"></textarea>
                </template>
                <div v-else-if="report.status !== 'open'" class="alert alert-light border fs-13 mb-0">
                    <div class="fw-semibold">{{ t(`freelance.community.report_status.${report.status}`) }} · {{ when(report.decided_at) }}</div>
                    <div v-if="report.decision_note" class="mt-1" style="white-space: pre-line">{{ report.decision_note }}</div>
                </div>
            </div>
            <template v-if="report?.status === 'open' && canUpdate" #footer>
                <button type="button" class="btn btn-light" @click="report = null">{{ t('cancel') }}</button>
                <button type="button" class="btn" :class="decision === 'uphold' ? 'btn-danger' : 'btn-primary'" :disabled="!decisionValid || deciding" @click="decide">
                    <span v-if="deciding" class="spinner-border spinner-border-sm me-1"></span>{{ t('freelance.community.confirm_decision') }}
                </button>
            </template>
        </WalletModal>

        <!-- One post and all its comments, with hide / show. -->
        <WalletModal :show="!!postUuid" :title="t('freelance.community.post_details')" smooth @close="closePost">
            <div v-if="postLoading" class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>{{ t('freelance.community.loading') }}</div>
            <div v-else-if="post">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <span class="badge" :class="itemBadge[post.status]">{{ t(`freelance.community.item_status.${post.status}`) }}</span>
                    <span v-if="post.hidden_by_reports" class="badge bg-warning-transparent">{{ t('freelance.community.by_reports') }}</span>
                    <span class="badge bg-light text-default">{{ t(`freelance.community.kinds.${post.kind}`) }}</span>
                    <span v-if="post.specialty" class="badge bg-light text-default">{{ post.specialty }}</span>
                    <span class="text-muted fs-12">{{ post.author?.name || '—' }}<template v-if="post.author?.id"> #{{ post.author.id }}</template> · {{ when(post.created_at) }}</span>
                </div>
                <div class="border rounded p-3 fs-13 mb-2 text-break" style="white-space: pre-line">{{ post.body }}</div>
                <div class="d-flex flex-wrap gap-3 text-muted fs-12 mb-2">
                    <span><i class="ri-image-line me-1"></i>{{ t('freelance.community.images_n', { n: post.images ?? 0 }) }}</span>
                    <span><i class="ri-heart-3-line me-1"></i>{{ post.reactions ?? 0 }}</span>
                    <span><i class="ri-chat-3-line me-1"></i>{{ postCommentCount }}</span>
                    <span :class="{ 'text-danger': post.reports }"><i class="ri-flag-line me-1"></i>{{ post.reports ?? 0 }}</span>
                </div>
                <div v-if="post.status === 'hidden' && post.hidden_reason" class="alert alert-warning fs-12 py-2 mb-2">{{ t('freelance.community.hidden_reason') }}: {{ post.hidden_reason }}</div>
                <ModerationActions v-if="canUpdate" :item="post" type="posts" />

                <h6 class="fw-semibold fs-13 mt-4 mb-2">{{ t('freelance.community.comments') }} ({{ postCommentCount }})</h6>
                <div v-if="!postComments.length" class="text-muted fs-12">{{ t('freelance.community.no_comments') }}</div>
                <div v-for="c in postComments":key="c.uuid" class="border rounded p-2 mb-2 fs-13" :class="{ 'ms-4': c.parent, 'bg-light': c.status !== 'published' }">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                        <span class="fw-semibold fs-12">{{ c.author?.name || '—' }}</span>
                        <span class="text-muted fs-11">{{ when(c.created_at) }}</span>
                        <span v-if="c.status !== 'published'" class="badge fs-10" :class="itemBadge[c.status]">{{ t(`freelance.community.item_status.${c.status}`) }}</span>
                    </div>
                    <div class="text-break" style="white-space: pre-line">{{ c.body }}</div>
                    <div v-if="c.status === 'hidden' && c.hidden_reason" class="text-warning fs-11 mt-1">{{ t('freelance.community.hidden_reason') }}: {{ c.hidden_reason }}</div>
                    <ModerationActions v-if="canUpdate" :item="c" type="comments" small />
                </div>
            </div>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import FlStatusFilter from '../../../../../../../components/freelance/FlStatusFilter.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';

// The professional community (FL-L12): the numbers, the members' reports (uphold hides the post / comment and may mute its author,
// dismiss leaves it), every post with its comments to hide or show again, and the members who are muted now.
const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { can } = usePermission();
const canUpdate = computed(() => can('freelance-community.update'));
const base = '/api/admin/v1/freelance-community';
const maxMuteDays = 90;

const reportStatuses = ['open', 'upheld', 'dismissed'];
const kinds = ['post', 'question', 'work'];
const badge = { open: 'bg-warning-transparent', upheld: 'bg-danger-transparent', dismissed: 'bg-secondary-transparent' };
const itemBadge = { published: 'bg-success-transparent', hidden: 'bg-warning-transparent', deleted: 'bg-secondary-transparent' };

const when = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
const day = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium' }) : '—');

async function run(request, after) {
    try {
        const response = await request();
        showSuccess(extractApiMessage(response, t('toast.success')));
        after?.();
        return true;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        return false;
    }
}

// ---------------------------------------------------------------- pager (same look as the other freelance lists)

const Pager = defineComponent({
    props: { pagination: { type: Object, default: () => ({}) }, page: { type: Number, default: 1 } },
    emits: ['page'],
    setup(props, { emit }) {
        return () => h('div', { class: 'card-footer border-top-0 d-flex align-items-center flex-wrap gap-3' }, [
            h('div', { class: 'text-muted fs-13' }, t('countries.showing_entries', { from: props.pagination.from ?? 0, to: props.pagination.to ?? 0, total: props.pagination.total ?? 0 })),
            (props.pagination.last_page ?? 1) > 1 ? h('nav', { class: 'pagination-style-4 ms-md-auto' }, [
                h('ul', { class: 'pagination mb-0' }, [
                    h('li', { class: ['page-item', { disabled: props.page <= 1 }] }, [h('button', { type: 'button', class: 'page-link', onClick: () => emit('page', props.page - 1) }, t('countries.previous'))]),
                    h('li', { class: 'page-item active' }, [h('span', { class: 'page-link' }, String(props.page))]),
                    h('li', { class: ['page-item', { disabled: props.page >= props.pagination.last_page }] }, [h('button', { type: 'button', class: 'page-link text-primary', onClick: () => emit('page', props.page + 1) }, t('countries.next'))]),
                ]),
            ]) : null,
        ]);
    },
});

// ---------------------------------------------------------------- numbers

const overview = ref(null);
const tab = ref('overview');

const kpis = computed(() => {
    const o = overview.value;
    return [
        { key: 'posts', icon: 'ri-article-line', tone: 'bg-primary-transparent', value: o?.posts.total ?? 0, sub: o ? t('freelance.community.this_week', { n: o.posts.week }) : '' },
        { key: 'hidden', icon: 'ri-eye-off-line', tone: 'bg-warning-transparent', value: o?.posts.hidden ?? 0 },
        { key: 'comments', icon: 'ri-chat-3-line', tone: 'bg-info-transparent', value: o?.comments.total ?? 0, sub: o ? t('freelance.community.this_week', { n: o.comments.week }) : '' },
        { key: 'posting', icon: 'ri-team-line', tone: 'bg-success-transparent', value: o?.members.posting_month ?? 0 },
        { key: 'muted', icon: 'ri-volume-mute-line', tone: 'bg-danger-transparent', value: o?.members.muted ?? 0 },
        { key: 'open_reports', icon: 'ri-flag-2-line', tone: 'bg-secondary-transparent', value: o?.reports?.open ?? 0 },
    ];
});
const tabs = computed(() => [
    { value: 'overview', label: t('freelance.community.tab_overview'), tone: 'all' },
    { value: 'reports', label: t('freelance.community.tab_reports'), count: overview.value?.reports?.open ?? 0, tone: 'pending' },
    { value: 'posts', label: t('freelance.community.tab_posts'), tone: 'all' },
    { value: 'mutes', label: t('freelance.community.tab_mutes'), count: overview.value?.members.muted ?? 0, tone: 'deleted' },
]);

async function loadOverview() {
    try {
        const { data } = await adminAxios.get(`${base}/overview`);
        overview.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

watch(tab, (v) => {
    if (v === 'overview') loadOverview();
    else if (v === 'reports') loadReports();
    else if (v === 'posts') loadPosts();
    else loadMutes();
});

// ---------------------------------------------------------------- reports

const reports = ref([]);
const reportsPagination = ref({});
const reportCounts = ref({ open: 0, upheld: 0, dismissed: 0 });
const reportsPage = ref(1);
const reportsLoading = ref(false);
const reportStatus = ref('open');
const report = ref(null);
const decision = ref('');
const note = ref('');
const muteDays = ref(0);
const deciding = ref(false);

const reportStatusOptions = computed(() => [
    { value: 'open', label: t('freelance.community.report_status.open'), count: reportCounts.value.open, tone: 'pending' },
    { value: 'upheld', label: t('freelance.community.report_status.upheld'), count: reportCounts.value.upheld, tone: 'deleted' },
    { value: 'dismissed', label: t('freelance.community.report_status.dismissed'), count: reportCounts.value.dismissed, tone: 'inactive' },
    { value: 'all', label: t('users.filter_all'), tone: 'all' },
]);
const decisionValid = computed(() => !!decision.value && (decision.value !== 'uphold' || (Number.isInteger(muteDays.value) && muteDays.value >= 0 && muteDays.value <= maxMuteDays)));

watch(reportStatus, () => (reportsPage.value === 1 ? loadReports() : (reportsPage.value = 1)));
watch(reportsPage, loadReports);

async function loadReports() {
    reportsLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/reports`, { params: { page: reportsPage.value, status: reportStatus.value === 'all' ? undefined : reportStatus.value } });
        reports.value = data.data ?? [];
        reportsPagination.value = data.pagination ?? {};
        reportCounts.value = data.counts ?? reportCounts.value;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        reportsLoading.value = false;
    }
}

function openReport(r) {
    report.value = r;
    decision.value = '';
    note.value = '';
    muteDays.value = 0;
}

async function decide() {
    deciding.value = true;
    const payload = { decision: decision.value, note: note.value.trim() || undefined, mute_days: decision.value === 'uphold' ? muteDays.value : undefined };
    await run(() => adminAxios.post(`${base}/reports/${report.value.uuid}/decide`, payload), () => {
        report.value = null;
        loadReports();
        loadOverview();
    });
    deciding.value = false;
}

// ---------------------------------------------------------------- posts

const posts = ref([]);
const postsPagination = ref({});
const postsPage = ref(1);
const postsLoading = ref(false);
const search = ref('');
const postStatus = ref('');
const postKind = ref('');
let searchTimer = null;

watch(search, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => (postsPage.value === 1 ? loadPosts() : (postsPage.value = 1)), 400);
});
watch([postStatus, postKind], () => (postsPage.value === 1 ? loadPosts() : (postsPage.value = 1)));
watch(postsPage, loadPosts);

async function loadPosts() {
    postsLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/posts`, {
            params: { page: postsPage.value, search: search.value.trim() || undefined, status: postStatus.value || undefined, kind: postKind.value || undefined },
        });
        posts.value = data.data ?? [];
        postsPagination.value = data.pagination ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        postsLoading.value = false;
    }
}

const postUuid = ref(null);
const post = ref(null);
const postLoading = ref(false);
// The details answer carries the comment list under `comments`; the list rows carry a count there.
const postComments = computed(() => (Array.isArray(post.value?.comments) ? post.value.comments : []));
const postCommentCount = computed(() => (Array.isArray(post.value?.comments) ? post.value.comments.length : (post.value?.comments ?? 0)));

async function openPost(uuid, fromReport = false) {
    if (fromReport) report.value = null;
    postUuid.value = uuid;
    post.value = null;
    await loadPost();
}

async function loadPost() {
    postLoading.value = !post.value;
    try {
        const { data } = await adminAxios.get(`${base}/posts/${postUuid.value}`);
        post.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        if (!post.value) postUuid.value = null;
    } finally {
        postLoading.value = false;
    }
}

function closePost() {
    postUuid.value = null;
    post.value = null;
}

function afterModeration() {
    loadPost();
    loadOverview();
    if (tab.value === 'posts') loadPosts();
    if (tab.value === 'reports') loadReports();
}

/** Hide (with a required reason) or show again one post / comment, inline under it. */
const ModerationActions = defineComponent({
    props: { item: { type: Object, required: true }, type: { type: String, required: true }, small: { type: Boolean, default: false } },
    setup(props) {
        const asking = ref(false);
        const reason = ref('');
        const busy = ref(false);
        const btn = (extra) => `btn btn-sm ${extra}${props.small ? ' py-0 px-2 fs-11' : ''}`;

        async function hide() {
            busy.value = true;
            const ok = await run(() => adminAxios.post(`${base}/${props.type}/${props.item.uuid}/hide`, { reason: reason.value.trim() }), afterModeration);
            busy.value = false;
            if (ok) {
                asking.value = false;
                reason.value = '';
            }
        }

        async function unhide() {
            busy.value = true;
            await run(() => adminAxios.post(`${base}/${props.type}/${props.item.uuid}/unhide`), afterModeration);
            busy.value = false;
        }

        const spinner = () => (busy.value ? h('span', { class: 'spinner-border spinner-border-sm me-1' }) : null);

        return () => {
            const status = props.item.status;
            if (status === 'deleted') return null;
            if (status === 'hidden') {
                return h('div', { class: 'mt-2' }, [
                    h('button', { type: 'button', class: btn('btn-success-light'), disabled: busy.value, onClick: unhide }, [spinner(), h('i', { class: 'ri-eye-line me-1' }), t('freelance.community.unhide')]),
                ]);
            }
            if (!asking.value) {
                return h('div', { class: 'mt-2' }, [
                    h('button', { type: 'button', class: btn('btn-warning-light'), onClick: () => (asking.value = true) }, [h('i', { class: 'ri-eye-off-line me-1' }), t('freelance.community.hide')]),
                ]);
            }
            const valid = reason.value.trim().length >= 3;
            return h('div', { class: 'd-flex flex-wrap gap-2 mt-2 align-items-center' }, [
                h('input', {
                    type: 'text', class: 'form-control form-control-sm flex-grow-1', style: 'min-width: 200px; max-width: 420px', maxlength: 300, value: reason.value,
                    placeholder: t('freelance.community.hide_reason'), onInput: (e) => (reason.value = e.target.value),
                }),
                h('button', { type: 'button', class: btn('btn-warning'), disabled: busy.value || !valid, onClick: hide }, [spinner(), t('freelance.community.confirm_hide')]),
                h('button', { type: 'button', class: btn('btn-light'), disabled: busy.value, onClick: () => { asking.value = false; reason.value = ''; } }, t('cancel')),
            ]);
        };
    },
});

// ---------------------------------------------------------------- mutes

const mutes = ref([]);
const mutesLoading = ref(false);
const lifting = ref(null);
const muting = ref(false);
const muteForm = reactive({ freelancer_id: null, days: 7, reason: '' });
const muteValid = computed(() => Number.isInteger(muteForm.freelancer_id) && muteForm.freelancer_id > 0
    && Number.isInteger(muteForm.days) && muteForm.days >= 1 && muteForm.days <= maxMuteDays && muteForm.reason.trim().length >= 3);

async function loadMutes() {
    mutesLoading.value = true;
    try {
        const { data } = await adminAxios.get(`${base}/mutes`);
        mutes.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        mutesLoading.value = false;
    }
}

async function muteMember() {
    muting.value = true;
    await run(() => adminAxios.post(`${base}/mutes`, { freelancer_id: muteForm.freelancer_id, days: muteForm.days, reason: muteForm.reason.trim() }), () => {
        Object.assign(muteForm, { freelancer_id: null, days: 7, reason: '' });
        loadMutes();
        loadOverview();
    });
    muting.value = false;
}

async function lift(m) {
    if (!window.confirm(t('freelance.community.lift_confirm', { name: m.freelancer?.name || `#${m.freelancer?.id}` }))) return;
    lifting.value = m.id;
    await run(() => adminAxios.delete(`${base}/mutes/${m.id}`), () => {
        loadMutes();
        loadOverview();
    });
    lifting.value = null;
}

onMounted(loadOverview);
</script>

<style scoped>
.cell-text {
    max-width: 300px;
}

.decision {
    cursor: pointer;
}

.decision-on {
    border-color: var(--primary-color, #845adf) !important;
    background: rgba(132, 90, 223, 0.06);
}
</style>
