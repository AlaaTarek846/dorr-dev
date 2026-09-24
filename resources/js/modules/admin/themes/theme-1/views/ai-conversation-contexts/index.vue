<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_conversation_contexts.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_conversation_contexts.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_conversation_contexts.title') }}</li>
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
                                        <th scope="col">{{ t('ai_conversation_contexts.conversation') }}</th>
                                        <th scope="col">{{ t('ai_conversation_contexts.context_type') }}</th>
                                        <th scope="col">{{ t('ai_conversation_contexts.included') }}</th>
                                        <th scope="col">{{ t('ai_conversation_contexts.token_count') }}</th>
                                        <th scope="col">{{ t('ai_conversation_contexts.created_at') }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <TableSkeleton v-if="loading" :rows="5" :columns="5" />

                                    <tr v-else-if="!contexts.length">
                                        <td colspan="5" class="border-0">
                                            <div class="text-center py-5 text-muted">{{ t('ai_conversation_contexts.empty') }}</div>
                                        </td>
                                    </tr>

                                    <tr v-for="context in contexts" v-else :key="context.id">
                                        <td>
                                            <span class="d-block fw-semibold">{{ context.conversation?.title || '-' }}</span>
                                            <span class="d-block text-muted fs-11">#{{ context.conversation?.id ?? '-' }}</span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary-transparent">{{ context.context_type }}</span>
                                        </td>
                                        <td>
                                            <span class="badge" :class="context.included ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                                {{ context.included ? t('yes') : t('no') }}
                                            </span>
                                        </td>
                                        <td>{{ context.token_count }}</td>
                                        <td>{{ formatDateTime(context.created_at) }}</td>
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

const { t, locale } = useI18n();
const { showError } = useToast();

const contexts = ref([]);
const loading = ref(true);

function formatDateTime(value) {
    if (! value) return '-';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US');
}

async function loadContexts() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-conversation-contexts');
        contexts.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

onMounted(() => loadContexts());
</script>
