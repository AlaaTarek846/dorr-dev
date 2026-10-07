<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-xl">
            <div class="modal-content">
                <div class="modal-header translations-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ t('language_translations.title') }}
                            <span v-if="overview?.language" class="text-muted fw-normal">
                                — {{ overview.language.name }}
                                <span class="badge bg-primary-transparent ms-1">{{ overview.language.code }}</span>
                            </span>
                        </h6>
                        <button type="button" class="btn-close" aria-label="Close" @click="close"></button>
                    </div>
                </div>

                <div class="modal-body px-4">
                    <div v-if="loading" class="text-center text-muted py-5">
                        <span class="spinner-border spinner-border-sm me-2"></span>
                        {{ t('language_translations.loading') }}
                    </div>

                    <div v-else-if="overview?.language?.is_source" class="alert alert-info mb-0">
                        {{ t('language_translations.source_notice') }}
                    </div>

                    <template v-else-if="overview">
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <p class="text-muted fs-13 mb-0">{{ t('language_translations.flow_hint') }}</p>
                            <div class="d-flex align-items-center gap-2">
                                <label class="text-muted fs-13 mb-0" for="translations-export-mode">
                                    {{ t('language_translations.export_mode') }}
                                </label>
                                <select id="translations-export-mode" v-model="exportMode" class="form-select form-select-sm w-auto">
                                    <option value="all">{{ t('language_translations.export_all') }}</option>
                                    <option value="missing">{{ t('language_translations.export_missing') }}</option>
                                </select>
                            </div>
                        </div>

                        <div v-for="platform in overview.platforms" :key="platform.platform" class="mb-4">
                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                                <h6 class="fw-semibold mb-0">{{ platformLabel(platform.platform) }}</h6>
                                <div v-if="platform.platform === 'android'" class="btn-list">
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-primary-light"
                                        :disabled="busy"
                                        @click="downloadAndroidZip('published')"
                                    >
                                        <i class="ri-file-zip-line me-1"></i>{{ t('language_translations.android_zip') }}
                                    </button>
                                    <button
                                        type="button"
                                        class="btn btn-sm btn-light"
                                        :disabled="busy"
                                        @click="downloadAndroidZip('draft')"
                                    >
                                        {{ t('language_translations.android_zip_draft') }}
                                    </button>
                                </div>
                            </div>
                            <p v-if="platform.platform === 'android'" class="text-muted fs-12 mb-2">
                                {{ t('language_translations.android_hint') }}
                            </p>

                            <div class="table-responsive border rounded">
                                <table class="table table-sm align-middle text-nowrap mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th>{{ t('language_translations.group') }}</th>
                                            <th>{{ t('language_translations.status') }}</th>
                                            <th class="text-center">{{ t('language_translations.keys_total') }}</th>
                                            <th class="text-center">{{ t('language_translations.keys_translated') }}</th>
                                            <th class="text-center">{{ t('language_translations.keys_missing') }}</th>
                                            <th style="min-width: 120px;">{{ t('language_translations.progress') }}</th>
                                            <th>{{ t('language_translations.published') }}</th>
                                            <th class="text-end">{{ t('language_translations.actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template v-for="group in platform.groups" :key="group.group">
                                            <tr>
                                                <td class="fw-semibold">
                                                    {{ group.group }}
                                                    <span v-if="! group.base_available" class="d-block text-danger fs-11">
                                                        {{ t('language_translations.base_unavailable') }}
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="badge" :class="statusBadgeClass(group.status)">
                                                        {{ t(`language_translations.status_${group.status}`) }}
                                                    </span>
                                                </td>
                                                <td class="text-center">{{ group.total_keys }}</td>
                                                <td class="text-center text-success">{{ group.translated_keys }}</td>
                                                <td class="text-center" :class="{ 'text-danger': group.missing_keys > 0 }">{{ group.missing_keys }}</td>
                                                <td>
                                                    <div class="d-flex align-items-center gap-2">
                                                        <div class="progress progress-xs flex-grow-1">
                                                            <div
                                                                class="progress-bar"
                                                                :class="group.progress === 100 ? 'bg-success' : 'bg-primary'"
                                                                :style="{ width: `${group.progress}%` }"
                                                            ></div>
                                                        </div>
                                                        <span class="fs-12 text-muted">{{ group.progress }}%</span>
                                                    </div>
                                                </td>
                                                <td>
                                                    <template v-if="group.is_published">
                                                        <span class="badge bg-success-transparent">
                                                            {{ t('language_translations.version', { version: group.version }) }}
                                                        </span>
                                                        <span class="d-block text-muted fs-11">{{ formatCatalogDate(group.published_at, locale) }}</span>
                                                    </template>
                                                    <span v-else class="text-muted fs-12">{{ t('language_translations.not_published') }}</span>
                                                </td>
                                                <td class="text-end">
                                                    <div v-if="isPending(platform.platform, group.group)" class="d-flex flex-column align-items-end gap-1">
                                                        <span class="fs-12 text-wrap text-end" style="max-width: 260px;">
                                                            {{ pendingAction.type === 'publish' ? t('language_translations.confirm_publish') : t('language_translations.confirm_discard') }}
                                                        </span>
                                                        <div class="btn-list">
                                                            <button type="button" class="btn btn-sm btn-light" :disabled="busy" @click="pendingAction = null">
                                                                {{ t('language_translations.cancel') }}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm"
                                                                :class="pendingAction.type === 'publish' ? 'btn-success' : 'btn-danger'"
                                                                :disabled="busy"
                                                                @click="runPendingAction"
                                                            >
                                                                {{ pendingAction.type === 'publish' ? t('language_translations.publish') : t('language_translations.discard_draft') }}
                                                            </button>
                                                        </div>
                                                    </div>
                                                    <div v-else class="btn-list justify-content-end">
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-light"
                                                            :disabled="busy || ! group.base_available"
                                                            @click="downloadGroup(platform.platform, group.group, 'csv')"
                                                        >
                                                            <i class="ri-download-2-line me-1"></i>{{ t('language_translations.export_csv') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-sm btn-light"
                                                            :disabled="busy || ! group.base_available"
                                                            @click="downloadGroup(platform.platform, group.group, 'json')"
                                                        >
                                                            {{ t('language_translations.export_json') }}
                                                        </button>
                                                        <template v-if="canUpdate">
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm btn-info-light"
                                                                :disabled="busy || ! group.base_available"
                                                                @click="openImport(platform.platform, group.group)"
                                                            >
                                                                <i class="ri-upload-2-line me-1"></i>{{ t('language_translations.import') }}
                                                            </button>
                                                            <button
                                                                v-if="group.has_draft"
                                                                type="button"
                                                                class="btn btn-sm btn-success-light"
                                                                :disabled="busy"
                                                                @click="pendingAction = { type: 'publish', platform: platform.platform, group: group.group }"
                                                            >
                                                                <i class="ri-send-plane-line me-1"></i>{{ t('language_translations.publish') }}
                                                            </button>
                                                            <button
                                                                v-if="group.has_draft"
                                                                type="button"
                                                                class="btn btn-sm btn-danger-light btn-icon"
                                                                :title="t('language_translations.discard_draft')"
                                                                :disabled="busy"
                                                                @click="pendingAction = { type: 'discard', platform: platform.platform, group: group.group }"
                                                            >
                                                                <i class="ri-delete-bin-line"></i>
                                                            </button>
                                                        </template>
                                                    </div>
                                                </td>
                                            </tr>

                                            <tr v-if="isImporting(platform.platform, group.group)">
                                                <td colspan="8" class="bg-light">
                                                    <div class="p-2">
                                                        <p class="fw-semibold mb-2">
                                                            {{ t('language_translations.import_title', { group: `${platform.platform} / ${group.group}` }) }}
                                                        </p>
                                                        <label class="form-label fs-12 text-muted" :for="`translation-file-${platform.platform}-${group.group}`">
                                                            {{ t('language_translations.choose_file') }}
                                                        </label>
                                                        <div class="d-flex flex-wrap gap-2 align-items-center">
                                                            <input
                                                                :id="`translation-file-${platform.platform}-${group.group}`"
                                                                type="file"
                                                                accept=".json,.csv,application/json,text/csv"
                                                                class="form-control form-control-sm w-auto"
                                                                @change="onFileChange"
                                                            >
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm btn-primary"
                                                                :disabled="busy || ! importFile"
                                                                @click="validateImport"
                                                            >
                                                                {{ t('language_translations.validate') }}
                                                            </button>
                                                            <button
                                                                type="button"
                                                                class="btn btn-sm btn-success"
                                                                :disabled="busy || ! importReport?.valid"
                                                                @click="saveDraft"
                                                            >
                                                                {{ t('language_translations.save_draft') }}
                                                            </button>
                                                            <button type="button" class="btn btn-sm btn-light" :disabled="busy" @click="closeImport">
                                                                {{ t('language_translations.cancel') }}
                                                            </button>
                                                        </div>

                                                        <div
                                                            v-if="importReport"
                                                            class="alert mt-3 mb-0"
                                                            :class="importReport.valid ? 'alert-success' : 'alert-danger'"
                                                        >
                                                            <p class="fw-semibold mb-1">
                                                                {{ t('language_translations.report_title') }}:
                                                                {{ importReport.valid ? t('language_translations.report_valid') : t('language_translations.report_invalid') }}
                                                            </p>
                                                            <p class="mb-1 fs-13">
                                                                {{ t('language_translations.report_counts', {
                                                                    translated: importReport.translated_keys ?? 0,
                                                                    total: importReport.total_keys ?? 0,
                                                                    missing: importReport.missing_keys ?? 0,
                                                                }) }}
                                                            </p>
                                                            <ul v-if="importReport.errors?.length" class="mb-1 fs-13 ps-3">
                                                                <li v-for="error in importReport.errors" :key="error.code">
                                                                    {{ error.message }}
                                                                    <span v-if="error.keys?.length" class="d-block text-break font-monospace fs-12">
                                                                        {{ error.keys.slice(0, 20).join(', ') }}
                                                                        <template v-if="error.keys.length > 20">
                                                                            {{ t('language_translations.report_more', { count: error.keys.length - 20 }) }}
                                                                        </template>
                                                                    </span>
                                                                </li>
                                                            </ul>
                                                            <details v-if="importReport.missing?.length" class="fs-12">
                                                                <summary>{{ t('language_translations.report_missing_list') }} ({{ importReport.missing_keys }})</summary>
                                                                <span class="d-block text-break font-monospace mt-1">
                                                                    {{ importReport.missing.join(', ') }}
                                                                    <template v-if="importReport.missing_keys > importReport.missing.length">
                                                                        {{ t('language_translations.report_more', { count: importReport.missing_keys - importReport.missing.length }) }}
                                                                    </template>
                                                                </span>
                                                            </details>
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" @click="close">{{ t('close') }}</button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import { useCatalogPermissions } from '../../../../../../composables/useCatalogPermissions';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../utils/catalog';

const props = defineProps({
    show: {
        type: Boolean,
        default: false,
    },
    language: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['close', 'changed']);

const { t, locale } = useI18n();
const { showSuccess, showError, showWarning } = useToast();
const { canUpdate } = useCatalogPermissions('languages');

const modalElement = ref(null);
const loading = ref(false);
const busy = ref(false);
const overview = ref(null);
const exportMode = ref('all');
const importTarget = ref(null);
const importFile = ref(null);
const importReport = ref(null);
const pendingAction = ref(null);
let modalInstance = null;

function baseUrl() {
    return `/api/admin/v1/languages/${props.language?.id}/translations`;
}

function platformLabel(platform) {
    return t(`language_translations.platform_${platform}`);
}

function statusBadgeClass(status) {
    return {
        published: 'bg-success-transparent text-success',
        draft: 'bg-warning-transparent text-warning',
    }[status] ?? 'bg-light text-muted';
}

async function fetchOverview() {
    if (! props.language?.id) {
        return;
    }

    loading.value = true;

    try {
        const { data } = await adminAxios.get(baseUrl());

        overview.value = data.data;
    } catch (error) {
        overview.value = null;
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function isImporting(platform, group) {
    return importTarget.value?.platform === platform && importTarget.value?.group === group;
}

function isPending(platform, group) {
    return pendingAction.value?.platform === platform && pendingAction.value?.group === group;
}

function openImport(platform, group) {
    importTarget.value = { platform, group };
    importFile.value = null;
    importReport.value = null;
    pendingAction.value = null;
}

function closeImport() {
    importTarget.value = null;
    importFile.value = null;
    importReport.value = null;
}

function onFileChange(event) {
    importFile.value = event.target.files?.[0] ?? null;
    importReport.value = null;
}

function importFormData() {
    const formData = new FormData();

    formData.append('file', importFile.value);

    return formData;
}

function reportFromError(error) {
    const report = error.response?.data?.data?.report;

    if (report) {
        return { ...report, valid: false };
    }

    const fileErrors = error.response?.data?.errors?.file;

    if (fileErrors?.length) {
        return { valid: false, errors: fileErrors.map((message, index) => ({ code: `file-${index}`, message, keys: [] })) };
    }

    return null;
}

async function submitImport(action) {
    const { platform, group } = importTarget.value;

    return adminAxios.post(`${baseUrl()}/${platform}/${group}/${action}`, importFormData());
}

async function validateImport() {
    if (! importTarget.value || ! importFile.value) {
        return;
    }

    busy.value = true;

    try {
        const response = await submitImport('validate');

        importReport.value = { ...response.data.data.report, valid: true };
    } catch (error) {
        importReport.value = reportFromError(error);
        showWarning(extractApiErrorMessage(error, t('toast.validation_error')));
    } finally {
        busy.value = false;
    }
}

async function saveDraft() {
    if (! importTarget.value || ! importFile.value) {
        return;
    }

    busy.value = true;

    try {
        const response = await submitImport('import');

        showSuccess(extractApiMessage(response, t('toast.updated')));
        closeImport();
        await fetchOverview();
        emit('changed');
    } catch (error) {
        importReport.value = reportFromError(error);
        showWarning(extractApiErrorMessage(error, t('toast.validation_error')));
    } finally {
        busy.value = false;
    }
}

async function runPendingAction() {
    const action = pendingAction.value;

    if (! action) {
        return;
    }

    busy.value = true;

    try {
        const url = `${baseUrl()}/${action.platform}/${action.group}`;
        const response = action.type === 'publish'
            ? await adminAxios.post(`${url}/publish`)
            : await adminAxios.delete(`${url}/draft`);

        showSuccess(extractApiMessage(response, t('toast.updated')));
        pendingAction.value = null;
        await fetchOverview();
        emit('changed');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busy.value = false;
    }
}

function filenameFromResponse(response, fallback) {
    const header = response.headers?.['content-disposition'] ?? '';
    const match = /filename="?([^";]+)"?/i.exec(header);

    return match?.[1] ?? fallback;
}

async function blobErrorMessage(error) {
    const data = error.response?.data;

    if (data instanceof Blob) {
        try {
            return JSON.parse(await data.text())?.message;
        } catch {
            return null;
        }
    }

    return extractApiErrorMessage(error);
}

async function download(url, params, fallbackName) {
    busy.value = true;

    try {
        const response = await adminAxios.get(url, { params, responseType: 'blob' });
        const objectUrl = URL.createObjectURL(response.data);
        const link = document.createElement('a');

        link.href = objectUrl;
        link.download = filenameFromResponse(response, fallbackName);
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(objectUrl);
    } catch (error) {
        showError((await blobErrorMessage(error)) || t('toast.error'));
    } finally {
        busy.value = false;
    }
}

function downloadGroup(platform, group, format) {
    const code = overview.value?.language?.code ?? 'lang';

    download(
        `${baseUrl()}/${platform}/${group}/export`,
        { format, mode: exportMode.value },
        `dorr-${code}-${platform}-${group}.${format}`,
    );
}

function downloadAndroidZip(source) {
    const code = overview.value?.language?.code ?? 'lang';

    download(`${baseUrl()}/android/export`, { source }, `dorr-android-${code}.zip`);
}

function openModal() {
    if (! modalElement.value) {
        return;
    }

    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function close() {
    modalInstance?.hide();
    emit('close');
}

function onModalHidden() {
    closeImport();
    pendingAction.value = null;
    emit('close');
}

watch(
    () => props.show,
    (visible) => {
        if (visible) {
            overview.value = null;
            closeImport();
            pendingAction.value = null;
            openModal();
            fetchOverview();
        } else {
            modalInstance?.hide();
        }
    },
);

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', onModalHidden);
});

onUnmounted(() => {
    modalElement.value?.removeEventListener('hidden.bs.modal', onModalHidden);
    modalInstance?.dispose();
});
</script>

<style scoped>
.translations-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.translations-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
}
</style>
