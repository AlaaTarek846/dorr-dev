<template>
    <WalletModal :show="show" :title="record ? `#${record.id} · ${t('ratings.view')}` : ''" size="lg" @close="emit('close')">
        <div v-if="record" class="d-flex flex-column gap-3">
            <WalletDetailHero
                :label="t('ratings.rating')"
                :value="`${formatStars(record.stars)} / 5`"
                :subtitle="record.created_at ? formatDate(record.created_at) : ''"
                icon="ri-star-smile-line"
            >
                <div class="d-flex flex-column align-items-end gap-2">
                    <RatingStars :value="Number(record.stars)" size="1.4rem" />
                    <span class="badge fs-12" :class="record.type === 'review' ? 'bg-success-transparent' : 'bg-warning-transparent'">
                        {{ record.type === 'review' ? t('ratings.type_review') : t('ratings.type_feedback') }}
                    </span>
                </div>
            </WalletDetailHero>

            <div class="row g-3">
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-user-line" :label="t('ratings.user')">
                        {{ record.author?.name || (record.author ? `#${record.author.id}` : t('ratings.deleted_user')) }}
                        <div v-if="record.author?.phone" class="fs-12 fw-normal text-muted" dir="ltr">{{ record.author.phone }}</div>
                        <div v-if="record.author?.email" class="fs-12 fw-normal text-muted" dir="ltr">{{ record.author.email }}</div>
                    </WalletInfoTile>
                </div>
                <div class="col-md-6">
                    <WalletInfoTile icon="ri-price-tag-3-line" :label="t('ratings.target')">
                        {{ record.rateable ? (record.rateable.name || `${record.rateable.type} #${record.rateable.id}`) : t('ratings.target_app') }}
                    </WalletInfoTile>
                </div>
            </div>

            <WalletSection :title="t('ratings.comment')" icon="ri-chat-quote-line">
                <p v-if="record.comment" class="mb-0 rating-comment-text">{{ record.comment }}</p>
                <p v-else class="mb-0 text-muted">{{ t('ratings.no_comment') }}</p>
            </WalletSection>
        </div>
    </WalletModal>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import WalletDetailHero from '../../../../../../components/wallet/WalletDetailHero.vue';
import WalletInfoTile from '../../../../../../components/wallet/WalletInfoTile.vue';
import WalletModal from '../../../../../../components/wallet/WalletModal.vue';
import WalletSection from '../../../../../../components/wallet/WalletSection.vue';
import RatingStars from './RatingStars.vue';

defineProps({
    show: { type: Boolean, default: false },
    record: { type: Object, default: null },
});

const emit = defineEmits(['close']);
const { t, locale } = useI18n();

/** 4 → "4", 4.5 → "4.5", 4.25 → "4.25" */
function formatStars(value) {
    return String(Number(value));
}

function formatDate(value) {
    return new Date(value).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<style scoped>
.rating-comment-text {
    white-space: pre-wrap;
    line-height: 1.7;
}
</style>
