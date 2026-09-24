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
                                            <span class="d-block fw-semibold">{{ preference.owner?.name ?? '-' }}</span>
                                            <span class="d-block text-muted fs-11">#{{ preference.owner?.id ?? '-' }}</span>
                                        </td>
                                        <td>{{ preference.language?.name || '-' }}</td>
                                        <td>{{ preference.variant?.name || '-' }}</td>
                                        <td>
                                            <span class="badge" :class="preference.auto_detect ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ preference.auto_detect ? t('yes') : t('no') }}
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ preference.response_language_mode }}</span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showError } = useToast();

const preferences = ref([]);
const loading = ref(true);

async function loadPreferences() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-user-language-preferences');
        preferences.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadPreferences());
</script>
