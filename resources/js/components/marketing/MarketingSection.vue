<template>
    <div class="mk">
        <router-view v-slot="{ Component, route }">
            <component :is="Component" :key="route.fullPath" />
        </router-view>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, provide } from 'vue';
import { createMarketingContext, provideKey } from './marketingContext';
import useMarketingRealtime from './useMarketingRealtime';

/**
 * The AI Marketing Director section of a SPA. `config` (from the SPA's thin wrapper page):
 * { portal, http, apiBase, walletBase, realtimeConfigPath, tokenKey, channelFor(id), accountId(), mePath }.
 * Holds the shared status and the one realtime connection for every marketing page.
 */
const props = defineProps({
    config: { type: Object, required: true },
});

const ctx = createMarketingContext(props.config);
provide(provideKey(), ctx);

const realtime = useMarketingRealtime(ctx);

async function accountId() {
    const known = props.config.accountId?.();
    if (known) {
        return known;
    }
    try {
        const { data } = await props.config.http.get(props.config.mePath);

        return (data.data?.user ?? data.data?.provider ?? data.data)?.id ?? null;
    } catch {
        return null;
    }
}

onMounted(async () => {
    const id = await accountId();
    realtime.connect(id, (projectId) => ctx.changed(projectId));
});

onBeforeUnmount(() => realtime.disconnect());
</script>

<style>
/* AI Marketing Director — shared by the user and provider SPAs. Logical properties keep RTL right. */
.mk {
    --mk-accent: var(--primary-color, #845adf);
    --mk-radius: 0.75rem;
    padding-bottom: 2rem;
}

.mk .mk-head {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    margin: 1.5rem 0 1.25rem;
    flex-wrap: wrap;
}

.mk .mk-head__text {
    flex: 1 1 14rem;
    min-width: 0;
}

.mk .mk-head__title {
    font-size: 1.125rem;
    font-weight: 600;
    margin: 0;
    overflow-wrap: anywhere;
}

.mk .mk-back {
    width: 2.25rem;
    height: 2.25rem;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

[dir='rtl'] .mk .mk-flip {
    transform: scaleX(-1);
}

.mk .mk-card {
    border-radius: var(--mk-radius);
}

.mk .mk-nav {
    display: flex;
    align-items: center;
    gap: 0.875rem;
    padding: 0.875rem 1rem;
    color: inherit;
    text-decoration: none;
    cursor: pointer;
    transition: transform 0.15s ease, box-shadow 0.15s ease;
    width: 100%;
    background: none;
    border: 0;
    text-align: start;
}

.mk .mk-nav:hover {
    transform: translateY(-1px);
}

.mk .mk-nav__body {
    flex: 1;
    min-width: 0;
}

.mk .mk-nav__title {
    font-weight: 600;
    overflow-wrap: anywhere;
}

.mk .mk-nav__sub {
    font-size: 0.8125rem;
    color: var(--text-muted, #8c9097);
    overflow-wrap: anywhere;
}

.mk .mk-icon {
    width: 2.625rem;
    height: 2.625rem;
    border-radius: 0.75rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.mk .mk-bar {
    height: 6px;
    border-radius: 99px;
    background: rgba(140, 144, 151, 0.18);
    overflow: hidden;
}

.mk .mk-bar > span {
    display: block;
    height: 100%;
    border-radius: 99px;
    background: var(--mk-accent);
    transition: width 0.5s ease;
}

.mk .mk-section-title {
    font-size: 0.9375rem;
    font-weight: 600;
    margin: 1.5rem 0 0.75rem;
}

.mk .mk-pill {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    white-space: nowrap;
}

.mk .mk-pill::before {
    content: '';
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: currentColor;
}

.mk .mk-bullets {
    list-style: none;
    padding: 0;
    margin: 0;
}

.mk .mk-bullets li {
    position: relative;
    padding-inline-start: 1rem;
    margin-bottom: 0.35rem;
    line-height: 1.7;
}

.mk .mk-bullets li::before {
    content: '';
    position: absolute;
    inset-inline-start: 0;
    top: 0.7em;
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--mk-accent);
}

.mk .mk-prewrap {
    white-space: pre-line;
    line-height: 1.8;
    overflow-wrap: anywhere;
}

.mk .mk-ltr {
    direction: ltr;
    unicode-bidi: isolate;
}

.mk .mk-skeleton {
    border-radius: var(--mk-radius);
    background: linear-gradient(90deg, rgba(140, 144, 151, 0.1), rgba(140, 144, 151, 0.2), rgba(140, 144, 151, 0.1));
    background-size: 200% 100%;
    animation: mk-shimmer 1.4s ease-in-out infinite;
}

@keyframes mk-shimmer {
    from { background-position: 200% 0; }
    to { background-position: -200% 0; }
}

.mk .mk-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.mk .mk-actions > .btn {
    flex: 1 1 auto;
}

.mk .mk-table-scroll {
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
}

@media (max-width: 575.98px) {
    .mk .mk-head {
        margin-top: 1rem;
    }

    .mk .mk-actions > .btn {
        flex: 1 1 100%;
    }
}
</style>
