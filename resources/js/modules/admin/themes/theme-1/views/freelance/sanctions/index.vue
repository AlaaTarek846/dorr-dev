<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.sanctions.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item"><router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link></li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.sanctions.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2 py-3">
                <button v-for="tab in tabs" :key="tab" type="button" class="btn btn-sm btn-wave" :class="view === tab ? 'btn-primary' : 'btn-light'" @click="view = tab">
                    {{ t(`freelance.sanctions.tabs.${tab}`) }} <span v-if="!['all', 'clients'].includes(tab)" class="badge bg-white text-default ms-1">{{ counts[tab] ?? 0 }}</span>
                </button>
                <div class="ms-md-auto d-flex flex-wrap gap-2">
                    <select v-if="view === 'clients'" v-model="risk" class="form-select form-select-sm w-auto">
                        <option value="">{{ t('freelance.sanctions.all_risks') }}</option>
                        <option v-for="r in ['high', 'medium', 'low']" :key="r" :value="r">{{ t(`freelance.sanctions.risk.${r}`) }} ({{ riskCounts[r] ?? 0 }})</option>
                    </select>
                    <select v-if="view !== 'clients'" v-model="type" class="form-select form-select-sm w-auto">
                        <option value="">{{ t('freelance.sanctions.all_types') }}</option>
                        <option v-for="ty in ['warning', 'chat_limit', 'work_limit', 'suspension']" :key="ty" :value="ty">{{ t(`freelance.sanctions.types.${ty}`) }}</option>
                    </select>
                    <select v-if="view !== 'clients'" v-model="side" class="form-select form-select-sm w-auto">
                        <option value="">{{ t('freelance.sanctions.all_sides') }}</option>
                        <option v-for="sd in ['freelancer', 'client']" :key="sd" :value="sd">{{ t(`freelance.sanctions.sides.${sd}`) }}</option>
                    </select>
                    <input v-model="search" type="search" class="form-control form-control-sm w-auto" :placeholder="t('freelance.sanctions.search')">
                    <button v-if="canIssue" type="button" class="btn btn-sm btn-danger" @click="picking = true"><i class="ri-add-line me-1"></i>{{ t('freelance.sanctions.issue') }}</button>
                </div>
            </div>
            <div class="alert alert-light border fs-12 m-3 mb-0">{{ t('freelance.sanctions.hint') }}</div>
            <div class="card-body">
                <div v-if="loading" class="text-center text-muted py-4">{{ t('flags.loading') }}</div>
                <p v-else-if="!rows.length" class="text-center text-muted py-4 mb-0">{{ t('freelance.sanctions.empty') }}</p>
                <template v-else-if="view === 'clients'">
                    <div v-for="c in rows" :key="c.id" class="border-bottom py-2 d-flex flex-wrap align-items-center gap-3">
                        <a href="#" class="fw-semibold" @click.prevent="openPerson('client', c.id, c.name)">{{ c.name || '#' + c.id }}</a>
                        <span class="text-muted fs-11" dir="ltr">{{ c.phone }}</span>
                        <span class="badge" :class="riskBadge(c.risk_level)">{{ t(`freelance.sanctions.risk.${c.risk_level}`) }}</span>
                        <span class="text-muted fs-12">{{ c.quality_score ?? '—' }} / 100</span>
                    </div>
                </template>
                <div v-for="s in rows" v-else :key="s.uuid" class="border-bottom py-2">
                    <SanctionLine :s="s" show-person @changed="load" @person="openPerson(s.side, s.person_id, s.person)" />
                </div>
            </div>
            <div v-if="(pagination.last_page ?? 1) > 1" class="card-footer d-flex gap-2 justify-content-end">
                <button type="button" class="btn btn-sm btn-light" :disabled="page <= 1" @click="page -= 1">{{ t('countries.previous') }}</button>
                <span class="align-self-center fs-13">{{ page }} / {{ pagination.last_page }}</span>
                <button type="button" class="btn btn-sm btn-light" :disabled="page >= pagination.last_page" @click="page += 1">{{ t('countries.next') }}</button>
            </div>
        </div>

        <!-- Pick the person, then issue from their page (it shows their quality, risk and history first). -->
        <WalletModal :show="picking" :title="t('freelance.sanctions.pick')" @close="picking = false">
            <div class="d-flex gap-2 mb-3">
                <select v-model="pickSide" class="form-select form-select-sm w-auto">
                    <option v-for="sd in ['freelancer', 'client']" :key="sd" :value="sd">{{ t(`freelance.sanctions.sides.${sd}`) }}</option>
                </select>
                <input v-model="pickSearch" type="search" class="form-control form-control-sm" :placeholder="t('freelance.sanctions.search')">
            </div>
            <p v-if="pickSearch.trim().length >= 2 && !people.length" class="text-muted fs-13">{{ t('freelance.sanctions.empty') }}</p>
            <button v-for="p in people" :key="p.id" type="button" class="btn btn-light btn-sm w-100 text-start mb-1" @click="openPerson(pickSide, p.id, p.name)">
                {{ p.name || '—' }} <span class="text-muted fs-11" dir="ltr">{{ p.phone }}</span>
            </button>
        </WalletModal>

        <WalletModal :show="!!personFor" :title="personFor?.name || ''" size="xl" @close="personFor = null">
            <PersonPanel v-if="personFor" :side="personFor.side" :id="personFor.id" @changed="load" />
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRoute } from 'vue-router';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import PersonPanel from './PersonPanel.vue';
import SanctionLine from './SanctionLine.vue';

