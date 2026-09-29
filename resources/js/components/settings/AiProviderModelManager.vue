<template>
    <div class="ai-provider-models mt-3 pt-3 border-top">
        <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
            <button type="button" class="btn btn-outline-primary btn-sm fs-12" @click="openModal">
                <i class="ri-settings-4-line align-middle"></i>
                {{ t('ai_settings.models.section_title') }}
                <span class="badge bg-light text-dark fs-11 ms-1">{{ models.length }}</span>
            </button>

            <span v-if="defaultModel" class="fs-12 text-muted">
                {{ t('ai_settings.models.default_badge') }}: <strong>{{ defaultModel.display_name }}</strong>
            </span>
        </div>

        <div ref="modalElement" class="modal fade" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-xl ai-provider-models-modal">
                <div class="modal-content">
                    <div class="modal-header catalog-modal-header">
                        <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                            <h6 class="modal-title mb-0">
                                {{ t('ai_settings.models.section_title') }} — {{ provider.name }}
                            </h6>
                            <button type="button" class="btn-close" aria-label="Close" @click="closeModal"></button>
                        </div>
                    </div>

                    <div class="modal-body px-4 pb-2">
                        <p v-if="models.length === 0 && !showAddForm" class="fs-12 text-muted mb-2">
                            {{ t('ai_settings.models.empty_hint') }}
                        </p>

                        <template v-if="models.length > 0">
                            <div class="d-flex align-items-center flex-wrap gap-2 mb-2">
                                <input
                                    v-model="search"
                                    type="search"
                                    class="form-control form-control-sm"
                                    style="max-width: 220px;"
                                    :placeholder="t('ai_settings.models.search_placeholder')"
                                >
                                <select v-model="capabilityFilter" class="form-select form-select-sm" style="max-width: 180px;">
                                    <option value="">{{ t('ai_settings.models.all_capabilities') }}</option>
                                    <option v-for="option in capabilityOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <select v-model="categoryFilter" class="form-select form-select-sm" style="max-width: 180px;">
                                    <option value="">{{ t('ai_settings.models.all_categories') }}</option>
                                    <option v-for="option in categoryOptions" :key="option.value" :value="option.value">
                                        {{ option.label }}
                                    </option>
                                </select>
                                <select v-model="statusFilter" class="form-select form-select-sm" style="max-width: 150px;">
                                    <option value="">{{ t('ai_settings.models.all_statuses') }}</option>
                                    <option value="active">{{ t('ai_settings.enabled') }}</option>
                                    <option value="disabled">{{ t('ai_settings.disabled') }}</option>
                                    <option value="deprecated">{{ t('ai_settings.models.status_deprecated') }}</option>
                                </select>
                                <div class="form-check form-check-inline m-0 fs-12">
                                    <input
                                        id="active-only-filter"
                                        v-model="activeOnly"
                                        type="checkbox"
                                        class="form-check-input"
                                    >
                                    <label class="form-check-label" for="active-only-filter">
                                        {{ t('ai_settings.models.active_only') }}
                                    </label>
                                </div>
                                <div class="form-check form-check-inline m-0 fs-12">
                                    <input
                                        id="hide-snapshots-filter"
                                        v-model="hideSnapshots"
                                        type="checkbox"
                                        class="form-check-input"
                                    >
                                    <label class="form-check-label" :title="t('ai_settings.models.hide_snapshots_hint')" for="hide-snapshots-filter">
                                        {{ t('ai_settings.models.hide_snapshots') }}
                                    </label>
                                </div>
                                <span v-if="hiddenSnapshotCount > 0" class="fs-11 text-muted">
                                    {{ t('ai_settings.models.snapshots_hidden_count', { count: hiddenSnapshotCount }) }}
                                </span>
                                <span class="fs-11 text-muted ms-auto">
                                    {{ t('ai_settings.models.showing_count', { shown: filteredModels.length, total: models.length }) }}
                                </span>
                            </div>

                            <div class="table-responsive ai-provider-models-table-wrap">
                                <table class="table table-sm text-nowrap align-middle mb-0">
                                    <thead class="sticky-top">
                                        <tr class="fs-11 text-muted text-uppercase">
                                            <th scope="col">{{ t('ai_settings.models.column_model') }}</th>
                                            <th scope="col" style="width: 8rem;">{{ t('ai_settings.models.column_category') }}</th>
                                            <th scope="col">{{ t('ai_settings.models.column_capabilities') }}</th>
                                            <th scope="col" style="width: 7.5rem;">{{ t('ai_settings.temperature') }}</th>
                                            <th scope="col" style="width: 8.5rem;">{{ t('ai_settings.max_tokens') }}</th>
                                            <th scope="col" style="width: 9rem;" :title="t('ai_settings.models.limits_hint')">
                                                {{ t('ai_settings.models.column_limits') }}
                                            </th>
                                            <th scope="col" style="width: 6rem;">{{ t('ai_settings.models.column_status') }}</th>
                                            <th scope="col" style="width: 11.5rem;" class="text-end">{{ t('ai_settings.models.column_actions') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="model in filteredModels" :key="model.id" :class="{ 'ai-provider-model-row--inactive': !model.is_active }">
                                            <td>
                                                <div class="fw-semibold fs-13">{{ model.display_name }}</div>
                                                <code class="fs-10 text-muted">{{ model.model_key }}</code>
                                                <div class="fs-10 text-muted mt-1 d-flex flex-wrap gap-1 align-items-center">
                                                    <span v-if="model.model_family && model.model_family !== model.model_key">
                                                        {{ t('ai_settings.models.family_prefix') }}: {{ model.model_family }}
                                                    </span>
                                                    <span v-if="model.is_snapshot" class="badge bg-secondary-transparent fs-10">
                                                        {{ t('ai_settings.models.badge_snapshot') }}
                                                    </span>
                                                    <span v-if="model.is_alias" class="badge bg-secondary-transparent fs-10" :title="model.canonical_model_id">
                                                        {{ t('ai_settings.models.badge_alias') }}
                                                    </span>
                                                    <span v-if="model.last_seen_at" :title="t('ai_settings.models.last_seen_hint')">
                                                        {{ t('ai_settings.models.last_seen_prefix') }}: {{ formatShortDate(model.last_seen_at) }}
                                                    </span>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary-transparent fs-10">
                                                    {{ categoryLabel(model.category) }}
                                                </span>
                                                <div v-if="model.needs_review" class="fs-10 text-warning mt-1">
                                                    <i class="ri-error-warning-line align-middle"></i>
                                                    {{ t('ai_settings.models.needs_review') }}
                                                </div>
                                                <div v-else-if="model.status === 'deprecated'" class="fs-10 text-muted mt-1">
                                                    {{ t('ai_settings.disabled') }}
                                                </div>
                                            </td>
                                            <td class="text-wrap" style="min-width: 14rem;">
                                                <template v-if="editingId === model.id">
                                                    <div class="d-flex flex-wrap gap-2">
                                                        <div
                                                            v-for="option in capabilityOptions"
                                                            :key="option.value"
                                                            class="form-check form-check-inline m-0"
                                                        >
                                                            <input
                                                                :id="`${provider.key}-edit-cap-${model.id}-${option.value}`"
                                                                v-model="editCapabilities"
                                                                class="form-check-input"
                                                                type="checkbox"
                                                                :value="option.value"
                                                            >
                                                            <label class="form-check-label fs-11" :for="`${provider.key}-edit-cap-${model.id}-${option.value}`">
                                                                {{ option.label }}
                                                            </label>
                                                        </div>
                                                    </div>
                                                    <div class="d-flex flex-wrap align-items-center gap-2 mt-2">
                                                        <div class="form-check form-check-inline m-0">
                                                            <input
                                                                :id="`${provider.key}-edit-temp-supported-${model.id}`"
                                                                v-model="editTemperatureSupported"
                                                                class="form-check-input"
                                                                type="checkbox"
                                                            >
                                                            <label class="form-check-label fs-11" :for="`${provider.key}-edit-temp-supported-${model.id}`">
                                                                {{ t('ai_settings.models.edit_temperature_supported') }}
                                                            </label>
                                                        </div>
                                                        <input
                                                            v-model="editContextWindow"
                                                            type="number"
                                                            min="1"
                                                            class="form-control form-control-sm"
                                                            style="max-width: 9rem;"
                                                            :placeholder="t('ai_settings.models.context_window_placeholder')"
                                                        >
                                                        <input
                                                            v-model="editMaxOutputTokens"
                                                            type="number"
                                                            min="1"
                                                            class="form-control form-control-sm"
                                                            style="max-width: 9rem;"
                                                            :placeholder="t('ai_settings.models.max_output_tokens_placeholder')"
                                                        >
                                                    </div>
                                                    <div class="d-flex gap-1 mt-1">
                                                        <button
                                                            type="button"
                                                            class="btn btn-primary btn-sm py-0 px-2 fs-11"
                                                            :disabled="busyId === model.id || editCapabilities.length === 0"
                                                            @click="saveCapabilities(model)"
                                                        >
                                                            {{ t('save') }}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            class="btn btn-light btn-sm py-0 px-2 fs-11"
                                                            :disabled="busyId === model.id"
                                                            @click="cancelEditCapabilities"
                                                        >
                                                            {{ t('cancel') }}
                                                        </button>
                                                    </div>
                                                </template>
                                                <template v-else>
                                                    <span
                                                        v-for="capability in model.capabilities.slice(0, 3)"
                                                        :key="capability"
                                                        class="badge bg-info-transparent fs-10 me-1"
                                                    >
                                                        {{ capabilityLabel(capability) }}
                                                    </span>
                                                    <span v-if="model.capabilities.length > 3" class="fs-10 text-muted">
                                                        +{{ model.capabilities.length - 3 }}
                                                    </span>
                                                </template>
                                            </td>
                                            <td>
                                                <input
                                                    v-if="model.temperature_supported"
                                                    :value="model.temperature"
                                                    type="number"
                                                    step="0.1"
                                                    min="0"
                                                    max="2"
                                                    class="form-control form-control-sm"
                                                    :title="t('ai_settings.models.override_hint')"
                                                    @change="updateOverrides(model, { temperature: parseOverrideNumber($event.target.value) })"
                                                >
                                                <span v-else class="fs-11 text-muted" :title="t('ai_settings.models.temperature_not_supported_hint')">
                                                    {{ t('ai_settings.models.temperature_not_supported') }}
                                                </span>
                                            </td>
                                            <td>
                                                <input
                                                    :value="model.max_tokens"
                                                    type="number"
                                                    step="1"
                                                    min="1"
                                                    class="form-control form-control-sm"
                                                    :title="t('ai_settings.models.override_hint')"
                                                    @change="updateOverrides(model, { max_tokens: parseOverrideNumber($event.target.value) })"
                                                >
                                            </td>
                                            <td class="fs-11">
                                                <div v-if="model.context_window || model.max_output_tokens">
                                                    <div v-if="model.context_window">
                                                        {{ t('ai_settings.models.context_window_prefix') }}: {{ formatTokenCount(model.context_window) }}
                                                    </div>
                                                    <div v-if="model.max_output_tokens">
                                                        {{ t('ai_settings.models.max_output_prefix') }}: {{ formatTokenCount(model.max_output_tokens) }}
                                                    </div>
                                                </div>
                                                <span v-else class="text-muted">&mdash;</span>
                                            </td>
                                            <td>
                                                <span v-if="model.is_default" class="badge bg-primary-transparent fs-10">
                                                    {{ t('ai_settings.models.default_badge') }}
                                                </span>
                                                <span v-else-if="!model.is_active" class="badge bg-secondary-transparent fs-10">
                                                    {{ t('ai_settings.disabled') }}
                                                </span>
                                                <span v-else class="badge bg-success-transparent fs-10">
                                                    {{ t('ai_settings.enabled') }}
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <div class="btn-list justify-content-end flex-nowrap">
                                                    <button
                                                        v-if="editingId !== model.id"
                                                        type="button"
                                                        class="btn btn-sm btn-secondary-light btn-icon"
                                                        :disabled="busyId === model.id"
                                                        :title="t('ai_settings.models.edit_capabilities')"
                                                        @click="startEditCapabilities(model)"
                                                    >
                                                        <i class="ri-edit-line"></i>
                                                    </button>
                                                    <button
                                                        v-if="!model.is_default"
                                                        type="button"
                                                        class="btn btn-sm btn-secondary-light btn-icon"
                                                        :disabled="busyId === model.id"
                                                        :title="t('ai_settings.models.make_default')"
                                                        @click="setAsDefault(model)"
                                                    >
                                                        <i class="ri-star-line"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-secondary-light btn-icon"
                                                        :disabled="busyId === model.id"
                                                        :title="model.is_active ? t('ai_settings.models.deactivate') : t('ai_settings.models.activate')"
                                                        @click="toggleActive(model)"
                                                    >
                                                        <i :class="model.is_active ? 'ri-pause-circle-line' : 'ri-play-circle-line'"></i>
                                                    </button>
                                                    <button
                                                        type="button"
                                                        class="btn btn-sm btn-danger-light btn-icon"
                                                        :disabled="busyId === model.id"
                                                        :title="t('ai_settings.models.remove')"
                                                        @click="removeModel(model)"
                                                    >
                                                        <i class="ri-delete-bin-line"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <p v-if="filteredModels.length === 0" class="fs-12 text-muted text-center py-3 mb-0">
                                {{ t('ai_settings.models.no_matches') }}
                            </p>
                        </template>

                        <form
                            v-show="showAddForm || models.length === 0"
                            class="ai-provider-model-add mt-2 p-2 rounded border border-dashed"
                            @submit.prevent="addModel"
                        >
                            <div class="row g-2">
                                <div class="col-sm-3">
                                    <input
                                        v-model="newModel.model_key"
                                        type="text"
                                        class="form-control form-control-sm"
                                        :placeholder="t('ai_settings.models.key_placeholder')"
                                        required
                                    >
                                </div>
                                <div class="col-sm-3">
                                    <input
                                        v-model="newModel.display_name"
                                        type="text"
                                        class="form-control form-control-sm"
                                        :placeholder="t('ai_settings.models.name_placeholder')"
                                    >
                                </div>
                                <div class="col-sm-2">
                                    <input
                                        v-model="newModel.temperature"
                                        type="number"
                                        step="0.1"
                                        min="0"
                                        max="2"
                                        class="form-control form-control-sm"
                                        :placeholder="t('ai_settings.models.temperature_placeholder')"
                                    >
                                </div>
                                <div class="col-sm-2">
                                    <input
                                        v-model="newModel.max_tokens"
                                        type="number"
                                        step="1"
                                        min="1"
                                        class="form-control form-control-sm"
                                        :placeholder="t('ai_settings.models.max_tokens_placeholder')"
                                    >
                                </div>
                                <div class="col-sm-2">
                                    <button type="submit" class="btn btn-primary btn-sm w-100" :disabled="adding || newModel.capabilities.length === 0">
                                        <span v-if="adding" class="spinner-border spinner-border-sm"></span>
                                        <i v-else class="ri-add-line"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <div v-for="option in capabilityOptions" :key="option.value" class="form-check form-check-inline m-0">
                                    <input
                                        :id="`${provider.key}-cap-${option.value}`"
                                        v-model="newModel.capabilities"
                                        class="form-check-input"
                                        type="checkbox"
                                        :value="option.value"
                                    >
                                    <label class="form-check-label fs-11" :for="`${provider.key}-cap-${option.value}`">
                                        {{ option.label }}
                                    </label>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div class="modal-footer catalog-modal-footer">
                        <button type="button" class="btn btn-light" @click="closeModal">{{ t('close') }}</button>
                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            :disabled="syncing"
                            :title="t('ai_settings.models.sync_hint')"
                            @click="syncModels"
                        >
                            <span v-if="syncing" class="spinner-border spinner-border-sm align-middle me-1"></span>
                            <i v-else class="ri-refresh-line align-middle"></i>
                            {{ syncing ? t('ai_settings.models.syncing') : t('ai_settings.models.sync') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            :disabled="normalizing || models.length === 0"
                            :title="t('ai_settings.models.normalize_hint')"
                            @click="normalizeClassification"
                        >
                            <span v-if="normalizing" class="spinner-border spinner-border-sm align-middle me-1"></span>
                            <i v-else class="ri-list-check-2 align-middle"></i>
                            {{ normalizing ? t('ai_settings.models.normalizing') : t('ai_settings.models.normalize') }}
                        </button>
                        <button
                            type="button"
                            class="btn btn-outline-secondary btn-sm"
                            :disabled="reclassifying || models.length === 0"
                            :title="t('ai_settings.models.reclassify_hint')"
                            @click="reclassifyWithAi"
                        >
                            <span v-if="reclassifying" class="spinner-border spinner-border-sm align-middle me-1"></span>
                            <i v-else class="ri-magic-line align-middle"></i>
                            {{ reclassifying ? t('ai_settings.models.reclassifying') : t('ai_settings.models.reclassify') }}
                        </button>
                        <button type="button" class="btn btn-outline-primary btn-sm" @click="showAddForm = !showAddForm">
                            <i class="ri-add-line align-middle"></i>
                            {{ t('ai_settings.models.add_model') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../composables/useToast';

const props = defineProps({
    provider: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['refresh-all']);

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const modalElement = ref(null);
let modalInstance = null;

const adding = ref(false);
const reclassifying = ref(false);
const syncing = ref(false);
const normalizing = ref(false);
const busyId = ref(null);
const showAddForm = ref(false);
const search = ref('');
const capabilityFilter = ref('');
const categoryFilter = ref('');
// '' = all, 'active' = is_active && status !== deprecated, 'disabled' =
// !is_active && status !== deprecated (an admin manually turned it off),
// 'deprecated' = status === deprecated (no longer offered by the
// provider). Distinct from the older activeOnly boolean below, which
// only ever expressed the first split (on/off) - kept alongside it since
// some admins may still rely on the simple toggle, but this is the
// precise three-way split the model actually has.
const statusFilter = ref('');
const activeOnly = ref(false);
// Defaults to true: a real provider catalog includes a dated snapshot
// row per release (gpt-4o-2024-05-13, gpt-4o-2024-08-06, ...) alongside
// its base id - genuinely useful to keep in the registry (routing can
// still reach them), but overwhelming to scroll through by default, so
// they start hidden and the admin can reveal them on demand.
const hideSnapshots = ref(true);
// Which existing registered row (if any) has its capabilities checklist
// open for editing - only one at a time, closed whenever the modal closes.
const editingId = ref(null);
const editCapabilities = ref([]);
const editTemperatureSupported = ref(true);
const editContextWindow = ref('');
const editMaxOutputTokens = ref('');

const models = computed(() => props.provider.registered_models ?? []);
const capabilityOptions = computed(() => props.provider.capability_options ?? []);
const categoryOptions = computed(() => props.provider.category_options ?? []);
const defaultModel = computed(() => models.value.find((model) => model.is_default) ?? null);

// Some providers now register dozens of real models at once (auto-sync on
// test connection), so the list needs to be searchable/filterable rather
// than always rendering every row - a long flat stack of tall cards was
// the exact problem this redesign fixes. Moving the whole manager into a
// popup keeps the provider card itself short no matter how many models a
// provider ends up with.
const filteredModels = computed(() => {
    const query = search.value.trim().toLowerCase();

    return models.value.filter((model) => {
        if (activeOnly.value && !model.is_active) {
            return false;
        }

        if (hideSnapshots.value && model.is_snapshot) {
            return false;
        }

        if (categoryFilter.value && (model.category ?? null) !== categoryFilter.value) {
            return false;
        }

        if (statusFilter.value === 'active' && (!model.is_active || model.status === 'deprecated')) {
            return false;
        }

        if (statusFilter.value === 'disabled' && (model.is_active || model.status === 'deprecated')) {
            return false;
        }

        if (statusFilter.value === 'deprecated' && model.status !== 'deprecated') {
            return false;
        }

        if (capabilityFilter.value && !model.capabilities.includes(capabilityFilter.value)) {
            return false;
        }

        if (query === '') {
            return true;
        }

        return model.model_key.toLowerCase().includes(query)
            || (model.display_name ?? '').toLowerCase().includes(query);
    });
});

// Counted separately from filteredModels so the hint stays accurate even
// while the admin is also searching/filtering by capability.
const hiddenSnapshotCount = computed(() => {
    if (! hideSnapshots.value) {
        return 0;
    }

    return models.value.filter((model) => model.is_snapshot).length;
});

const newModel = reactive({
    model_key: '',
    display_name: '',
    capabilities: [],
    temperature: '',
    max_tokens: '',
});

/**
 * A blank input must clear an existing override (send null), not be
 * silently dropped from the request - omitting the key would leave the
 * previous override in place instead of reverting to "inherit from the
 * provider", which is what an emptied field visually implies.
 */
function parseOverrideNumber(value) {
    return value === '' || value === null ? null : Number(value);
}

function capabilityLabel(value) {
    return capabilityOptions.value.find((option) => option.value === value)?.label ?? value;
}

// Compact, locale-agnostic thousands display (128000 -> "128K") - purely
// a table-density convenience, the raw number is always in the title/
// edit inputs for anyone who needs the exact figure.
function formatTokenCount(value) {
    if (value === null || value === undefined) {
        return '—';
    }

    if (value >= 1000) {
        return `${Math.round(value / 1000)}K`;
    }

    return String(value);
}

function formatShortDate(value) {
    if (! value) {
        return '';
    }

    try {
        return new Date(value).toLocaleDateString();
    } catch {
        return '';
    }
}

function categoryLabel(value) {
    if (! value || value === 'unknown') {
        return t('ai_settings.models.category_unknown');
    }

    return categoryOptions.value.find((option) => option.value === value)?.label ?? value;
}

function openModal() {
    if (! modalElement.value) return;
    modalInstance ??= new window.bootstrap.Modal(modalElement.value, { focus: false });
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

onMounted(() => {
    modalElement.value?.addEventListener('hidden.bs.modal', () => {
        showAddForm.value = false;
        search.value = '';
        capabilityFilter.value = '';
        activeOnly.value = false;
        hideSnapshots.value = true;
        categoryFilter.value = '';
        statusFilter.value = '';
        editingId.value = null;
        editCapabilities.value = [];
        editTemperatureSupported.value = true;
        editContextWindow.value = '';
        editMaxOutputTokens.value = '';
    });
});

onUnmounted(() => {
    modalInstance?.dispose();
});

async function syncModels() {
    syncing.value = true;

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/ai-providers/${props.provider.key}/models/sync`);
        emit('refresh-all');
        showSuccess(data?.message || t('ai_settings.models.synced'));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        syncing.value = false;
    }
}

async function normalizeClassification() {
    normalizing.value = true;

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/ai-providers/${props.provider.key}/models/normalize`);
        emit('refresh-all');
        showSuccess(data?.message || t('ai_settings.models.normalized'));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        normalizing.value = false;
    }
}

async function reclassifyWithAi() {
    reclassifying.value = true;

    try {
        const { data } = await adminAxios.post(`/api/admin/v1/ai-providers/${props.provider.key}/models/reclassify`);
        emit('refresh-all');
        showSuccess(data?.message || t('ai_settings.models.reclassified'));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        reclassifying.value = false;
    }
}

async function addModel() {
    adding.value = true;

    try {
        await adminAxios.post(`/api/admin/v1/ai-providers/${props.provider.key}/models`, {
            ...newModel,
            temperature: parseOverrideNumber(newModel.temperature),
            max_tokens: parseOverrideNumber(newModel.max_tokens),
        });
        newModel.model_key = '';
        newModel.display_name = '';
        newModel.capabilities = [];
        newModel.temperature = '';
        newModel.max_tokens = '';
        showAddForm.value = false;
        emit('refresh-all');
        showSuccess(t('ai_settings.models.added'));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        adding.value = false;
    }
}

function startEditCapabilities(model) {
    editingId.value = model.id;
    editCapabilities.value = [...model.capabilities];
    // NULL (not computed yet) reads as "supported" here too, matching
    // the same fallback the backend resource and the temperature column
    // above both already use - editing never turns a not-yet-known model
    // into an explicitly unsupported one just because the checkbox has
    // to start somewhere.
    editTemperatureSupported.value = model.temperature_supported ?? true;
    editContextWindow.value = model.context_window ?? '';
    editMaxOutputTokens.value = model.max_output_tokens ?? '';
}

function cancelEditCapabilities() {
    editingId.value = null;
    editCapabilities.value = [];
    editContextWindow.value = '';
    editMaxOutputTokens.value = '';
}

// Root-cause fix: an already-registered model's capabilities (and,
// alongside them, whether it genuinely accepts a temperature parameter
// and its real published context window / max output tokens) could
// previously only ever be corrected by deleting and re-adding the model
// (or, for capabilities, by re-running "Reclassify with AI" and hoping
// for a better answer) - there was no way to fix a single wrong value by
// hand. Reuses the same PUT .../models/{id} endpoint the temperature/
// max-tokens inline overrides already use; the backend already accepts
// and validates all four fields on update.
async function saveCapabilities(model) {
    busyId.value = model.id;

    try {
        await adminAxios.put(`/api/admin/v1/ai-providers/${props.provider.key}/models/${model.id}`, {
            capabilities: editCapabilities.value,
            temperature_supported: editTemperatureSupported.value,
            context_window: parseOverrideNumber(editContextWindow.value),
            max_output_tokens: parseOverrideNumber(editMaxOutputTokens.value),
        });
        emit('refresh-all');
        showSuccess(t('ai_settings.models.overrides_updated'));
        editingId.value = null;
        editCapabilities.value = [];
        editContextWindow.value = '';
        editMaxOutputTokens.value = '';
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busyId.value = null;
    }
}

async function updateOverrides(model, overrides) {
    busyId.value = model.id;

    try {
        await adminAxios.put(`/api/admin/v1/ai-providers/${props.provider.key}/models/${model.id}`, overrides);
        emit('refresh-all');
        showSuccess(t('ai_settings.models.overrides_updated'));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busyId.value = null;
    }
}

async function setAsDefault(model) {
    busyId.value = model.id;

    try {
        await adminAxios.put(`/api/admin/v1/ai-providers/${props.provider.key}/models/${model.id}`, { is_default: true });
        emit('refresh-all');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busyId.value = null;
    }
}

async function toggleActive(model) {
    busyId.value = model.id;

    try {
        await adminAxios.put(`/api/admin/v1/ai-providers/${props.provider.key}/models/${model.id}`, { is_active: ! model.is_active });
        emit('refresh-all');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busyId.value = null;
    }
}

async function removeModel(model) {
    if (! window.confirm(t('ai_settings.models.confirm_delete'))) {
        return;
    }

    busyId.value = model.id;

    try {
        await adminAxios.delete(`/api/admin/v1/ai-providers/${props.provider.key}/models/${model.id}`);
        emit('refresh-all');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        busyId.value = null;
    }
}
</script>

<style scoped>
/* The registered-models table grew a lot of columns (category, limits,
   family/snapshot/alias/last-seen metadata, ...) - the default modal-xl
   width (1140px) was cramping every column, including the actions icons
   at the far end, which is exactly why "activate/deactivate" was hard
   to find: it was being pushed past the visible edge instead of hidden
   by any CSS. */
.ai-provider-models-modal {
    max-width: min(96vw, 1600px);
}

.ai-provider-models-table-wrap {
    max-height: 26rem;
    overflow-y: auto;
    border: 1px solid var(--bs-border-color, #e9edf1);
    border-radius: 0.375rem;
}

.ai-provider-models-table-wrap table thead th {
    position: sticky;
    top: 0;
    background: var(--bs-body-bg, #fff);
    z-index: 1;
}

.ai-provider-model-row--inactive {
    opacity: 0.6;
}

.ai-provider-models-table-wrap td,
.ai-provider-models-table-wrap th {
    padding-top: 0.4rem;
    padding-bottom: 0.4rem;
}
</style>
