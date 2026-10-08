<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_languages.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_languages.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_languages.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <!--
            Root-cause fix (languages consolidation): this screen used to
            create/edit/delete its own ai_languages rows (code/name/
            direction) - a redundant, driftable copy of the platform's
            general Languages table. It now only lists the platform's
            existing languages and toggles whether the AI assistant may
            use each one; adding, renaming or removing a language itself
            is done from the general Languages screen (it also affects
            the website/dashboard, so this screen should not do it too).
        -->
        <div class="row">
            <div class="col-xl-12">
                <div class="card custom-card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table text-nowrap table-striped table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th scope="col">{{ t('ai_languages.code') }}</th>
                                        <th scope="col">{{ t('ai_languages.name') }}</th>
                                        <th scope="col">{{ t('ai_languages.direction') }}</th>
                                        <th scope="col">{{ t('ai_languages.ai_enabled') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="4" />

                                    <tr v-else-if="!languages.length">
                                        <td colspan="4" class="border-0">
                                            <div class="text-center py-5">
                                                <p class="fw-semibold mb-1">{{ t('ai_languages.empty_title') }}</p>
                                                <p class="text-muted mb-0">{{ t('ai_languages.empty') }}</p>
                                            </div>
                                        </td>
                                    </tr>

                                    <tr v-for="language in languages" v-else :key="language.id">
                                        <td class="fw-semibold text-default">{{ language.code }}</td>
                                        <td>{{ language.name }}</td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ language.direction }}</span>
                                        </td>
                                        <td>
                                            <div
                                                class="toggle toggle-success mb-0"
                                                :class="{ on: language.ai_enabled }"
                                                role="button"
                                                tabindex="0"
                                                @click="toggleAiEnabled(language)"
                                                @keydown.enter.space.prevent="toggleAiEnabled(language)"
                                            >
                                                <span></span>
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
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import AdminPaginationFooter from '../../../../../../components/admin/AdminPaginationFooter.vue';
import useAdminPagination from '../../../../../../composables/useAdminPagination';

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const languages = ref([]);
const loading = ref(true);
const { page, perPage, pagination, paginationParams, applyPagination } = useAdminPagination();

async function loadLanguages() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-languages', { params: paginationParams.value });
        languages.value = data.data ?? [];
        applyPagination(data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function toggleAiEnabled(language) {
    const previous = language.ai_enabled;
    language.ai_enabled = ! language.ai_enabled;

    try {
        const response = await adminAxios.put(`/api/admin/v1/ai-languages/${language.id}`, { ai_enabled: language.ai_enabled });
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
    } catch (error) {
        language.ai_enabled = previous;
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onChangePage(target) {
    page.value = target;
    loadLanguages();
}

function onChangePerPage(value) {
    perPage.value = value;
    page.value = 1;
    loadLanguages();
}

onMounted(() => loadLanguages());
</script>
