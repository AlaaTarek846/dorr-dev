<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_site_download.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_site_download.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_site_download.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div v-if="loading" class="text-center py-5">
            <span class="spinner-border spinner-border-sm me-2"></span>{{ t('ai_site_offers.loading') }}
        </div>

        <div v-else-if="setting" class="row g-3">
            <div class="col-lg-6">
                <div class="card custom-card h-100">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3 pb-3 border-bottom">
                            <span class="avatar avatar-md bg-primary-transparent"><i class="ri-folder-zip-line fs-20"></i></span>
                            <div class="flex-fill">
                                <p class="fw-semibold mb-1">{{ t('ai_site_download.enabled') }}</p>
                                <p class="text-muted fs-13 mb-0">{{ t('ai_site_download.enabled_hint') }}</p>
                            </div>
                            <div
                                class="toggle toggle-success mb-0"
                                :class="{ on: setting.is_active, 'opacity-50': saving }"
                                role="button"
                                tabindex="0"
                                @click="flip('is_active')"
                                @keydown.enter.space.prevent="flip('is_active')"
                            >
                                <span></span>
                            </div>
                        </div>

                        <div class="pt-3" :class="{ 'opacity-50': ! setting.is_active }">
                            <p class="fw-semibold mb-2">{{ t('ai_site_download.pricing') }}</p>
                            <div class="btn-group w-100" role="group">
                                <button type="button" class="btn" :class="setting.is_free ? 'btn-primary' : 'btn-outline-primary'" :disabled="saving || ! setting.is_active" @click="setFree(true)">
                                    <i class="ri-gift-line me-1 align-middle"></i>{{ t('ai_site_download.free') }}
                                </button>
                                <button type="button" class="btn" :class="! setting.is_free ? 'btn-primary' : 'btn-outline-primary'" :disabled="saving || ! setting.is_active" @click="setFree(false)">
                                    <i class="ri-money-dollar-circle-line me-1 align-middle"></i>{{ t('ai_site_download.paid') }}
                                </button>
                            </div>
                            <p class="text-muted fs-13 mt-2 mb-0">{{ setting.is_free ? t('ai_site_download.free_hint') : t('ai_site_download.paid_hint') }}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-6">
                <div class="card custom-card h-100">
                    <div class="card-body d-flex flex-column">
                        <p class="fw-semibold mb-1">{{ t('ai_site_download.prices') }}</p>
                        <p class="text-muted fs-13">{{ t('ai_site_download.prices_hint') }}</p>

                        <div class="mb-3">
                            <span v-if="setting.is_free" class="badge bg-success-transparent">{{ t('ai_site_download.free_now') }}</span>
                            <span v-else-if="setting.prices_count" class="badge bg-success-transparent">{{ t('ai_site_download.countries_count', { count: setting.prices_count }) }}</span>
                            <span v-else class="badge bg-warning-transparent">{{ t('ai_site_offers.no_prices') }}</span>
                        </div>

                        <div v-if="! setting.is_free && ! setting.prices_count" class="alert alert-warning fs-13">{{ t('ai_site_download.no_price_warning') }}</div>

                        <button type="button" class="btn btn-primary mt-auto align-self-start" @click="pricesShow = true">
                            <i class="ri-earth-line me-1 align-middle"></i>{{ t('ai_site_offers.manage_prices') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <ModalOfferPrices :show="pricesShow" :offer="setting" @close="pricesShow = false" @saved="load" />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import ModalOfferPrices from '../ai-site-offers/ModalOfferPrices.vue';

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const setting = ref(null);
const loading = ref(true);
const saving = ref(false);
const pricesShow = ref(false);

async function load() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-site-download');
        setting.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function save(patch) {
    saving.value = true;

    try {
        const response = await adminAxios.put('/api/admin/v1/ai-site-download', {
            is_active: setting.value.is_active,
            is_free: setting.value.is_free,
            ...patch,
        });
        setting.value = response.data.data;
        showSuccess(extractApiMessage(response, t('toast.success')));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

function flip(key) {
    if (! saving.value) {
        save({ [key]: ! setting.value[key] });
    }
}

function setFree(value) {
    if (! saving.value && setting.value.is_free !== value) {
        save({ is_free: value });
    }
}

onMounted(load);
</script>
