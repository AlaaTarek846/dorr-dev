<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_learned_intents.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_learned_intents.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_learned_intents.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!-- Stats -->
        <div class="row">
            <div v-for="card in statCards" :key="card.key" class="col-xxl col-xl-4 col-md-6">
                <div class="card custom-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center gap-3">
                            <span class="avatar avatar-md avatar-rounded" :class="card.tone">
                                <i :class="card.icon" class="fs-18"></i>
                            </span>
                            <div class="flex-fill">
                                <span class="d-block text-muted fs-12">{{ card.label }}</span>
                                <h4 class="fw-semibold mb-0">
                                    <span v-if="statsLoading" class="placeholder col-4"></span>
                                    <template v-else>{{ card.value }}</template>
                                </h4>
                            </div>
                        </div>
                        <p v-if="card.hint" class="text-muted fs-11 mb-0 mt-2">{{ card.hint }}</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- How it works + phrase tester -->
        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header">
                        <div class="card-title">{{ t('ai_learned_intents.tester_title') }}</div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted fs-13 mb-3">{{ t('ai_learned_intents.tester_hint') }}</p>
                        <form class="row g-2 align-items-center" @submit.prevent="runTest">
                            <div class="col-lg">
                                <input
                                    v-model="testMessage"
                                    type="text"
                                    dir="auto"
                                    maxlength="600"
                                    class="form-control"
                                    :placeholder="t('ai_learned_intents.tester_placeholder')"
                                >
                            </div>
                            <div class="col-lg-auto">
                                <div class="form-check mb-0">
                                    <input id="tester-recent-image" v-model="testRecentImage" type="checkbox" class="form-check-input">
                                    <label class="form-check-label fs-13" for="tester-recent-image">{{ t('ai_learned_intents.tester_recent_image') }}</label>
                                </div>
                            </div>
                            <div class="col-lg-auto">
                                <button type="submit" class="btn btn-primary btn-wave" :disabled="testing || ! testMessage.trim()">
                                    <span v-if="testing" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ri-flask-line me-1 align-middle"></i>
                                    {{ t('ai_learned_intents.tester_run') }}
                                </button>
                            </div>
                        </form>

                        <div v-if="testResult" class="mt-3 p-3 rounded border bg-light">
                            <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                <span class="badge" :class="stageTone(testResult.stage)">{{ t(`ai_learned_intents.stage.${testResult.stage}`) }}</span>
                                <span v-for="intent in testResult.intents" :key="intent" class="badge bg-primary-transparent">
                                    <i :class="intentIcon(intent)" class="me-1"></i>{{ intentLabel(intent) }}
                                </span>
                                <span v-if="testResult.file_format" class="badge bg-info-transparent text-uppercase">{{ testResult.file_format }}</span>
                            </div>
                            <p class="text-muted fs-12 mb-1">{{ t(`ai_learned_intents.stage_hint.${testResult.stage}`) }}</p>
                            <p class="text-muted fs-11 mb-0">
                                {{ t('ai_learned_intents.normalized_as') }}: <code dir="auto">{{ testResult.normalized }}</code>
                                <span v-if="testResult.learned_id"> · #{{ testResult.learned_id }}</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Table -->
        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2 py-3">
                        <div class="d-flex flex-wrap gap-2 flex-fill">
                            <div class="input-group input-group-sm w-auto">
                                <span class="input-group-text"><i class="ri-search-line"></i></span>
                                <input
                                    v-model="filters.search"
                                    type="text"
                                    dir="auto"
                                    class="form-control"
                                    :placeholder="t('ai_learned_intents.search_placeholder')"
                                    @input="onSearchInput"
                                >
                            </div>
                            <select v-model="filters.status" class="form-select form-select-sm w-auto" @change="applyFilters">
                                <option value="">{{ t('ai_learned_intents.filter_all_statuses') }}</option>
                                <option value="active">{{ t('ai_learned_intents.status_active') }}</option>
                                <option value="pending">{{ t('ai_learned_intents.status_pending') }}</option>
                                <option value="conflict">{{ t('ai_learned_intents.status_conflict') }}</option>
                            </select>
                            <select v-model="filters.intent" class="form-select form-select-sm w-auto" @change="applyFilters">
                                <option value="">{{ t('ai_learned_intents.filter_all_intents') }}</option>
                                <option v-for="intent in intents" :key="intent" :value="intent">{{ intentLabel(intent) }}</option>
                            </select>
                            <select v-model="filters.mode" class="form-select form-select-sm w-auto" @change="applyFilters">
                                <option value="">{{ t('ai_learned_intents.filter_all_modes') }}</option>
                                <option value="phrase">{{ t('ai_learned_intents.mode_phrase') }}</option>
                                <option value="exact">{{ t('ai_learned_intents.mode_exact') }}</option>
                            </select>
                            <select v-model="filters.source" class="form-select form-select-sm w-auto" @change="applyFilters">
                                <option value="">{{ t('ai_learned_intents.filter_all_sources') }}</option>
                                <option value="model">{{ t('ai_learned_intents.source_model') }}</option>
                                <option value="admin">{{ t('ai_learned_intents.source_admin') }}</option>
                            </select>
                        </div>
                        <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                            <i class="ri-add-line me-1 align-middle"></i>
                            {{ t('ai_learned_intents.add_short') }}
                        </button>
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0 align-middle">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_learned_intents.phrase') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.intent') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.status') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.confidence') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.hits') }}</th>
                                        <th scope="col">{{ t('ai_learned_intents.source') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_learned_intents.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="7" />

                                    <tr v-else-if="!records.length">
                                        <td colspan="7" class="border-0">
                                            <div class="text-center py-5">
                                                <p class="fw-semibold mb-1">{{ t('ai_learned_intents.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('ai_learned_intents.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr v-for="record in records" v-else :key="record.id">
                                        <td>
                                            <span class="d-block fw-semibold text-wrap" dir="auto" style="max-width: 320px">{{ record.phrase }}</span>
                                            <span class="badge bg-light text-muted fs-10 mt-1">{{ t(`ai_learned_intents.mode_${record.match_mode}`) }}</span>
                                            <span class="badge bg-light text-muted fs-10 mt-1 text-uppercase">{{ record.language }}</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary-transparent">
                                                <i :class="intentIcon(record.intent)" class="me-1"></i>{{ intentLabel(record.intent) }}
                                            </span>
                                            <span v-if="record.file_format" class="badge bg-info-transparent text-uppercase ms-1">{{ record.file_format }}</span>
                                        </td>
                                        <td>
                                            <span class="badge" :class="statusTone(record.status)">{{ t(`ai_learned_intents.status_${record.status}`) }}</span>
                                            <span v-if="record.status === 'pending'" class="d-block text-muted fs-11 mt-1">
                                                {{ t('ai_learned_intents.confirmations_progress', { n: record.confirmations, total: minConfirmations }) }}
                                            </span>
                                            <span v-if="record.status === 'conflict'" class="d-block text-danger fs-11 mt-1">
                                                {{ t('ai_learned_intents.conflicts_count', { n: record.conflicts }) }}
                                            </span>
                                        </td>
                                        <td style="min-width: 110px">
                                            <div class="progress progress-xs" role="progressbar">
                                                <div class="progress-bar" :class="confidenceTone(record.confidence)" :style="{ width: `${Math.round(record.confidence * 100)}%` }"></div>
                                            </div>
                                            <span class="text-muted fs-11">{{ Math.round(record.confidence * 100) }}%</span>
                                        </td>
                                        <td>
                                            <span class="fw-semibold">{{ record.hits }}</span>
                                            <span v-if="record.last_hit_at" class="d-block text-muted fs-11">{{ formatDate(record.last_hit_at) }}</span>
                                        </td>
                                        <td>
                                            <span class="badge" :class="record.source === 'admin' ? 'bg-secondary-transparent' : 'bg-light text-default'">
                                                <i :class="record.source === 'admin' ? 'ri-user-settings-line' : 'ri-robot-2-line'" class="me-1"></i>{{ t(`ai_learned_intents.source_${record.source}`) }}
                                            </span>
                                            <span v-if="record.learned_with_model" class="d-block text-muted fs-11">{{ record.learned_with_model }}</span>
                                        </td>
                                        <td class="text-end pe-4">
                                            <div class="d-flex align-items-center justify-content-end gap-3">
                                                <div
                                                    class="toggle toggle-success mb-0"
                                                    :class="{ on: record.is_active }"
                                                    role="button"
                                                    tabindex="0"
                                                    :title="record.is_active ? t('ai_learned_intents.disable') : t('ai_learned_intents.enable')"
                                                    @click="toggleActive(record)"
                                                    @keydown.enter.space.prevent="toggleActive(record)"
                                                >
                                                    <span></span>
                                                </div>
                                                <div class="btn-list">
                                                    <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(record)">
                                                        <i class="ri-pencil-line"></i>
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="askDelete(record)">
                                                        <i class="ri-delete-bin-line"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <AdminPaginationFooter
                        :pagination="pagination"
                        :current-page="page"
                        :per-page="perPage"
                        :loading="loading"
                        @change-page="onChangePage"
                        @change-per-page="onChangePerPage"
                    />
                </div>
            </div>
        </div>

        <ModalCreateAndUpdate
            :show="modalShow"
            :type="modalType"
            :record="selectedRecord"
            :intents="intents"
            @close="modalShow = false"
            @saved="onSaved"
        />

        <ConfirmDeleteModal
            :show="deleteConfirm.state.show"
            :title="deleteConfirm.state.title"
            :message="deleteConfirm.state.message"
            :loading="deleteConfirm.state.loading"
            @close="deleteConfirm.close"
            @confirm="remove"
        />
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import ConfirmDeleteModal from '../../../../../../components/ui/ConfirmDeleteModal.vue';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';
import { useConfirmDelete } from '../../../../../../composables/useConfirmDelete';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import ModalCreateAndUpdate from './ModalCreateAndUpdate.vue';

const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();
const deleteConfirm = useConfirmDelete();

const BASE = '/api/admin/v1/ai-learned-intents';

// Must mirror Modules\AI\Support\AiChatIntent::ACTIONS.
const intents = [
    'image_generation', 'image_edit', 'voice_reply', 'file_output', 'web_search',
    'research', 'study', 'coding', 'structured_output',
];

const intentIcons = {
    image_generation: 'ri-image-add-line',
    image_edit: 'ri-image-edit-line',
    voice_reply: 'ri-mic-line',
    file_output: 'ri-file-download-line',
    web_search: 'ri-global-line',
    research: 'ri-microscope-line',
    study: 'ri-graduation-cap-line',
    coding: 'ri-code-s-slash-line',
    structured_output: 'ri-braces-line',
};

const records = ref([]);
const loading = ref(true);
const stats = ref(null);
const statsLoading = ref(true);

const filters = reactive({ search: '', status: '', intent: '', mode: '', source: '' });

const modalShow = ref(false);
const modalType = ref('create');
const selectedRecord = ref(null);

const testMessage = ref('');
const testRecentImage = ref(false);
const testing = ref(false);
const testResult = ref(null);

const minConfirmations = computed(() => stats.value?.min_confirmations ?? 2);

const statCards = computed(() => [
    { key: 'total', label: t('ai_learned_intents.stat_total'), value: stats.value?.total ?? 0, icon: 'ri-book-2-line', tone: 'bg-primary-transparent text-primary' },
    { key: 'active', label: t('ai_learned_intents.stat_active'), value: stats.value?.active ?? 0, icon: 'ri-checkbox-circle-line', tone: 'bg-success-transparent text-success' },
    { key: 'pending', label: t('ai_learned_intents.stat_pending'), value: stats.value?.pending ?? 0, icon: 'ri-time-line', tone: 'bg-warning-transparent text-warning', hint: t('ai_learned_intents.stat_pending_hint', { n: minConfirmations.value }) },
    { key: 'conflicts', label: t('ai_learned_intents.stat_conflicts'), value: stats.value?.conflicts ?? 0, icon: 'ri-error-warning-line', tone: 'bg-danger-transparent text-danger', hint: t('ai_learned_intents.stat_conflicts_hint') },
    { key: 'hits', label: t('ai_learned_intents.stat_hits'), value: stats.value?.hits ?? 0, icon: 'ri-flashlight-line', tone: 'bg-info-transparent text-info', hint: t('ai_learned_intents.stat_hits_hint') },
]);

function intentLabel(intent) {
    return t(`ai_learned_intents.intents.${intent}`);
}

function intentIcon(intent) {
    return intentIcons[intent] ?? 'ri-question-line';
}

function statusTone(status) {
    return { active: 'bg-success-transparent', pending: 'bg-warning-transparent', conflict: 'bg-danger-transparent' }[status] ?? 'bg-light';
}

function stageTone(stage) {
    return {
        lexicon: 'bg-success-transparent',
        learned: 'bg-success-transparent',
        would_ask_model: 'bg-warning-transparent',
        skipped: 'bg-light text-muted',
        router_disabled: 'bg-danger-transparent',
    }[stage] ?? 'bg-light';
}

function confidenceTone(value) {
    if (value >= 0.9) return 'bg-success';
    if (value >= 0.7) return 'bg-warning';
    return 'bg-danger';
}

function formatDate(value) {
    try {
        return new Intl.DateTimeFormat(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
    } catch {
        return value;
    }
}

async function loadStats() {
    statsLoading.value = true;

    try {
        const { data } = await adminAxios.get(`${BASE}/stats`);
        stats.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        statsLoading.value = false;
    }
}

async function loadRecords() {
    loading.value = true;

    try {
        const params = { ...paginationParams.value };

        Object.entries(filters).forEach(([key, value]) => {
            if (value) params[key] = value;
        });

        const { data } = await adminAxios.get(BASE, { params });
        records.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function refreshAll() {
    loadStats();
    loadRecords();
}

let searchTimer = null;

function onSearchInput() {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(applyFilters, 350);
}

function applyFilters() {
    page.value = 1;
    loadRecords();
}

async function runTest() {
    if (! testMessage.value.trim()) return;

    testing.value = true;

    try {
        const { data } = await adminAxios.post(`${BASE}/test`, {
            message: testMessage.value,
            recent_image: testRecentImage.value,
        });
        testResult.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        testing.value = false;
    }
}

function openCreate() {
    modalType.value = 'create';
    selectedRecord.value = null;
    modalShow.value = true;
}

function openEdit(record) {
    modalType.value = 'edit';
    selectedRecord.value = { ...record };
    modalShow.value = true;
}

async function toggleActive(record) {
    try {
        const response = await adminAxios.put(`${BASE}/${record.id}`, { is_active: ! record.is_active });
        Object.assign(record, response.data?.data ?? {});
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
        loadStats();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function askDelete(record) {
    deleteConfirm.open({
        title: t('ai_learned_intents.confirm_delete_title'),
        message: t('ai_learned_intents.confirm_delete', { phrase: record.phrase }),
        payload: record,
    });
}

async function remove() {
    const record = deleteConfirm.state.payload;
    if (! record) return;

    deleteConfirm.setLoading(true);

    try {
        const response = await adminAxios.delete(`${BASE}/${record.id}`);
        showSuccess(extractApiMessage(response, t('toast.deleted')));
        deleteConfirm.setLoading(false);
        deleteConfirm.close();
        refreshAll();
    } catch (error) {
        deleteConfirm.setLoading(false);
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onSaved() {
    modalShow.value = false;
    refreshAll();
}

function onChangePage(target) {
    page.value = target;
    loadRecords();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadRecords();
}

onMounted(refreshAll);
onBeforeUnmount(() => clearTimeout(searchTimer));
</script>
