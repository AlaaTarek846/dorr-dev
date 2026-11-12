<template>
    <div>
        <div class="d-md-flex d-block align-items-center justify-content-between my-4 page-header-breadcrumb">
            <h1 class="page-title fw-semibold fs-18 mb-0">{{ t('freelance.skill_tests.title') }}</h1>
            <div class="ms-md-1 ms-0">
                <nav>
                    <ol class="breadcrumb mb-0">
                        <li class="breadcrumb-item">
                            <router-link :to="{ name: 'admin.dashboard' }">{{ t('dashboard') }}</router-link>
                        </li>
                        <li class="breadcrumb-item">{{ t('sidebar.freelance') }}</li>
                        <li class="breadcrumb-item active" aria-current="page">{{ t('freelance.skill_tests.title') }}</li>
                    </ol>
                </nav>
            </div>
        </div>

        <div class="alert alert-light border mb-3 fs-13">{{ t('freelance.skill_tests.hint') }}</div>

        <ul class="nav nav-tabs mb-3">
            <li v-for="tb in ['tests', 'badges']" :key="tb" class="nav-item">
                <button type="button" class="nav-link" :class="{ active: tab === tb }" @click="tab = tb">{{ t(`freelance.skill_tests.tab_${tb}`) }}</button>
            </li>
        </ul>

        <!-- Tests: one row per skill with its bank and switch -->
        <div v-if="tab === 'tests'" class="card custom-card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 280px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="search" type="search" class="form-control" :placeholder="t('search_placeholder')">
                </div>
                <Select v-model="only" :options="onlyOptions" option-label="label" option-value="value" class="wallet-filter-select" />
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.skill_tests.skill') }}</th>
                                <th>{{ t('freelance.skill_tests.questions') }}</th>
                                <th>{{ t('freelance.skill_tests.active_questions') }}</th>
                                <th>{{ t('freelance.skill_tests.test_on') }}</th>
                                <th>{{ t('freelance.skill_tests.badges') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <TableSkeleton v-if="loading" :rows="8" :layout="['avatar-text', 'text', 'text', 'badge', 'text', 'actions']" />
                            <tr v-else-if="!rows.length">
                                <td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <template v-else>
                            <tr v-for="row in rows" :key="row.id">
                                <td class="ps-4 fw-semibold">{{ row.name }}</td>
                                <td>{{ row.questions_total }}</td>
                                <td>{{ row.questions_active }}</td>
                                <td>
                                    <span class="badge" :class="row.test.status ? 'bg-success-transparent' : 'bg-secondary-transparent'">{{ row.test.status ? t('freelance.skill_tests.state_valid') : '—' }}</span>
                                </td>
                                <td>{{ row.badges }}</td>
                                <td class="text-end pe-4">
                                    <button type="button" class="btn btn-sm btn-primary-light btn-wave" @click="openSkill(row)">
                                        <i class="ri-settings-3-line me-1 align-middle"></i>{{ t('freelance.skill_tests.configure') }}
                                    </button>
                                </td>
                            </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-if="!loading && (pagination.last_page ?? 1) > 1" class="card-footer border-top-0 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-light btn-sm" :disabled="page <= 1" @click="page -= 1">{{ t('countries.previous') }}</button>
                <button type="button" class="btn btn-light btn-sm" :disabled="page >= pagination.last_page" @click="page += 1">{{ t('countries.next') }}</button>
            </div>
        </div>

        <!-- Badges earned -->
        <div v-else class="card custom-card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2 py-3">
                <Select v-model="badgeState" :options="stateOptions" option-label="label" option-value="value" class="wallet-filter-select" />
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('freelance.skill_tests.freelancer') }}</th>
                                <th>{{ t('freelance.skill_tests.skill') }}</th>
                                <th>{{ t('freelance.skill_tests.score') }}</th>
                                <th>{{ t('freelance.skill_tests.earned') }}</th>
                                <th>{{ t('freelance.skill_tests.expires') }}</th>
                                <th class="text-end pe-4">{{ t('countries.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="!badgeRows.length">
                                <td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td>
                            </tr>
                            <template v-else>
                            <tr v-for="b in badgeRows" :key="b.id">
                                <td class="ps-4">{{ b.freelancer.name }}</td>
                                <td>{{ b.skill.name }}</td>
                                <td>{{ b.score_percent }}%</td>
                                <td>{{ day(b.earned_at) }}</td>
                                <td>
                                    <span v-if="b.revoked_at" class="badge bg-danger-transparent" :title="b.revoked_reason || ''">{{ t('freelance.skill_tests.state_revoked') }}</span>
                                    <span v-else-if="!b.expires_at">{{ t('freelance.skill_tests.never') }}</span>
                                    <span v-else :class="{ 'text-danger': !b.is_valid }">{{ day(b.expires_at) }}</span>
                                </td>
                                <td class="text-end pe-4">
                                    <button v-if="canUpdate && !b.revoked_at" type="button" class="btn btn-sm btn-danger-light btn-wave" @click="revoking = b; revokeReason = ''">
                                        <i class="ri-forbid-line me-1 align-middle"></i>{{ t('freelance.skill_tests.revoke') }}
                                    </button>
                                </td>
                            </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- One skill: settings and the bank -->
        <WalletModal :show="!!skill" :title="skill ? `${t('freelance.skill_tests.configure')} · ${skill.name}` : ''" size="xl" @close="closeSkill">
            <template v-if="skill">
                <h6 class="fw-semibold mb-3">{{ t('freelance.skill_tests.settings') }}</h6>
                <div class="row g-3 mb-4">
                    <div class="col-6 col-md-3">
                        <label class="form-label">{{ t('freelance.skill_tests.count') }}</label>
                        <input v-model.number="settings.questions_count" type="number" min="3" max="50" class="form-control" :disabled="!canUpdate">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">{{ t('freelance.skill_tests.minutes') }}</label>
                        <input v-model.number="settings.minutes" type="number" min="1" max="180" class="form-control" :disabled="!canUpdate">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">{{ t('freelance.skill_tests.pass') }}</label>
                        <input v-model.number="settings.pass_percent" type="number" min="1" max="100" class="form-control" :disabled="!canUpdate">
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">{{ t('freelance.skill_tests.intermediate') }}</label>
                        <input v-model.number="settings.intermediate_percent" type="number" :min="settings.pass_percent || 1" max="100" class="form-control" :class="{ 'is-invalid': !!fieldError('intermediate_percent') }" :disabled="!canUpdate">
                        <div v-if="fieldError('intermediate_percent')" class="invalid-feedback d-block">{{ fieldError('intermediate_percent') }}</div>
                    </div>
                    <div class="col-6 col-md-3">
                        <label class="form-label">{{ t('freelance.skill_tests.retake') }}</label>
                        <input v-model.number="settings.retake_hours" type="number" min="0" class="form-control" :disabled="!canUpdate">
                    </div>
                    <!-- FS-602: advanced / expert come from a practical challenge staff decide on the quality desk; the brief says what to build. -->
                    <div class="col-12">
                        <div class="border rounded p-3">
                            <div class="d-flex align-items-center gap-3 mb-2">
                                <div class="toggle toggle-success mb-0" :class="{ on: settings.challenge_enabled }" role="button" tabindex="0" @click="canUpdate && (settings.challenge_enabled = !settings.challenge_enabled)"><span></span></div>
                                <span class="fw-semibold">{{ t('freelance.skill_tests.challenge_enabled') }}</span>
                            </div>
                            <p class="text-muted fs-12 mb-2">{{ t('freelance.skill_tests.challenge_hint') }}</p>
                            <CatalogTranslationTabs :languages="briefLanguages" :active-locale="briefLocale" :translation-tab-class="briefTabClass" :translation-tab-feedback="briefTabFeedback" @update:active-locale="briefLocale = $event" />
                            <label class="form-label">{{ t('freelance.skill_tests.challenge_brief') }} <span v-if="settings.challenge_enabled" class="text-danger">*</span></label>
                            <textarea v-model="settings.challenge_brief[briefLocale]" rows="4" maxlength="3000" class="form-control" :class="{ 'is-invalid': !!fieldError('challenge_brief') }" :dir="briefLocale === 'ar' ? 'rtl' : 'ltr'" :disabled="!canUpdate" :placeholder="t('freelance.skill_tests.challenge_brief_hint')"></textarea>
                            <div class="d-flex justify-content-between fs-11 mt-1">
                                <span class="text-danger">{{ fieldError('challenge_brief') }}</span>
                                <span class="text-muted" dir="ltr">{{ (settings.challenge_brief[briefLocale] || '').length }}/3000</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 d-flex align-items-center gap-3">
                        <div class="toggle toggle-success mb-0" :class="{ on: settings.status }" role="button" tabindex="0" @click="canUpdate && (settings.status = !settings.status)"><span></span></div>
                        <span>{{ t('freelance.skill_tests.enabled') }}</span>
                        <span v-if="activeCount < skill.min_questions" class="text-warning fs-12">{{ t('freelance.skill_tests.need_min') }}</span>
                        <button v-if="canUpdate" type="button" class="btn btn-primary btn-sm ms-auto" :disabled="saving" @click="saveSettings">{{ t('save') }}</button>
                    </div>
                </div>

                <div class="d-flex align-items-center justify-content-between mb-2">
                    <h6 class="fw-semibold mb-0">{{ t('freelance.skill_tests.bank') }} <span class="badge bg-primary-transparent ms-1">{{ skill.questions.length }}</span></h6>
                    <button v-if="canCreate" type="button" class="btn btn-outline-primary btn-sm" @click="startQuestion()"><i class="ri-add-line me-1 align-middle"></i>{{ t('freelance.skill_tests.add_question') }}</button>
                </div>

                <!-- the question being written -->
                <form v-if="draft" class="border rounded p-3 mb-3 bg-light" @submit.prevent="saveQuestion">
                    <div class="fw-semibold mb-2">{{ draft.id ? t('freelance.skill_tests.edit_question') : t('freelance.skill_tests.add_question') }}</div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6"><label class="form-label fs-12">{{ t('freelance.skill_tests.prompt') }} (AR)</label><textarea v-model="draft.prompt.ar" rows="2" class="form-control" dir="rtl"></textarea></div>
                        <div class="col-md-6"><label class="form-label fs-12">{{ t('freelance.skill_tests.prompt') }} (EN)</label><textarea v-model="draft.prompt.en" rows="2" class="form-control" dir="ltr"></textarea></div>
                    </div>
                    <label class="form-label fs-12">{{ t('freelance.skill_tests.options') }}</label>
                    <div v-for="(o, i) in draft.options" :key="i" class="d-flex align-items-center gap-2 mb-2">
                        <input class="form-check-input mt-0" type="radio" name="right-option" :checked="o.correct" :title="t('freelance.skill_tests.right')" @change="markRight(i)">
                        <input v-model="o.text.ar" type="text" class="form-control form-control-sm" dir="rtl" placeholder="AR">
                        <input v-model="o.text.en" type="text" class="form-control form-control-sm" dir="ltr" placeholder="EN">
                        <button type="button" class="btn btn-sm btn-light btn-icon" :disabled="draft.options.length <= 2" @click="removeOption(i)"><i class="ri-close-line"></i></button>
                    </div>
                    <button v-if="draft.options.length < 6" type="button" class="btn btn-link btn-sm p-0" @click="addOption">{{ t('freelance.skill_tests.add_option') }}</button>
                    <div v-if="formError" class="text-danger fs-13 mt-2">{{ formError }}</div>
                    <div class="d-flex justify-content-between align-items-center mt-3">
                        <div class="form-check form-switch mb-0">
                            <input id="q-active" v-model="draft.status" class="form-check-input" type="checkbox">
                            <label class="form-check-label" for="q-active">{{ t('freelance.skill_tests.active_questions') }}</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-light btn-sm" @click="draft = null">{{ t('cancel') }}</button>
                            <button type="submit" class="btn btn-primary btn-sm" :disabled="saving">{{ t('save') }}</button>
                        </div>
                    </div>
                </form>

                <ol class="list-group list-group-numbered">
                    <li v-for="q in skill.questions" :key="q.id" class="list-group-item d-flex justify-content-between align-items-start gap-3" :class="{ 'opacity-50': !q.status }">
                        <div class="me-auto">
                            <div class="fw-semibold">{{ q.prompt[locale] || q.prompt.ar }}</div>
                            <ul class="list-unstyled mb-0 mt-1 fs-13">
                                <li v-for="(o, i) in q.options" :key="i" :class="o.correct ? 'text-success fw-semibold' : 'text-muted'">
                                    <i :class="o.correct ? 'ri-checkbox-circle-fill' : 'ri-checkbox-blank-circle-line'" class="me-1 align-middle"></i>{{ o.text[locale] || o.text.ar }}
                                </li>
                            </ul>
                        </div>
                        <div class="btn-list flex-shrink-0">
                            <button v-if="canUpdate" type="button" class="btn btn-sm btn-info-light btn-icon" @click="startQuestion(q)"><i class="ri-pencil-line"></i></button>
                            <button v-if="canDelete" type="button" class="btn btn-sm btn-danger-light btn-icon" @click="removeQuestion(q)"><i class="ri-delete-bin-line"></i></button>
                        </div>
                    </li>
                </ol>
                <p v-if="!skill.questions.length && !draft" class="text-muted text-center my-4">{{ t('wallet.common.empty') }}</p>
            </template>
        </WalletModal>

        <!-- Revoke a badge -->
        <WalletModal :show="!!revoking" :title="t('freelance.skill_tests.revoke')" size="md" @close="revoking = null">
            <template v-if="revoking">
                <p>{{ revoking.freelancer.name }} · {{ revoking.skill.name }}</p>
                <label class="form-label">{{ t('freelance.skill_tests.revoke_reason') }}</label>
                <input v-model="revokeReason" type="text" maxlength="200" class="form-control">
            </template>
            <template #footer>
                <button type="button" class="btn btn-light" @click="revoking = null">{{ t('cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="saving" @click="revoke">{{ t('freelance.skill_tests.revoke') }}</button>
            </template>
        </WalletModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import Select from 'primevue/select';
import adminAxios from '../../../../../../../api/adminAxios';
import CatalogTranslationTabs from '../../../../../../../components/catalog/CatalogTranslationTabs.vue';
import TableSkeleton from '../../../../../../../components/ui/TableSkeleton.vue';
import WalletModal from '../../../../../../../components/wallet/WalletModal.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { usePermission } from '../../../../../../../composables/usePermission';
import { useAvailableLanguagesStore } from '../../../../../../../stores/availableLanguages';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showSuccess, showError } = useToast();

const canCreate = computed(() => can('freelance-catalogs.create'));
const canUpdate = computed(() => can('freelance-catalogs.update'));
const canDelete = computed(() => can('freelance-catalogs.delete'));

const tab = ref('tests');
const saving = ref(false);
const day = (v) => (v ? new Date(v).toLocaleDateString(locale.value) : '—');

// ---- the skills list
const rows = ref([]);
const pagination = ref({});
const loading = ref(false);
const search = ref('');
const only = ref('all');
const page = ref(1);
const onlyOptions = computed(() => [
    { value: 'all', label: t('freelance.skill_tests.only_all') },
    { value: 'with_test', label: t('freelance.skill_tests.only_with_test') },
    { value: 'on', label: t('freelance.skill_tests.only_on') },
]);

async function load() {
    loading.value = true;
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-skill-tests', { params: { page: page.value, per_page: 15, search: search.value || undefined, only: only.value === 'all' ? undefined : only.value } });
        rows.value = data.data ?? [];
        pagination.value = data.pagination ?? {};
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

let timer = null;
watch(search, () => { clearTimeout(timer); timer = setTimeout(() => { page.value === 1 ? load() : (page.value = 1); }, 300); });
watch(only, () => { page.value === 1 ? load() : (page.value = 1); });
watch(page, load);

// ---- one skill: settings and the bank
const skill = ref(null);
const settings = reactive({ status: false, questions_count: 10, minutes: 15, pass_percent: 70, retake_hours: 24, intermediate_percent: 85, challenge_enabled: false, challenge_brief: { ar: '', en: '' } });
const settingsErrors = ref({});
const fieldError = (key) => settingsErrors.value[key]?.[0] ?? '';

// FS-602: the challenge brief is stored as {ar, en}; its language tabs show the panel's languages that the brief keeps.
const BRIEF_LOCALES = ['ar', 'en'];
const languagesStore = useAvailableLanguagesStore();
const briefLanguages = computed(() => {
    const fromStore = (languagesStore.items ?? []).filter((lang) => BRIEF_LOCALES.includes(lang.code));
    return fromStore.length ? fromStore : BRIEF_LOCALES.map((code) => ({ code, name: code.toUpperCase() }));
});
const briefLocale = ref('ar');
const briefFilled = (code) => !!(settings.challenge_brief?.[code] || '').trim();
const briefTabClass = (code) => ({ active: briefLocale.value === code, 'catalog-lang-tab--error': !!fieldError('challenge_brief'), 'catalog-lang-tab--valid': briefFilled(code) });
const briefTabFeedback = (code) => ({ show: briefFilled(code) || !!fieldError('challenge_brief'), valid: briefFilled(code) });
const draft = ref(null);
const formError = ref('');
const activeCount = computed(() => (skill.value?.questions ?? []).filter((q) => q.status).length);

async function openSkill(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/freelance-skill-tests/${row.id}`);
        applySkill(data.data);
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

function applySkill(data) {
    skill.value = data;
    const brief = data.test?.challenge_brief && !Array.isArray(data.test.challenge_brief) ? data.test.challenge_brief : {};
    Object.assign(settings, data.test, { challenge_brief: { ar: brief.ar ?? '', en: brief.en ?? '' } });
    settingsErrors.value = {};
}

function closeSkill() {
    skill.value = null;
    draft.value = null;
    load();
}

async function saveSettings() {
    saving.value = true;
    settingsErrors.value = {};
    try {
        const { data } = await adminAxios.put(`/api/admin/v1/freelance-skill-tests/${skill.value.id}`, { ...settings, challenge_brief: { ...settings.challenge_brief } });
        applySkill(data.data);
        showSuccess(t('freelance.skill_tests.saved'));
    } catch (error) {
        settingsErrors.value = error?.response?.data?.errors ?? {};
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

const blankOption = () => ({ text: { ar: '', en: '' }, correct: false });

function startQuestion(q = null) {
    formError.value = '';
    draft.value = q
        ? JSON.parse(JSON.stringify(q))
        : { id: null, status: true, prompt: { ar: '', en: '' }, options: [{ ...blankOption(), correct: true }, blankOption()] };
}

const addOption = () => draft.value.options.push(blankOption());
const removeOption = (i) => {
    const wasRight = draft.value.options[i].correct;
    draft.value.options.splice(i, 1);
    if (wasRight) {
        draft.value.options[0].correct = true;
    }
};
const markRight = (i) => draft.value.options.forEach((o, n) => { o.correct = n === i; });

async function saveQuestion() {
    saving.value = true;
    formError.value = '';
    const d = draft.value;
    const body = { prompt: d.prompt, options: d.options, status: !!d.status };
    try {
        if (d.id) {
            await adminAxios.put(`/api/admin/v1/freelance-skill-questions/${d.id}`, body);
        } else {
            await adminAxios.post(`/api/admin/v1/freelance-skill-tests/${skill.value.id}/questions`, body);
        }
        draft.value = null;
        const { data } = await adminAxios.get(`/api/admin/v1/freelance-skill-tests/${skill.value.id}`);
        applySkill(data.data);
        showSuccess(t('freelance.skill_tests.question_saved'));
    } catch (error) {
        formError.value = extractApiErrorMessage(error, t('toast.error'));
    } finally {
        saving.value = false;
    }
}

async function removeQuestion(q) {
    saving.value = true;
    try {
        await adminAxios.delete(`/api/admin/v1/freelance-skill-questions/${q.id}`);
        const { data } = await adminAxios.get(`/api/admin/v1/freelance-skill-tests/${skill.value.id}`);
        applySkill(data.data);
        showSuccess(t('freelance.skill_tests.question_deleted'));
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

// ---- badges
const badgeRows = ref([]);
const badgeState = ref('valid');
const revoking = ref(null);
const revokeReason = ref('');
const stateOptions = computed(() => ['valid', 'revoked', 'all'].map((v) => ({ value: v, label: t(`freelance.skill_tests.state_${v}`) })));

async function loadBadges() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/freelance-skill-badges', { params: { state: badgeState.value, per_page: 50 } });
        badgeRows.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}

async function revoke() {
    saving.value = true;
    try {
        await adminAxios.post(`/api/admin/v1/freelance-skill-badges/${revoking.value.id}/revoke`, { reason: revokeReason.value || undefined });
        revoking.value = null;
        showSuccess(t('freelance.skill_tests.revoked'));
        loadBadges();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        saving.value = false;
    }
}

watch(badgeState, loadBadges);
watch(tab, (v) => { if (v === 'badges') loadBadges(); });
onMounted(() => {
    load();
    languagesStore.fetch();
});
</script>
