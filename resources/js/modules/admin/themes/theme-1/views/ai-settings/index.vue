<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_settings.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_settings.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_settings.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div v-if="loading" class="row g-4">
            <div v-for="n in 4" :key="n" class="col-xl-6 col-12">
                <div class="card custom-card">
                    <div class="card-body">
                        <div class="placeholder-glow">
                            <span class="placeholder col-4 mb-3 d-block"></span>
                            <span class="placeholder col-12 mb-2 d-block"></span>
                            <span class="placeholder col-12 mb-2 d-block"></span>
                            <span class="placeholder col-6 d-block"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else class="row g-4">
            <div v-for="provider in providers" :key="provider.key" class="col-xl-6 col-12">
                <AiProviderCard :provider="provider" @updated="onUpdated" @refresh-all="loadProviders(false)" />
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import AiProviderCard from '../../../../../../components/settings/AiProviderCard.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../composables/useToast';

const { t } = useI18n();
const { showError } = useToast();

const providers = ref([]);
const loading = ref(true);

function onUpdated(updatedProvider) {
    const index = providers.value.findIndex((provider) => provider.key === updatedProvider.key);

    if (index !== -1) {
        providers.value.splice(index, 1, updatedProvider);
    }
}

async function loadProviders(showSkeleton = true) {
    if (showSkeleton) {
        loading.value = true;
    }

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-providers');
        providers.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        if (showSkeleton) {
            loading.value = false;
        }
    }
}

onMounted(() => loadProviders());
</script>
