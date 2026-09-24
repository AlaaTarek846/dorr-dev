<template>
    <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
        <h1 class="page-title fw-semibold fs-18 mb-0">
            {{ title }}
            <span v-if="total != null" class="badge bg-primary-transparent ms-2 fs-12 align-middle">{{ total }}</span>
        </h1>
        <div class="ms-md-1 ms-0">
            <nav>
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item">
                        <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                    </li>
                    <li class="breadcrumb-item">{{ t('sidebar.wallet') }}</li>
                    <li class="breadcrumb-item active" aria-current="page">{{ title }}</li>
                </ol>
            </nav>
        </div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';

defineProps({
    title: { type: String, required: true },
    total: { type: Number, default: null },
});

const { t } = useI18n();
</script>
