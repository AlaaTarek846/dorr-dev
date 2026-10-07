<template>
    <WalletModal :show="show" :title="record ? t('referrals.view') : ''" size="lg" @close="emit('close')">
        <div v-if="record" class="d-flex flex-column gap-3">
            <WalletInfoTile icon="ri-coupon-line" :label="t('referrals.code')">
                <span class="font-monospace">{{ record.referral_code }}</span>
            </WalletInfoTile>
            <div class="row g-3">
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-user-shared-line" :label="t('referrals.referrer')">
                        {{ record.referrer?.name || `#${record.referrer?.id}` }}
                        <div class="fs-12 text-muted">{{ record.referrer?.type }}</div>
                    </WalletInfoTile>
                </div>
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-user-received-line" :label="t('referrals.referred')">
                        {{ record.referred?.name || `#${record.referred?.id}` }}
                        <div class="fs-12 text-muted">{{ record.referred?.type }}</div>
                    </WalletInfoTile>
                </div>
            </div>
            <WalletSection :title="t('referrals.timeline')" icon="ri-time-line">
                <div class="fs-13">{{ t('referrals.created') }}: {{ formatDate(record.created_at) }}</div>
                <div class="fs-13">{{ t('referrals.registered') }}: {{ formatDate(record.registered_at) }}</div>
                <div class="fs-13">{{ t('referrals.completed') }}: {{ formatDate(record.completed_at) }}</div>
            </WalletSection>
        </div>
    </WalletModal>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import WalletInfoTile from '../../../../../../components/wallet/WalletInfoTile.vue';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
import WalletSection from '../../../../../../components/wallet/WalletSection.vue';

defineProps({
    show: { type: Boolean, default: false },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close']);
const { t, locale } = useI18n();

function formatDate(value) {
    if (!value) return '—';
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    });
}
</script>
