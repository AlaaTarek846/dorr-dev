<template>
    <div>
        <MkHeader :title="t('marketing.campaigns')" :subtitle="project?.name || ''" :back="to('project', { projectId })">
            <button v-if="enabled && canEdit" type="button" class="btn btn-primary" @click="creating = true">
                <i class="ri-add-line me-1"></i>{{ t('marketing.campaign_new') }}
            </button>
        </MkHeader>

        <MkUnavailable v-if="statusReady && !enabled" />
        <div v-else class="row g-3">
            <div class="col-xl-7">
                <div class="alert alert-info fs-13">{{ t('marketing.campaigns_note') }}</div>
                <div v-if="campaigns === null" class="d-grid gap-2">
                    <div v-for="i in 2" :key="i" class="mk-skeleton" style="height: 84px"></div>
                </div>
                <div v-else-if="campaigns.length === 0" class="card custom-card mk-card">
                    <div class="card-body text-center py-5">
                        <span class="mk-icon bg-pink-transparent text-pink mb-3"><i class="ri-megaphone-line"></i></span>
                        <h6 class="fw-semibold">{{ t('marketing.campaigns_empty') }}</h6>
                        <p class="text-muted mb-0">{{ t('marketing.campaigns_empty_sub') }}</p>
                    </div>
                </div>
                <div v-else class="d-grid gap-2">
                    <MkNavCard
                        v-for="c in campaigns"
                        :key="c.id"
                        icon="ri-megaphone-line"
                        tone="pink"
                        :title="c.name"
                        :subtitle="`${mkMoney(c.total_budget_minor, c.currency)} · ${t(`marketing.objectives.${c.objective}`)}`"
                        :to="to('campaign', { projectId, campaignId: c.id })"
                    >
                        <template #trailing>
                            <span class="badge mk-pill" :class="`bg-${campaignStatus(c.status).tone}-transparent`">{{ t(campaignStatus(c.status).key) }}</span>
                        </template>
                    </MkNavCard>
                </div>
            </div>

            <!-- Spending delegations: the owner sets them, everyone sees them -->
            <div class="col-xl-5">
                <div class="d-flex align-items-center gap-2 mk-section-title mt-xl-0">
                    <span class="flex-grow-1">{{ t('marketing.delegations') }}</span>
                    <button v-if="owner" type="button" class="btn btn-light btn-sm" @click="delegating = true">
                        <i class="ri-add-line me-1"></i>{{ t('marketing.add') }}
                    </button>
                </div>
                <p class="text-muted fs-12">{{ t('marketing.delegations_note') }}</p>
                <p v-if="!delegations.length" class="text-muted fs-13">{{ t('marketing.no_delegations') }}</p>
                <div v-else class="d-grid gap-2">
                    <div v-for="d in delegations" :key="d.id" class="card custom-card mk-card mb-0">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2">
                                <span class="mk-icon bg-warning-transparent text-warning"><i class="ri-shake-hands-line"></i></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold">{{ d.name || '—' }}</div>
                                    <div class="text-muted fs-12">{{ t('marketing.until', { date: date(d.expires_at) }) }}</div>
                                </div>
                                <span class="badge" :class="d.status === 'active' ? 'bg-success-transparent' : 'bg-light text-muted'">
                                    {{ t(`marketing.delegation_status.${['active', 'expired'].includes(d.status) ? d.status : 'revoked'}`) }}
                                </span>
                            </div>
                            <MkBar class="mt-3" :value="d.cap_minor > 0 ? (d.used_minor / d.cap_minor) * 100 : 0" color="rgb(var(--warning-rgb))" />
                            <div class="text-muted fs-12 mt-1">
                                {{ t('marketing.delegation_left', { left: mkMoney(d.remaining_minor, d.currency || currency), cap: mkMoney(d.cap_minor, d.currency || currency) }) }}
                            </div>
                            <button v-if="owner && d.status === 'active'" type="button" class="btn btn-outline-danger btn-sm mt-2" @click="revoking = d">
                                <i class="ri-lock-line me-1"></i>{{ t('marketing.revoke') }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <CampaignFormModal :show="creating" :project-id="projectId" :currency="currency" @close="creating = false" @saved="onCreated" />

        <!-- New delegation -->
        <MkModal :show="delegating" :title="t('marketing.delegation_new')" :busy="busy" @close="delegating = false">
            <p class="text-muted fs-13">{{ t('marketing.delegations_note') }}</p>
            <p v-if="!managers.length" class="text-warning fs-13">{{ t('marketing.no_managers') }}</p>
            <MkChips v-else v-model="dlg.member" :options="managers.map((m) => ({ value: m.id, label: m.name || m.phone }))" />
            <label class="form-label mt-3" for="mk-d-cap">{{ t('marketing.delegation_cap') }}</label>
            <div class="input-group">
                <input id="mk-d-cap" v-model="dlg.cap" class="form-control mk-ltr" :class="{ 'is-invalid': dlg.cap && capMinor === null }" inputmode="decimal" placeholder="0.00" maxlength="14">
                <span v-if="currency" class="input-group-text">{{ currency }}</span>
            </div>
            <label class="form-label mt-3" for="mk-d-until">{{ t('marketing.valid_until') }}</label>
            <input id="mk-d-until" v-model="dlg.until" type="date" class="form-control mk-ltr" :min="addDays(today, 1)" :max="addDays(today, 90)">
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="delegating = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy || !dlg.member || capMinor === null || capMinor < 100 || !dlg.until" @click="delegate">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <MkModal :show="revoking !== null" :title="t('marketing.revoke')" :busy="busy" @close="revoking = null">
            <p class="mb-0">{{ t('marketing.revoke_text') }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="revoking = null">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="busy" @click="revoke">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.revoke') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { addDays, campaignStatus, localDay, localIso, mkMoney, mkParseMinor } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkNavCard from './parts/MkNavCard.vue';
import MkBar from './parts/MkBar.vue';
import MkChips from './parts/MkChips.vue';
import MkModal from './parts/MkModal.vue';
import MkUnavailable from './parts/MkUnavailable.vue';
import CampaignFormModal from './parts/CampaignFormModal.vue';

/** Campaigns (U09) and spending delegations (D7). Reloads once per `marketing.campaign.changed` of this project. */
const {
    projectId, project, abilities, statusReady, enabled, currency, http, api, to, fail, date, t, toast, router, onPush,
} = useProjectPage('campaigns');

const today = localDay();
const campaigns = ref(null);
const delegations = ref([]);
const managers = ref([]);
const creating = ref(false);
const delegating = ref(false);
const revoking = ref(null);
const busy = ref(false);
const dlg = reactive({ member: null, cap: '', until: addDays(today, 30) });

const canEdit = computed(() => abilities.value.edit === true);
const owner = computed(() => abilities.value.manage_team === true);
const capMinor = computed(() => mkParseMinor(dlg.cap));

async function load() {
    if (! enabled.value) return;
    try {
        const [c, d] = await Promise.all([
            http.get(api(`/projects/${projectId}/campaigns`)),
            http.get(api(`/projects/${projectId}/delegations`)),
        ]);
        campaigns.value = c.data.data ?? [];
        delegations.value = d.data.data ?? [];
    } catch (error) {
        campaigns.value = campaigns.value ?? [];
        fail(error);
    }
}

function onCreated(campaign) {
    creating.value = false;
    if (campaign?.id) router.push(to('campaign', { projectId, campaignId: campaign.id }));
}

watch(delegating, async (open) => {
    if (! open) return;
    Object.assign(dlg, { member: null, cap: '', until: addDays(today, 30) });
    try {
        const { data } = await http.get(api(`/projects/${projectId}/members`));
        managers.value = (data.data ?? []).filter((m) => m.role === 'manager');
    } catch (error) {
        fail(error);
    }
});

async function delegate() {
    busy.value = true;
    try {
        const { data } = await http.post(api(`/projects/${projectId}/delegations`), {
            member_id: dlg.member,
            cap_minor: capMinor.value,
            expires_at: localIso(dlg.until, '23:00'),
        });
        toast.showSuccess(data.message);
        delegating.value = false;
        load();
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

async function revoke() {
    busy.value = true;
    try {
        const { data } = await http.delete(api(`/delegations/${revoking.value.id}`));
        toast.showSuccess(data.message);
        revoking.value = null;
        load();
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

watch(enabled, (on) => on && load());
onPush(load);
onMounted(load);
</script>