// Warnings, limits and suspensions (FL-708): what is active, the appeals waiting for someone other than the issuer, and the history.
const { t } = useI18n();
const route = useRoute();
const { showError } = useToast();
const { can } = usePermission();
const endpoint = '/api/admin/v1/freelance-sanctions';
const canIssue = computed(() => can('freelance-sanctions.issue'));
const tabs = ['active', 'appeals', 'lifted', 'expired', 'all', 'clients'];
const risk = ref('');
const riskBadge = (level) => ({ high: 'bg-danger-transparent', medium: 'bg-warning-transparent', low: 'bg-success-transparent' }[level] ?? 'bg-light text-default');

const view = ref('active');
const type = ref('');
const side = ref('');
const search = ref('');
const page = ref(1);
const rows = ref([]);
const counts = ref({});
const riskCounts = ref({});
const pagination = ref({});
const loading = ref(false);

async function load() {
    loading.value = true;
    try {
        if (view.value === 'clients') {
            const { data } = await adminAxios.get(`${endpoint}/clients`, { params: { page: page.value, risk: risk.value || undefined, search: search.value || undefined } });
            rows.value = data.data ?? [];
            pagination.value = data.pagination ?? {};
            riskCounts.value = data.pagination?.counts ?? {};
            return;
        }
        const { data } = await adminAxios.get(endpoint, {
            params: {
                page: page.value,
                state: ['active', 'lifted', 'expired'].includes(view.value) ? view.value : undefined,
                appeal: view.value === 'appeals' ? 'open' : undefined,
                type: type.value || undefined, side: side.value || undefined, search: search.value || undefined,
            },
        });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
        counts.value = data.pagination?.counts ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => (page.value === 1 ? load() : (page.value = 1)), 300); });
watch([view, type, side, risk], () => (page.value === 1 ? load() : (page.value = 1)));
watch(page, load);

// ---- one person
const picking = ref(false);
const pickSide = ref('freelancer');
const pickSearch = ref('');
const people = ref([]);
const personFor = ref(null);
let pickTimer = null;
watch([pickSide, pickSearch], () => {
    clearTimeout(pickTimer);
    if (pickSearch.value.trim().length < 2) { people.value = []; return; }
    pickTimer = setTimeout(async () => {
        try {
            const { data } = await adminAxios.get(`${endpoint}/people`, { params: { side: pickSide.value, search: pickSearch.value.trim() } });
            people.value = data.data ?? [];
        } catch (error) {
            showError(extractApiErrorMessage(error, t('toast.error')));
        }
    }, 300);
});

function openPerson(personSide, id, name) {
    picking.value = false;
    personFor.value = { side: personSide, id: Number(id), name };
}

onMounted(() => {
    // Opened from another page for one person: ?side=freelancer&person=12
    if (route.query.person && ['client', 'freelancer'].includes(route.query.side)) openPerson(route.query.side, route.query.person, '');
    load();
});
</script>
