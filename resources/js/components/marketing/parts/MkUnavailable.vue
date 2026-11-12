<template>
    <div class="card custom-card mk-card">
        <div class="card-body text-center py-5">
            <span class="mk-icon bg-info-transparent text-info mb-3"><i class="ri-earth-line"></i></span>
            <h6 class="fw-semibold">{{ t('marketing.feature_off_title') }}</h6>
            <p class="text-muted mb-0">{{ t('marketing.feature_off_text') }}</p>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>
