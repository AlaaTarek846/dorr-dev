<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_benchmark_runs.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_benchmark_runs.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_benchmark_runs.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-header d-flex flex-wrap align-items-center justify-content-between py-3 gap-2">
                        <router-link :to="{ name: 'admin.ai-benchmark-cases.index' }" class="btn btn-light btn-sm btn-wave">
                            <i class="ri-list-check-2 me-1 align-middle"></i>
                            {{ t('ai_benchmark_runs.manage_cases') }}
                        </router-link>

                        <div class="d-flex align-items-center gap-2">
                            <select v-model="triggerDomain" class="form-select form-select-sm" style="width: auto;">
                                <option value="">{{ t('ai_benchmark_runs.all_domains') }}</option>
                                <option value="legal">legal</option>
                                <option value="health">health</option>
                                <option value="education">education</option>
                                <option value="code">code</option>
                                <option value="marketing">marketing</option>
                                <option value="general_info">general_info</option>
                            </select>
                            <button type="button" class="btn btn-primary btn-sm btn-wave" :disabled="triggering" @click="triggerRun">
                                <i class="ri-play-circle-line me-1 align-middle"></i>
                                {{ triggering ? t('ai_benchmark_runs.running') : t('ai_benchmark_runs.run_now') }}
                            </button>
                        </div>
                    </div>

                    <div class="alert alert-warning mb-0 rounded-0 border-0 fs-12" v-if="lastRunSmallSample">
                        {{ t('ai_benchmark_runs.small_sample_warning', { size: minSampleSize }) }}
                    </div>

                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_benchmark_runs.run') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_runs.status') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_runs.total_cases') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_runs.passed_cases') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_runs.pass_rate') }}</th>
                                        <th scope="col">{{ t('ai_benchmark_runs.started_at') }}</th>
                                        <th scope="col" class="text-end pe-4">{{ t('ai_benchmark_runs.actions') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="6" :columns="7" />

                                    <tr v-else-if="!runs.length">
                                        <td colspan="7" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_benchmark_runs.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="run in runs" v-else :key="run.id">
                                        <td>#{{ run.id }} <span class="d-block text-muted fs-11">{{ run.model_key || '-' }}</span></td>
                                        <td>
                                            <span class="badge" :class="statusBadgeClass(run.status)">
                                                {{ t('ai_benchmark_runs.status_' + run.status) }}
                                            </span>
                                        </td>
                                        <td>{{ run.total_cases }}</td>
                                        <td>{{ run.passed_cases }} / {{ run.total_cases }}</td>
                                        <td>
                                            <span class="fw-semibold" :class="passRateClass(run.pass_rate)">
                                                {{ run.pass_rate !== null ? run.pass_rate + '%' : '-' }}
                                            </span>
                                        </td>
                                        <td>{{ formatDateTime(run.started_at) }}</td>
                                        <td class="text-end pe-4">
                                            <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openDetails(run)">
                                                <i class="ri-eye-line"></i>
                                            </button>
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

        <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl">
                <div class="modal-content" v-if="selected">
                    <div class="modal-header catalog-modal-header">
                        <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                            <h6 class="modal-title mb-0">{{ t('ai_benchmark_runs.details_title') }} #{{ selected.id }}</h6>
                            <button type="button" class="btn-close" aria-label="Close" @click="closeModal"></button>
                        </div>
                    </div>
                    <div class="modal-body px-4">
                        <div class="table-responsive">
                            <table class="table table-sm table-striped mb-0">
                                <thead>
                                    <tr>
                                        <th>{{ t('ai_benchmark_runs.result_prompt') }}</th>
                                        <th>{{ t('ai_benchmark_runs.result_expected') }}</th>
                                        <th>{{ t('ai_benchmark_runs.result_abstained') }}</th>
                                        <th>{{ t('ai_benchmark_runs.result_citation') }}</th>
                                        <th>{{ t('ai_benchmark_runs.result_correctness') }}</th>
                                        <th>{{ t('ai_benchmark_runs.result_passed') }}</th>
                                        <th>{{ t('ai_benchmark_runs.result_latency') }}</th>
                                        <th>{{ t('ai_benchmark_runs.result_response') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="result in selected.results" :key="result.id">
                                        <td style="max-width: 320px; white-space: normal;">{{ result.case?.prompt }}</td>
                                        <td>{{ result.case?.expected_behavior ? t('ai_benchmark_cases.behavior_' + result.case.expected_behavior) : '-' }}</td>
                                        <td>{{ result.abstained ? t('yes') : t('no') }}</td>
                                        <td>{{ result.citation_present ? t('yes') : t('no') }}</td>
                                        <td>{{ result.correctness_score !== null ? result.correctness_score : '-' }}</td>
                                        <td>
                                            <span class="badge" :class="result.passed ? 'bg-success-transparent' : 'bg-danger-transparent'">
                                                {{ result.passed ? t('yes') : t('no') }}
                                            </span>
                                        </td>
                                        <td>{{ result.latency_ms !== null ? result.latency_ms + ' ms' : '-' }}</td>
                                        <td style="max-width: 280px; white-space: normal;">
                                            <!-- Was silently dropped from this modal before - the two fields
                                                 that actually explain WHY a case passed/failed (the model's real
                                                 reply, or the failure reason when the call itself errored) were
                                                 computed and stored (ai_benchmark_results.actual_response /
                                                 failure_reason) but never rendered anywhere in the admin UI. -->
                                            <span v-if="result.failure_reason" class="text-danger">{{ result.failure_reason }}</span>
                                            <span v-else-if="result.actual_response" class="text-muted" :title="result.actual_response">
                                                {{ truncate(result.actual_response, 140) }}
                                            </span>
                                            <span v-else>-</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="closeModal">{{ t('close') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t, locale } = useI18n();

function truncate(value, max) {
    if (! value) return '';
    return value.length > max ? value.slice(0, max) + '\u2026' : value;
}
const { showSuccess, showError } = useToast();
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

const runs = ref([]);
const loading = ref(true);
const triggering = ref(false);
const triggerDomain = ref('');
const selected = ref(null);
const modalElement = ref(null);
const minSampleSize = ref(30); // fallback until loadMinSampleSize() resolves the real configured value
let modalInstance = null;

const lastRunSmallSample = computed(() => {
    const latest = runs.value[0];
    return Boolean(latest && latest.total_cases > 0 && latest.total_cases < minSampleSize.value);
});

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

function statusBadgeClass(status) {
    return {
        completed: 'bg-success-transparent',
        running: 'bg-info-transparent',
        failed: 'bg-danger-transparent',
    }[status] || 'bg-light text-muted';
}

function passRateClass(rate) {
    if (rate === null || rate === undefined) return 'text-muted';
    if (rate >= 80) return 'text-success';
    if (rate >= 50) return 'text-warning';
    return 'text-danger';
}

async function loadRuns() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-benchmark-runs', { params: paginationParams.value });
        runs.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

/**
 * Business gap fix: this screen's small-sample warning used to hardcode
 * "30" in the frontend, while the real threshold is configurable
 * (config('ai.min_recommended_sample_size') / env AI_BENCHMARK_MIN_SAMPLE_SIZE)
 * - a config change never reached this screen. Reads the live value from
 * the new GET .../ai-benchmark-runs/config endpoint; the ref above stays
 * as a sane fallback only if that call fails.
 */
async function loadMinSampleSize() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-benchmark-runs/config');
        if (data?.data?.min_recommended_sample_size) {
            minSampleSize.value = data.data.min_recommended_sample_size;
        }
    } catch (error) {
        // Non-fatal - the hardcoded fallback above still gives a reasonable warning.
    }
}

async function triggerRun() {
    triggering.value = true;

    try {
        const response = await adminAxios.post('/api/admin/v1/ai-benchmark-runs', {
            domain_key: triggerDomain.value || null,
        });
        showSuccess(extractApiMessage(response, t('toast.created')));
        await loadRuns();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        triggering.value = false;
    }
}

async function openDetails(run) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/ai-benchmark-runs/${run.id}`);
        selected.value = data.data ?? run;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
        selected.value = run;
    }

    if (! modalElement.value) return;
    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function onChangePage(target) {
    page.value = target;
    loadRuns();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadRuns();
}

onMounted(() => {
    loadRuns();
    loadMinSampleSize();
    modalElement.value?.addEventListener('hidden.bs.modal', () => { selected.value = null; });
});

onUnmounted(() => {
    modalInstance?.dispose();
});
</script>
