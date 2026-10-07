<template>
    <div
        ref="modalElement"
        class="modal fade"
        tabindex="-1"
        aria-hidden="true"
    >
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content">
                <div class="modal-header catalog-modal-header">
                    <div class="d-flex align-items-center justify-content-between w-100 gap-3">
                        <h6 class="modal-title mb-0">
                            {{ record?.code || t('referral_codes.view') }}
                        </h6>
                        <button
                            type="button"
                            class="btn-close catalog-modal-close"
                            aria-label="Close"
                            @click="close"
                        ></button>
                    </div>
                </div>

                <div v-if="record" class="modal-body px-4 pb-3">
                    <div class="d-flex flex-column gap-3">
                        <WalletDetailHero
                            :label="t('referral_codes.code')"
                            :value="record.code"
                            :subtitle="formatCatalogDate(record.created_at, locale)"
                            icon="ri-coupon-3-line"
                        >
                            <span class="badge fs-12" :class="record.is_active ? 'bg-success-transparent' : 'bg-secondary-transparent'">
                                {{ record.is_active ? t('referral_codes.active') : t('referral_codes.inactive') }}
                            </span>
                        </WalletDetailHero>

                        <div class="row g-3">
                            <div class="col-6">
                                <WalletInfoTile icon="ri-user-line" :label="t('referral_codes.owner')">
                                    {{ record.owner?.name || `#${record.owner?.id}` }}
                                </WalletInfoTile>
                            </div>
                            <div class="col-6">
                                <WalletInfoTile icon="ri-account-circle-line" :label="t('referral_codes.owner_type')">
                                    {{ ownerLabel(record.owner?.type) }}
                                </WalletInfoTile>
                            </div>
                            <div class="col-6">
                                <WalletInfoTile icon="ri-phone-line" :label="t('referral_codes.phone')">
                                    <span v-if="record.owner?.phone" dir="ltr">{{ record.owner.phone }}</span>
                                    <span v-else class="text-muted fw-normal">—</span>
                                </WalletInfoTile>
                            </div>
                            <div class="col-6">
                                <WalletInfoTile icon="ri-mail-line" :label="t('referral_codes.email')">
                                    <span v-if="record.owner?.email" dir="ltr">{{ record.owner.email }}</span>
                                    <span v-else class="text-muted fw-normal">—</span>
                                </WalletInfoTile>
                            </div>
                        </div>

                        <WalletSection v-if="record.stats" :title="t('referral_codes.stats')" icon="ri-bar-chart-line">
                            <div class="row g-3">
                                <div class="col-6">
                                    <WalletInfoTile icon="ri-hashtag" :label="t('referral_codes.stat_total')">
                                        {{ record.stats.total }}
                                    </WalletInfoTile>
                                </div>
                                <div class="col-6">
                                    <WalletInfoTile icon="ri-user-add-line" :label="t('referral_codes.stat_registered')">
                                        {{ record.stats.registered }}
                                    </WalletInfoTile>
                                </div>
                                <div class="col-6">
                                    <WalletInfoTile icon="ri-checkbox-circle-line" :label="t('referral_codes.stat_completed')">
                                        {{ record.stats.completed }}
                                    </WalletInfoTile>
                                </div>
                                <div class="col-6">
                                    <WalletInfoTile icon="ri-close-circle-line" :label="t('referral_codes.stat_cancelled')">
                                        {{ record.stats.cancelled }}
                                    </WalletInfoTile>
                                </div>
                            </div>
                        </WalletSection>

                        <WalletSection
                            v-if="record.referrals?.length"
                            :title="t('referral_codes.referrals')"
                            icon="ri-share-forward-line"
                            flush
                        >
                            <div class="table-responsive">
                                <table class="table text-nowrap table-striped mb-0">
                                    <thead>
                                        <tr>
                                            <th>{{ t('referrals.referred') }}</th>
                                            <th>{{ t('referrals.status') }}</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr v-for="item in record.referrals" :key="item.id" class="crm-contact">
                                            <td>
                                                {{ item.referred?.name || `#${item.referred?.id}` }}
                                                <span class="d-block text-muted fs-11">#{{ item.id }}</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary-transparent">{{ referralStatus(item.status) }}</span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </WalletSection>
                    </div>
                </div>

                <div class="modal-footer catalog-modal-footer">
                    <button type="button" class="btn btn-light" @click="close">
                        {{ t('close') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import WalletDetailHero from '../../../../../../components/wallet/WalletDetailHero.vue';
import WalletInfoTile from '../../../../../../components/wallet/WalletInfoTile.vue';
import WalletSection from '../../../../../../components/wallet/WalletSection.vue';
import { formatCatalogDate } from '../../../../../../utils/catalog';

const props = defineProps({
    show: { type: Boolean, default: false },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close']);
const { t, locale } = useI18n();
const modalElement = ref(null);
let modalInstance = null;

function ownerLabel(type) {
    if (type === 'user') {
        return t('referral_codes.type_user');
    }

    if (type === 'provider') {
        return t('referral_codes.type_provider');
    }

    return type || '—';
}

function referralStatus(status) {
    if (status === 'registered') {
        return t('referrals.status_registered');
    }

    if (status === 'completed') {
        return t('referrals.status_completed');
    }

    if (status === 'cancelled') {
        return t('referrals.status_cancelled');
    }

    return status || '—';
}

function openModal() {
    if (! modalElement.value) {
        return;
    }

    modalInstance ??= new window.bootstrap.Modal(modalElement.value);
    modalInstance.show();
}

function closeModal() {
    modalInstance?.hide();
}

function close() {
    closeModal();
    emit('close');
}

function onModalHidden() {
    emit('close');
}

watch(
    () => props.show,
    (visible) => {
        if (visible) {
            openModal();
        } else {
            closeModal();
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
.catalog-modal-header {
    padding: 1.25rem 1.5rem;
    border-bottom: 1px solid var(--default-border, #dee2e6);
}

.catalog-modal-header .modal-title {
    font-size: 1rem;
    font-weight: 600;
    line-height: 1.4;
}

.catalog-modal-close {
    margin: 0 !important;
    padding: 0.625rem;
    flex-shrink: 0;
    opacity: 0.65;
    background-size: 0.65rem;
}

.catalog-modal-close:hover {
    opacity: 1;
}

.catalog-modal-footer {
    padding: 1rem 1.5rem 1.25rem;
    gap: 0.5rem;
}
</style>
