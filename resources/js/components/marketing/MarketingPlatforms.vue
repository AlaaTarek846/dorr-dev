<template>
    <div>
        <MkHeader :title="t('marketing.platforms')" :subtitle="project?.name || ''" :back="to('project', { projectId })" />

        <MkUnavailable v-if="statusReady && !enabled" />
        <template v-else>
            <div class="alert alert-info fs-13">{{ t('marketing.platforms_note') }}</div>
            <div v-if="data === null" class="row g-3">
                <div v-for="i in 2" :key="i" class="col-lg-6"><div class="mk-skeleton" style="height: 110px"></div></div>
            </div>
            <div v-else class="row g-3">
                <div v-for="pf in data.platforms || []" :key="pf.provider" class="col-lg-6">
                    <div class="card custom-card mk-card h-100 mb-0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-3">
                                <span class="mk-icon" :class="pf.available ? 'bg-info-transparent text-info' : 'bg-light text-muted'"><i class="ri-share-circle-line"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold">{{ t(`marketing.platform_names.${PLATFORMS.includes(pf.provider) ? pf.provider : 'meta'}`) }}</div>
                                    <div class="text-muted fs-13 text-break">{{ subtitle(pf) }}</div>
                                </div>
                                <span v-if="link(pf)?.status === 'CONNECTED'" class="badge bg-success-transparent">{{ t('marketing.connected') }}</span>
                                <span v-else-if="link(pf)?.reconnect_needed" class="badge bg-danger-transparent">{{ t('marketing.reconnect_needed') }}</span>
                                <span v-else-if="link(pf)?.status === 'PENDING' && link(pf)?.available_accounts" class="badge bg-warning-transparent">{{ t('marketing.choose_account') }}</span>
                            </div>
                            <span v-if="link(pf)?.sandbox" class="badge bg-warning-transparent mt-2">{{ t('marketing.sandbox') }}</span>

                            <!-- OAuth found several ad accounts: the owner picks one -->
                            <div v-if="owner && link(pf)?.status === 'PENDING' && Array.isArray(link(pf)?.available_accounts)" class="mt-3">
                                <p v-if="!link(pf).available_accounts.length" class="text-danger fs-13 mb-0">{{ t('marketing.no_ad_accounts') }}</p>
                                <template v-else>
                                    <div class="text-muted fs-13 fw-semibold mb-2">{{ t('marketing.choose_account') }}</div>
                                    <div class="d-grid gap-2">
                                        <button
                                            v-for="acc in link(pf).available_accounts"
                                            :key="acc.id"
                                            type="button"
                                            class="btn btn-light btn-sm text-start"
                                            :disabled="busy !== null"
                                            @click="chooseAccount(link(pf), acc)"
                                        >
                                            <span v-if="busy === acc.id" class="spinner-border spinner-border-sm me-1"></span>
                                            {{ acc.name || acc.id }}<template v-if="acc.currency"> · {{ acc.currency }}</template>
                                        </button>
                                    </div>
                                </template>
                            </div>

                            <div v-if="owner && pf.available && pf.configured && !pf.coming_soon" class="mk-actions mt-3">
                                <button v-if="link(pf)?.status !== 'CONNECTED'" type="button" class="btn btn-primary btn-sm" :disabled="busy !== null" @click="connect(pf)">
                                    <span v-if="busy === pf.provider" class="spinner-border spinner-border-sm me-1"></span>
                                    <i v-else class="ri-link me-1"></i>{{ link(pf)?.reconnect_needed ? t('marketing.reconnect') : t('marketing.connect') }}
                                </button>
                                <button v-if="link(pf)" type="button" class="btn btn-outline-danger btn-sm" :disabled="busy !== null" @click="disconnecting = link(pf)">
                                    <i class="ri-link-unlink me-1"></i>{{ t('marketing.disconnect') }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <p v-if="project && !owner" class="text-muted fs-13 mt-3">{{ t('marketing.owner_connects') }}</p>
        </template>

        <MkModal :show="disconnecting !== null" :title="t('marketing.disconnect')" :busy="busy !== null" @close="disconnecting = null">
            <p class="mb-0">{{ t('marketing.disconnect_text') }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="disconnecting = null">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="busy !== null" @click="disconnect">
                    <span v-if="busy === 'disconnect'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.disconnect') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkModal from './parts/MkModal.vue';
import MkUnavailable from './parts/MkUnavailable.vue';

const PLATFORMS = ['meta', 'google', 'tiktok', 'snapchat'];

/**
 * Ad platforms (U10): Meta now, the others shown as coming. The owner connects; the platform's own page opens in a
 * new tab and this page reloads once when the server pushes `marketing.integration.changed` (or on reopen).
 */
const {
    projectId, project, abilities, statusReady, enabled, http, api, to, fail, t, toast, onPush,
} = useProjectPage('platforms');

const data = ref(null);
const busy = ref(null);
const disconnecting = ref(null);
const owner = computed(() => abilities.value.manage_team === true);

function link(pf) {
    return (data.value?.integrations ?? []).find((i) => i.provider === pf.provider && i.status !== 'REVOKED') ?? null;
}

function subtitle(pf) {
    const i = link(pf);
    if (pf.coming_soon) return t('marketing.coming_soon');
    if (! pf.available) return t('marketing.platform_off_here');
    if (! pf.configured) return t('marketing.platform_not_ready');
    if (i?.account) return i.account.name || i.account.id;

    return t('marketing.platform_not_connected');
}

async function load() {
    if (! enabled.value) return;
    try {
        const { data: body } = await http.get(api(`/projects/${projectId}/integrations`));
        data.value = body.data;
    } catch (error) {
        data.value = data.value ?? { integrations: [], platforms: [] };
        fail(error);
    }
}

async function connect(pf) {
    // Open the tab inside the click (popup blockers), then point it at the platform once the server answers.
    const tab = window.open('about:blank', '_blank');
    busy.value = pf.provider;
    try {
        const { data: body } = await http.post(api(`/projects/${projectId}/integrations/${pf.provider}/connect`));
        const url = body.data?.authorize_url;
        if (url) {
            if (tab) {
                tab.opener = null;
                tab.location.href = url;
            } else {
                window.open(url, '_blank', 'noopener');
            }
            toast.showInfo(t('marketing.platform_continue'));
        } else {
            tab?.close();
            toast.showSuccess(t('marketing.platform_connected'));
        }
        load();
    } catch (error) {
        tab?.close();
        fail(error);
    } finally {
        busy.value = null;
    }
}

async function chooseAccount(integration, acc) {
    busy.value = acc.id;
    try {
        await http.post(api(`/integrations/${integration.id}/account`), { account_id: acc.id });
        toast.showSuccess(t('marketing.platform_connected'));
        load();
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
    }
}

async function disconnect() {
    busy.value = 'disconnect';
    try {
        const { data: body } = await http.post(api(`/integrations/${disconnecting.value.id}/disconnect`));
        toast.showSuccess(body.message);
        disconnecting.value = null;
        load();
    } catch (error) {
        fail(error);
    } finally {
        busy.value = null;
    }
}

watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>
