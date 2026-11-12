<template>
    <div>
        <div class="d-flex flex-wrap gap-2">
            <button
                v-for="r in ASSIGNABLE_ROLES"
                :key="r"
                type="button"
                class="btn btn-sm rounded-pill"
                :class="modelValue === r ? 'btn-primary' : 'btn-outline-light text-default'"
                @click="$emit('update:modelValue', r)"
            >{{ t(roleKey(r)) }}</button>
        </div>
        <div v-if="ASSIGNABLE_ROLES.includes(modelValue)" class="text-muted fs-13 mt-2">{{ t(`marketing.role_hints.${modelValue}`) }}</div>
    </div>
</template>

<script setup>
import { useI18n } from 'vue-i18n';
import { ASSIGNABLE_ROLES, roleKey } from '../marketingContext';

defineProps({ modelValue: { type: String, default: 'viewer' } });
defineEmits(['update:modelValue']);

const { t } = useI18n();
</script>
