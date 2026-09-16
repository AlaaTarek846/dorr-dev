<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('providers.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('providers.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('providers.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <ul class="nav nav-tabs mb-4 provider-status-tabs">
            <li v-for="tab in TABS" :key="tab.value" class="nav-item">
                <button
                    type="button"
                    class="nav-link"
                    :class="{ active: activeTab === tab.value }"
                    @click="setTab(tab.value)"
                >
                    {{ t(tab.labelKey) }}
                    <span v-if="counts[tab.value] != null" class="badge bg-primary-transparent ms-1">{{ counts[tab.value] }}</span>
                </button>
            </li>
        </ul>

        <div v-if="loading" class="row g-4">
            <div v-for="n in 3" :key="n" class="col-xl-6 col-12">
                <div class="card custom-card">
                    <div class="card-body">
                        <div class="placeholder-glow">
                            <span class="placeholder col-6 mb-3 d-block"></span>
                            <span class="placeholder col-12 mb-2 d-block"></span>
                            <span class="placeholder col-8 d-block"></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-else-if="!providers.length" class="card custom-card">
            <div class="card-body text-center py-5">
                <span class="avatar avatar-xxl avatar-rounded bg-primary-transparent mb-3">
                    <i class="ri-user-search-line fs-2 text-primary"></i>
                </span>
                <p class="fw-semibold mb-1">{{ t('providers.empty_title') }}</p>
                <p class="text-muted mb-0">{{ t('providers.empty') }}</p>
            </div>
        </div>

        <div v-else class="row g-4">
            <div v-for="provider in providers" :key="provider.id" class="col-xl-6 col-12">
                <ProviderCard
                    :provider="provider"
                    :leaf-options="leafOptions"
                    @updated="onUpdated"
                    @reject="openReject"
                />
            </div>
        </div>

        <div v-if="pagination && !loading && providers.length" class="d-flex justify-content-center mt-4">
            <nav aria-label="Providers pagination" class="pagination-style-4">
                <ul class="pagination mb-0">
                    <li class="page-item" :class="{ disabled: !pagination.prev_page_url }">
                        <button type="button" class="page-link" @click="changePage(currentPage - 1)">
                            {{ t('service_categories.previous') }}
                        </button>
                    </li>
                    <li
                        v-for="page in pageNumbers"
                        :key="page"
                        class="page-item"
                        :class="{ active: page === currentPage }"
                    >
                        <button type="button" class="page-link" @click="changePage(page)">{{ page }}</button>
                    </li>
                    <li class="page-item" :class="{ disabled: !pagination.next_page_url }">
                        <button type="button" class="page-link text-primary" @click="changePage(currentPage + 1)">
                            {{ t('service_categories.next') }}
                        </button>
                    </li>
                </ul>
            </nav>
        </div>

        <ProviderRejectModal
            :show="rejectModal.show"
            :loading="rejectModal.loading"
            @close="rejectModal.show = false"
            @confirm="handleRejectConfirm"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../api/adminAxios';
import ProviderCard from '../../../../components/provider/ProviderCard.vue';
import ProviderRejectModal from '../../../../components/provider/ProviderRejectModal.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../composables/useToast';

const TABS = [
    { value: 'pending', labelKey: 'providers.tab_pending' },
    { value: 'approved', labelKey: 'providers.tab_approved' },
    { value: 'rejected', labelKey: 'providers.tab_rejected' },
    { value: 'suspended', labelKey: 'providers.tab_suspended' },
];

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const activeTab = ref('pending');
const providers = ref([]);
const leafOptions = ref([]);
const loading = ref(true);
const pagination = ref(null);
const currentPage = ref(1);
const counts = reactive({ pending: null, approved: null, rejected: null, suspended: null });

const rejectModal = reactive({ show: false, loading: false, provider: null });

function statusParams(status) {
    return {
        filterColumns: {
            columns: [{ column: 'status', opreator: '=', value: status }],
        },
    };
}

async function fetchProviders(page = 1) {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/providers', {
            params: { page, paginate: 10, ...statusParams(activeTab.value) },
        });

        providers.value = data.data ?? [];
        pagination.value = data.pagination ?? null;
        currentPage.value = page;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function fetchCounts() {
    const results = await Promise.allSettled(
        TABS.map((tab) => adminAxios.get('/api/admin/v1/providers', {
            params: { paginate: 1, page: 1, ...statusParams(tab.value) },
        })),
    );

    results.forEach((result, index) => {
        if (result.status === 'fulfilled') {
            counts[TABS[index].value] = result.value.data.pagination?.total ?? 0;
        }
    });
}

async function fetchLeafOptions() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/service-categories/leaf-options');
        leafOptions.value = data.data ?? [];
    } catch {
        // Non-fatal: "add service" selects just show no options.
    }
}

function setTab(tab) {
    if (activeTab.value === tab) {
        return;
    }

    activeTab.value = tab;
    fetchProviders(1);
}

function changePage(page) {
    if (! pagination.value || page < 1 || page > pagination.value.last_page) {
        return;
    }

    fetchProviders(page);
}

const pageNumbers = computed(() => {
    if (! pagination.value?.last_page) {
        return [];
    }

    const total = pagination.value.last_page;
    const current = currentPage.value;
    const pages = [];

    let start = Math.max(1, current - 2);
    let end = Math.min(total, start + 4);

    if (end - start < 4) {
        start = Math.max(1, end - 4);
    }

    for (let page = start; page <= end; page += 1) {
        pages.push(page);
    }

    return pages;
});

function onUpdated(updatedProvider) {
    const index = providers.value.findIndex((provider) => provider.id === updatedProvider.id);

    // The account (or one of its services) may have just moved out of the
    // currently active tab (e.g. approving a pending one) - simplest correct
    // behaviour is to refetch the current view rather than patch it in place.
    if (index !== -1 && updatedProvider.status !== activeTab.value) {
        fetchProviders(currentPage.value);
        fetchCounts();
        return;
    }

    if (index !== -1) {
        providers.value.splice(index, 1, updatedProvider);
    }

    fetchCounts();
}

function openReject(provider) {
    rejectModal.provider = provider;
    rejectModal.show = true;
}

async function handleRejectConfirm(reason) {
    rejectModal.loading = true;

    try {
        const response = await adminAxios.post(`/api/admin/v1/providers/${rejectModal.provider.id}/reject`, { reason });
        showSuccess(extractApiMessage(response, t('toast.updated')));
        onUpdated(response.data.data);
        rejectModal.show = false;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        rejectModal.loading = false;
    }
}

onMounted(async () => {
    await Promise.all([fetchProviders(), fetchLeafOptions(), fetchCounts()]);
});
</script>

<style scoped>
.provider-status-tabs .nav-link {
    cursor: pointer;
}
</style>
