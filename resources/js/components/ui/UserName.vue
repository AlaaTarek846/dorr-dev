<template>
    <span class="d-inline-flex align-items-center gap-1 mw-100">
        <span class="text-truncate"><slot>{{ label }}</slot></span>
        <i v-if="verified" class="ri-verified-badge-fill text-primary flex-shrink-0" :title="t('chat.verification.verified_badge')" :aria-label="t('chat.verification.verified_badge')"></i>
    </span>
</template>

<script setup>
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

/**
 * A person's name with the account verification ✔ next to it (docs/verification_tasks.md AV-029). Every
 * dashboard screen that shows a customer's name uses this, so the badge looks the same everywhere. The
 * server says who is verified (`is_verified`); this never works it out by itself.
 *
 *   <UserName :user="row.user" />                   — name, else phone, else #id
 *   <UserName :user="row.owner">custom text</UserName>
 */
const props = defineProps({
    user: { type: Object, default: null },
    fallback: { type: String, default: '—' },
});

const { t } = useI18n();
const verified = computed(() => props.user?.is_verified === true);
const label = computed(() => props.user?.name || props.user?.phone || (props.user?.id ? `#${props.user.id}` : props.fallback));
</script>
