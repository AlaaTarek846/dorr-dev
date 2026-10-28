<template>
    <div>
        <WalletPageHeader :title="t('freelance.overview.title')" :section="t('sidebar.freelance')" />

        <div class="card custom-card">
            <div class="card-body text-center py-5">
                <i class="ri-briefcase-4-line fs-1 text-primary"></i>
                <h6 class="fw-semibold mt-2">{{ t('freelance.overview.heading') }}</h6>
                <p class="text-muted mb-0">{{ t('freelance.overview.hint') }}</p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';

const { t } = useI18n();
</script>
