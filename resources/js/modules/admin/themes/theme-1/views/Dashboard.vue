<template>
    <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
        <div>
            <p class="fw-semibold fs-18 mb-0">{{ t('dashboard') }}</p>
            <span class="fs-semibold text-muted">{{ t('dashboard_placeholder') }}</span>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

const { t } = useI18n();
</script>
