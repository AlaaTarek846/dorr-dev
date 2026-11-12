<template>
    <div class="card custom-card mk-card mb-0">
        <div class="card-body">
            <div v-if="!claims.length" class="d-flex align-items-center gap-2 fw-semibold">
                <i class="ri-shield-check-line text-success fs-18"></i>{{ t('marketing.claims_clear') }}
            </div>
            <template v-else>
                <div v-for="(c, i) in claims" :key="i" class="d-flex gap-2 align-items-start" :class="{ 'mt-3': i > 0 }">
                    <i class="ri-error-warning-line fs-18" :class="c.severity === 'blocking' ? 'text-danger' : 'text-warning'"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold fs-13">{{ t(`marketing.claim_codes.${CODES.includes(c.code) ? c.code : 'empty_text'}`) }}</div>
                        <div v-if="where(c)" class="text-muted fs-12 text-break">{{ where(c) }}</div>
                    </div>
                    <span class="badge" :class="c.severity === 'blocking' ? 'bg-danger-transparent' : 'bg-warning-transparent'">
                        {{ c.severity === 'blocking' ? t('marketing.blocking') : t('marketing.warning') }}
                    </span>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

/** The claims review (ClaimsReviewer): blocking ones stop the review, warnings ask for a check. */
defineProps({ claims: { type: Array, default: () => [] } });

const CODES = ['guaranteed_result', 'absolute_claim', 'number_needs_proof', 'price_claim', 'too_long_for_channel', 'headline_too_long', 'empty_text'];
const { t } = useI18n();

function where(c) {
    return [c.variant ? t('marketing.variant', { key: c.variant }) : null, c.detail ? `«${c.detail}»` : null].filter(Boolean).join(' · ');
}
</script>
