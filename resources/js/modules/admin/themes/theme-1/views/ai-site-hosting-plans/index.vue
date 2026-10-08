<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <div>
                <p class="fw-semibold fs-18 mb-0">{{ t('ai_site_hosting.title') }}</p>
                <span class="fs-semibold text-muted">{{ t('ai_site_hosting.subtitle') }}</span>
            </div>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('ai_site_hosting.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center justify-content-end py-3">
                <button type="button" class="btn btn-primary btn-sm btn-wave" @click="openCreate">
                    <i class="ri-add-line me-1 align-middle"></i>{{ t('ai_site_hosting.add') }}
                </button>
            </div>

            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th>{{ t('ai_site_offers.name') }}</th>
                                <th>{{ t('ai_site_offers.code') }}</th>
                                <th>{{ t('ai_site_hosting.period') }}</th>
                                <th>{{ t('ai_site_offers.countries') }}</th>
                                <th>{{ t('ai_site_offers.status') }}</th>
                                <th class="text-end pe-4">{{ t('ai_site_offers.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="4" :columns="6" />

                            <tr v-else-if="!offers.length">
                                <td colspan="6" class="border-0">
                                    <div class="text-center py-5">
                                        <p class="fw-semibold mb-1">{{ t('ai_site_hosting.empty_title') }}</p>
                                        <p class="text-muted mb-0">{{ t('ai_site_hosting.empty') }}</p>
                                    </div>
                                </td>
                            </tr>

                            <tr v-for="offer in offers" v-else :key="offer.id">
                                <td>
                                    <button type="button" class="btn btn-link p-0 fw-semibold text-default" @click="openEdit(offer)">{{ offer.name }}</button>
                                </td>
                                <td><span class="badge bg-primary-transparent">{{ offer.code }}</span></td>
                                <td>{{ t('ai_site_hosting.period_' + offer.period) }}</td>
                                <td>
                                    <span v-if="offer.prices_count" class="badge bg-success-transparent">{{ offer.prices_count }}</span>
                                    <span v-else class="badge bg-warning-transparent">{{ t('ai_site_offers.no_prices') }}</span>
                                </td>
                                <td>
                                    <div
                                        class="toggle toggle-success mb-0"
                                        :class="{ on: offer.is_active }"
                                        role="button"
                                        tabindex="0"
                                        @click="toggleActive(offer)"
                                        @keydown.enter.space.prevent="toggleActive(offer)"
                                    >
                                        <span></span>
                                    </div>
                                </td>
                                <td class="text-end pe-4">
                                    <div class="btn-list justify-content-end">
                                        <button type="button" class="btn btn-sm btn-primary-light" @click="openPrices(offer)">
                                            <i class="ri-earth-line me-1 align-middle"></i>{{ t('ai_site_offers.manage_prices') }}
                                        </button>
                                        <button type="button" class="btn btn-sm btn-info-light btn-icon" @click="openEdit(offer)">
                                            <i class="ri-pencil-line"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-danger-light btn-icon" @click="remove(offer)">
                                            <i class="ri-delete-bin-line"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <ModalHostingPlan :show="modalShow" :type="modalType" :record="selected" @close="modalShow = false" @saved="onSaved" />
        <ModalHostingPlanPrices :show="pricesShow" :offer="pricesOffer" @close="pricesShow = false" @saved="load" />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../api/adminAxios';
import TableSkeleton from '../../../../../../components/ui/TableSkeleton.vue';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../composables/useToast';
import ModalHostingPlan from './ModalHostingPlan.vue';
import ModalHostingPlanPrices from './ModalHostingPlanPrices.vue';

const { t } = useI18n();
const { showSuccess, showError } = useToast();

const offers = ref([]);
const loading = ref(true);
const modalShow = ref(false);
const modalType = ref('create');
const selected = ref(null);
const pricesShow = ref(false);
const pricesOffer = ref(null);

async function load() {
    loading.value = true;

    try {
        const { data } = await adminAxios.get('/api/admin/v1/ai-site-hosting-plans');
        offers.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

function openCreate() {
    modalType.value = 'create';
    selected.value = null;
    modalShow.value = true;
}

function openEdit(offer) {
    modalType.value = 'edit';
    selected.value = { ...offer };
    modalShow.value = true;
}

function openPrices(offer) {
    pricesOffer.value = offer;
    pricesShow.value = true;
}

async function toggleActive(offer) {
    const previous = offer.is_active;
    offer.is_active = ! offer.is_active;

    try {
        const response = await adminAxios.put(`/api/admin/v1/ai-site-hosting-plans/${offer.id}`, {
            name: offer.name,
            code: offer.code,
            description: offer.description,
            period: offer.period,
            sort_order: offer.sort_order,
            is_active: offer.is_active,
        });
        showSuccess(extractApiMessage(response, t('toast.status_changed')));
    } catch (error) {
        offer.is_active = previous;
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function remove(offer) {
    if (! window.confirm(t('ai_site_hosting.confirm_delete'))) {
        return;
    }

    try {
        const response = await adminAxios.delete(`/api/admin/v1/ai-site-hosting-plans/${offer.id}`);
        showSuccess(extractApiMessage(response, t('toast.deleted')));
        await load();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function onSaved() {
    modalShow.value = false;
    load();
}

onMounted(load);
</script>
