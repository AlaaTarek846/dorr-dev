<template>
    <div>
        <MkHeader :title="t('marketing.packages')" :back="to('home')" />

        <div v-if="!status" class="row g-3">
            <div v-for="i in 2" :key="i" class="col-md-6"><div class="mk-skeleton" style="height: 220px"></div></div>
        </div>
        <div v-else-if="packages.length === 0" class="card custom-card mk-card">
            <div class="card-body text-center py-5">
                <span class="mk-icon bg-secondary-transparent text-secondary mb-3"><i class="ri-vip-crown-2-line"></i></span>
                <p class="text-muted mb-0">{{ t('marketing.no_packages') }}</p>
            </div>
        </div>
        <div v-else class="row g-3">
            <div v-for="pkg in packages" :key="pkg.code" class="col-xl-4 col-md-6">
                <div class="card custom-card mk-card h-100 mb-0" :class="{ 'border border-primary': isActive(pkg) }">
                    <div class="card-body d-flex flex-column">
                        <div class="d-flex align-items-start gap-2">
                            <div class="flex-grow-1 min-w-0">
                                <h5 class="fw-semibold mb-1">{{ pkg.name || pkg.code }}</h5>
                                <span class="text-muted fs-13">{{ t('marketing.per_days', { days: pkg.period_days }) }}</span>
                            </div>
                            <span class="fs-18 fw-bold text-primary mk-ltr text-nowrap">{{ mkMoney(pkg.price_minor, pkg.currency_code) }}</span>
                        </div>
                        <p v-if="pkg.description" class="text-muted fs-13 mt-2 mb-0 mk-prewrap">{{ pkg.description }}</p>
                        <ul class="mk-bullets fs-13 mt-3 mb-3">
                            <li v-for="key in LIMIT_KEYS" :key="key">
                                {{ t(`marketing.limits.${key}`) }}: <strong>{{ pkg.limits?.[key] ?? t('marketing.unlimited') }}</strong>
                            </li>
                        </ul>
                        <button
                            type="button"
                            class="btn mt-auto"
                            :class="isActive(pkg) ? 'btn-light' : 'btn-primary'"
                            @click="buying = pkg"
                        >{{ isActive(pkg) ? t('marketing.renew') : t('marketing.buy') }}</button>
                    </div>
                </div>
            </div>
        </div>

        <MarketingCheckoutModal
            :show="buying !== null"
            :purpose="status?.checkout_purpose || 'marketing_package'"
            :reference="buying ? { package: buying.code } : null"
            @close="buying = null"
            @paid="onPaid"
        />
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { mkMoney, useMk } from './marketingContext';
import MkHeader from './parts/MkHeader.vue';
import MarketingCheckoutModal from './parts/MarketingCheckoutModal.vue';

const LIMIT_KEYS = ['projects', 'research_runs', 'plan_generations', 'content_generations', 'members'];

const { ctx, state, to, t, toast, router } = useMk();
const status = computed(() => state.status);
const packages = computed(() => state.status?.packages ?? []);
const buying = ref(null);

function isActive(pkg) {
    return state.status?.subscription?.active && state.status?.subscription?.package?.code === pkg.code;
}

async function onPaid() {
    buying.value = null;
    toast.showSuccess(t('marketing.paid'));
    await ctx.loadStatus();
    router.push(to('home'));
}

onMounted(() => {
    if (! state.status) {
        ctx.loadStatus();
    }
});
</script>
