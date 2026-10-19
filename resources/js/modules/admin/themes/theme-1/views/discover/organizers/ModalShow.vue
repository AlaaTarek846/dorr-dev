<template>
    <WalletModal
        :show="show"
        :title="record ? `${t('discover.organizers.details')} #${record.id}` : ''"
        size="lg"
        @close="emit('close')"
    >
        <div v-if="record" class="d-flex flex-column gap-3">
            <WalletDetailHero
                :label="t('discover.organizers.name')"
                :value="record.name || '—'"
                :subtitle="formatCatalogDate(record.created_at, locale)"
                icon="ri-user-star-line"
            >
                <span class="badge fs-12" :class="statusBadge(record.status)">
                    {{ t(`discover.organizers.s_${record.status}`) }}
                </span>
            </WalletDetailHero>

            <div class="row g-3">
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-account-circle-line" :label="t('discover.organizers.account')">
                        {{ record.account?.name || '—' }}
                        <div v-if="record.account?.phone" class="fs-12 fw-normal text-muted" dir="ltr">{{ record.account.phone }}</div>
                    </WalletInfoTile>
                </div>
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-calendar-event-line" :label="t('discover.organizers.events')">
                        {{ record.events_count ?? 0 }}
                    </WalletInfoTile>
                </div>
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-mail-line" :label="t('discover.organizers.email')">
                        <span v-if="record.email" dir="ltr">{{ record.email }}</span>
                        <span v-else class="text-muted fw-normal">—</span>
                    </WalletInfoTile>
                </div>
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-phone-line" :label="t('discover.organizers.phone')">
                        <span v-if="record.phone" dir="ltr">{{ record.phone }}</span>
                        <span v-else class="text-muted fw-normal">—</span>
                    </WalletInfoTile>
                </div>
                <div class="col-12">
                    <WalletInfoTile icon="ri-links-line" :label="t('discover.organizers.website')">
                        <a v-if="record.website" :href="record.website" target="_blank" rel="noopener" dir="ltr">{{ record.website }}</a>
                        <span v-else class="text-muted fw-normal">—</span>
                    </WalletInfoTile>
                </div>
            </div>

            <WalletSection :title="t('discover.organizers.about')" icon="ri-file-text-line">
                <p class="mb-0" :class="{ 'text-muted': ! record.about }">{{ record.about || '—' }}</p>
            </WalletSection>

            <WalletSection v-if="record.review_note || record.verified_at" :title="t('discover.organizers.review')" icon="ri-shield-check-line">
                <div v-if="record.verified_at" class="text-muted fs-13 mb-1">
                    {{ t('discover.organizers.verified_at') }}: {{ formatCatalogDate(record.verified_at, locale) }}
                </div>
                <p v-if="record.review_note" class="mb-0">{{ record.review_note }}</p>
            </WalletSection>
        </div>

        <template v-if="canUpdate && record" #footer>
            <button type="button" class="btn btn-light" @click="emit('close')">{{ t('close') }}</button>
            <button v-if="record.status !== 'verified'" type="button" class="btn btn-success btn-wave" @click="emit('review', record, 'verified')">
                <i class="ri-verified-badge-line me-1 align-middle"></i>{{ t('discover.organizers.verify') }}
            </button>
            <button v-if="record.status === 'pending'" type="button" class="btn btn-danger btn-wave" @click="emit('review', record, 'rejected')">
                {{ t('discover.organizers.reject') }}
            </button>
            <button v-if="record.status === 'verified'" type="button" class="btn btn-warning btn-wave" @click="emit('review', record, 'suspended')">
                {{ t('discover.organizers.suspend') }}
            </button>
            <button v-if="record.status === 'suspended' || record.status === 'rejected'" type="button" class="btn btn-light btn-wave" @click="emit('review', record, 'pending')">
                {{ t('discover.organizers.reopen') }}
            </button>
        </template>
    </WalletModal>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import WalletDetailHero from '../../../../../../../components/wallet/WalletDetailHero.vue';
import WalletInfoTile from '../../../../../../../components/wallet/WalletInfoTile.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import WalletSection from '../../../../../../../components/wallet/WalletSection.vue';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

defineProps({
    show: { type: Boolean, default: false },
    record: { type: Object, default: null },
    canUpdate: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'review']);
const { t, locale } = useI18n();

function statusBadge(status) {
    return {
        pending: 'bg-warning-transparent',
        verified: 'bg-success-transparent',
        rejected: 'bg-danger-transparent',
        suspended: 'bg-secondary-transparent',
    }[status] || 'bg-light';
}
</script>
