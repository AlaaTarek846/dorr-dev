<template>
    <WalletModal :show="show" :title="record ? record.code : ''" size="lg" @close="emit('close')">
        <div v-if="record" class="d-flex flex-column gap-3">
            <div class="row g-3">
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-user-line" :label="t('referral_codes.owner')">
                        {{ record.owner?.name || `#${record.owner?.id}` }}
                        <div class="fs-12 text-muted">{{ record.owner?.type }}</div>
                    </WalletInfoTile>
                </div>
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-toggle-line" :label="t('referral_codes.status')">
                        {{ record.is_active ? t('referral_codes.active') : t('referral_codes.inactive') }}
                    </WalletInfoTile>
                </div>
            </div>
            <WalletSection v-if="record.stats" :title="t('referral_codes.stats')" icon="ri-bar-chart-line">
                <div class="d-flex flex-wrap gap-3 fs-13">
                    <span>{{ t('referral_codes.stat_total') }}: {{ record.stats.total }}</span>
                    <span>{{ t('referral_codes.stat_registered') }}: {{ record.stats.registered }}</span>
                    <span>{{ t('referral_codes.stat_completed') }}: {{ record.stats.completed }}</span>
                    <span>{{ t('referral_codes.stat_cancelled') }}: {{ record.stats.cancelled }}</span>
                </div>
            </WalletSection>
            <WalletSection v-if="record.referrals?.length" :title="t('referral_codes.referrals')" icon="ri-share-forward-line">
                <div v-for="item in record.referrals" :key="item.id" class="border-bottom py-2 fs-13">
                    {{ item.referred?.name || `#${item.referred?.id}` }}
                    · {{ item.status }}
                </div>
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
const { t } = useI18n();
</script>
