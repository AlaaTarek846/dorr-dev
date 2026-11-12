<template>
    <div class="card custom-card mk-card mb-0">
        <div class="card-body">
            <div class="d-flex align-items-center gap-3">
                <span class="mk-icon" :class="active ? 'bg-success-transparent text-success' : 'bg-warning-transparent text-warning'">
                    <i class="ri-vip-crown-2-line"></i>
                </span>
                <div class="flex-grow-1 min-w-0">
                    <div class="fw-semibold fs-15">
                        {{ active ? (sub.package?.name || t('marketing.package')) : t('marketing.no_package_title') }}
                    </div>
                    <div class="text-muted fs-13">
                        {{ active ? t('marketing.active_until', { date: date(sub.paid_until || sub.ends_at) }) : t('marketing.no_package_text') }}
                    </div>
                </div>
            </div>

            <template v-if="active">
                <div v-for="row in usage" :key="row.key" class="mt-3">
                    <div class="d-flex justify-content-between fs-13 mb-1">
                        <span class="text-muted">{{ row.label }}</span>
                        <span class="fw-semibold">{{ t('marketing.used_of', { used: row.used, limit: row.limit ?? t('marketing.unlimited') }) }}</span>
                    </div>
                    <MkBar v-if="row.limit" :value="(row.used / row.limit) * 100" :color="row.used >= row.limit ? 'var(--danger-color, #e6533c)' : ''" />
                </div>
                <router-link :to="to('packages')" class="btn btn-light btn-sm mt-3">
                    <i class="ri-add-circle-line me-1"></i>{{ t('marketing.renew') }}
                </router-link>
            </template>
            <router-link v-else :to="to('packages')" class="btn btn-primary btn-sm mt-3">
                {{ t('marketing.see_packages') }}
            </router-link>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { useMk } from '../marketingContext';
import MkBar from './MkBar.vue';

const { state, to, date, t } = useMk();

const sub = computed(() => state.status?.subscription ?? {});
const active = computed(() => sub.value.active === true);

const usage = computed(() => ['projects', 'research_runs', 'plan_generations', 'content_generations'].map((key) => ({
    key,
    label: t(`marketing.limits.${key}`),
    used: sub.value.usage?.[key] ?? 0,
    limit: sub.value.limits?.[key] ?? null,
})));
</script>
