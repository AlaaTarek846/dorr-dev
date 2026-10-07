<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_user_language_preferences.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_user_language_preferences.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_user_language_preferences.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_user_language_preferences.owner') }}</th>
                                        <th scope="col">{{ t('ai_user_language_preferences.language') }}</th>
                                        <th scope="col">{{ t('ai_user_language_preferences.variant') }}</th>
                                        <th scope="col">{{ t('ai_user_language_preferences.auto_detect') }}</th>
                                        <th scope="col">{{ t('ai_user_language_preferences.response_language_mode') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!preferences.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_user_language_preferences.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="preference in preferences" v-else :key="preference.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ ownerDisplayName(preference.owner) }}</span>
                                            <span class="d-block text-muted fs-11">#{{ preference.owner?.id ?? '-' }}</span>
                                        </td>
                                        <td>
                                            <select
                                                class="form-select form-select-sm w-auto"
                                                :value="preference.language?.id ?? ''"
                                                @change="update(preference, { language_id: $event.target.value || null })"
                                            >
                                                <option value="">{{ t('ai_user_language_preferences.no_language') }}</option>
                                                <option v-for="language in languages" :key="language.id" :value="language.id">{{ language.name }}</option>
                                            </select>
                                        </td>
                                        <td>
                                            <select
                                                class="form-select form-select-sm w-auto"
                                                :value="preference.variant?.id ?? ''"
                                                @change="update(preference, { variant_id: $event.target.value || null })"
                                            >
                                                <option value="">{{ t('ai_user_language_preferences.no_variant') }}</option>
                                                <option
                                                    v-for="variant in variantsForLanguage(preference.language?.id)"
                                                    :key="variant.id"
                                                    :value="variant.id"
                                                >
                                                    {{ variant.name }}
                                                </option>
                                            </select>
                                        </td>
                                        <td>
                                            <div class="form-check form-switch">
                                                <input
                                                    class="form-check-input"
                                                    type="checkbox"
                                                    role="switch"
                                                    :checked="preference.auto_detect"
                                                    @change="update(preference, { auto_detect: $event.target.checked })"
                                                >
                                            </div>
                                        </td>
                                        <td>
                                            <select
                                                class="form-select form-select-sm w-auto"
                                                :value="preference.response_language_mode"
                                                @change="update(preference, { response_language_mode: $event.target.value })"
                                            >
                                                <option value="follow_input">{{ t('ai_user_language_preferences.mode_follow_input') }}</option>
                                                <option value="fixed">{{ t('ai_user_language_preferences.mode_fixed') }}</option>
                                            </select>
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
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import { ownerDisplayName } from '../../../../../../utils/aiOwner';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

/**
 * Business gap fix: PUT /api/admin/v1/ai-user-language-preferences/{id} has
 * always been a real, working endpoint (AiUserLanguagePreferenceUpdateRequest
 * even documents itself as "only the admin can set this for now"), but this
 * screen only ever rendered the table read-only, so the capability was
 * unreachable from the UI. Wired the same inline-edit-on-change pattern
 * ai-trial-control already uses for its own per-row admin overrides.
 */

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const preferences = ref([]);
const languages = ref([]);
const variants = ref([]);
const loading = ref(true);
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

function variantsForLanguage(languageId) {
    if (! languageId) {
        return variants.value;
    }

    return variants.value.filter((variant) => (variant.language?.id ?? variant.language_id) === languageId);
}

async function loadPreferences() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-user-language-preferences', { params: paginationParams.value });
        preferences.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function loadOptions() {
    try {
        const [languagesResponse, variantsResponse] = await Promise.all([
            adminAxios.get('/api/admin/v1/ai-languages', { params: { all: 1 } }),
            adminAxios.get('/api/admin/v1/ai-language-variants', { params: { all: 1 } }),
        ]);

        languages.value = languagesResponse.data.data ?? [];
        variants.value = variantsResponse.data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function update(preference, payload) {
    const previous = { ...preference };

    if ('language_id' in payload) {
        const language = languages.value.find((item) => String(item.id) === String(payload.language_id)) ?? null;
        preference.language = language;

        // Changing the language invalidates a variant that belonged to the
        // old one - clear it client-side and on the server in the same
        // request, matching what the dropdown will show right after.
        if (! variantsForLanguage(language?.id).some((variant) => variant.id === preference.variant?.id)) {
            preference.variant = null;
            payload.variant_id = null;
        }
    }

    if ('variant_id' in payload) {
        preference.variant = variants.value.find((item) => String(item.id) === String(payload.variant_id)) ?? null;
    }

    Object.assign(preference, payload);

    try {
        const response = await adminAxios.put(`/api/admin/v1/ai-user-language-preferences/${preference.id}`, payload);
        showSuccess(extractApiMessage(response, t('toast.updated')));
    } catch (error) {
        Object.assign(preference, previous);
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onChangePage(target) {
    page.value = target;
    loadPreferences();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadPreferences();
}

onMounted(() => {
    loadOptions();
    loadPreferences();
});
</script>
