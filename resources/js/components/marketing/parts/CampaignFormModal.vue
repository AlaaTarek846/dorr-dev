<template>
    <MkModal :show="show" :title="existing ? t('marketing.edit') : t('marketing.campaign_new')" :busy="busy" wide @close="$emit('close')">
        <label class="form-label" for="mk-c-name">{{ t('marketing.campaign_name') }}</label>
        <input id="mk-c-name" v-model="form.name" class="form-control" maxlength="120">

        <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.objective') }}</label>
        <MkChips v-model="form.objective" :options="OBJECTIVES.map((o) => ({ value: o, label: t(`marketing.objectives.${o}`) }))" />

        <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.ad_account') }}</label>
        <p v-if="accounts.length === 0" class="text-warning fs-13 mb-0">{{ t('marketing.no_connected_account') }}</p>
        <MkChips v-else v-model="form.integration" :options="accounts.map((a) => ({ value: a.id, label: a.account?.name || a.account?.id || a.provider }))" />

        <div class="row g-2 mt-1">
            <div class="col-md-4">
                <label class="form-label" for="mk-c-daily">{{ t('marketing.daily_budget') }}</label>
                <div class="input-group">
                    <input id="mk-c-daily" v-model="form.daily" class="form-control mk-ltr" :class="{ 'is-invalid': form.daily && dailyMinor === null }" inputmode="decimal" placeholder="0.00" maxlength="12">
                    <span v-if="currency" class="input-group-text">{{ currency }}</span>
                </div>
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label" for="mk-c-from">{{ t('marketing.from') }}</label>
                <input id="mk-c-from" v-model="form.start" type="date" class="form-control mk-ltr" :min="today">
            </div>
            <div class="col-6 col-md-4">
                <label class="form-label" for="mk-c-to">{{ t('marketing.to') }}</label>
                <input id="mk-c-to" v-model="form.end" type="date" class="form-control mk-ltr" :min="form.start">
            </div>
        </div>
        <div v-if="dailyMinor && days > 0" class="text-primary fw-bold fs-13 mt-2">
            {{ t('marketing.total_will_be', { amount: mkMoney(dailyMinor * days, currency), days }) }}
        </div>

        <div class="row g-2 mt-1">
            <div class="col-md-6">
                <label class="form-label" for="mk-c-countries">{{ t('marketing.countries') }}</label>
                <input id="mk-c-countries" v-model="form.countries" class="form-control mk-ltr" placeholder="SA, AE" maxlength="80" dir="ltr">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="mk-c-amin">{{ t('marketing.age_from') }}</label>
                <input id="mk-c-amin" v-model="form.ageMin" class="form-control mk-ltr" inputmode="numeric" maxlength="2">
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label" for="mk-c-amax">{{ t('marketing.age_to') }}</label>
                <input id="mk-c-amax" v-model="form.ageMax" class="form-control mk-ltr" inputmode="numeric" maxlength="2">
            </div>
        </div>

        <template v-if="ads.length">
            <label class="form-label fw-semibold fs-13 mt-3">{{ t('marketing.link_ad') }}</label>
            <MkChips v-model="form.content" :options="[{ value: null, label: t('marketing.none') }, ...ads.map((a) => ({ value: a.id, label: a.title.slice(0, 28) }))]" />
        </template>
        <div v-if="existing?.approval" class="alert alert-warning fs-13 mt-3 mb-0">{{ t('marketing.edit_drops_approval') }}</div>

        <template #footer>
            <button type="button" class="btn btn-light" :disabled="busy" @click="$emit('close')">{{ t('marketing.cancel') }}</button>
            <button type="button" class="btn btn-primary" :disabled="busy || !valid" @click="save">
                <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
            </button>
        </template>
    </MkModal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { OBJECTIVES, addDays, localDay, localIso, mkMinorToInput, mkMoney, mkNormalizeDigits, mkParseMinor, parseDay, useMk } from '../marketingContext';
import MkModal from './MkModal.vue';
import MkChips from './MkChips.vue';

/** A new campaign draft, or a change to one (a new version — any approval stops counting). Nothing is sent to a platform. */
const props = defineProps({
    show: { type: Boolean, default: false },
    projectId: { type: String, required: true },
    existing: { type: Object, default: null },
    currency: { type: String, default: '' },
});
const emit = defineEmits(['close', 'saved']);

const { state, http, api, fail, t, toast } = useMk();
const today = localDay();
const busy = ref(false);
const accounts = ref([]);
const ads = ref([]);
const form = reactive({ name: '', objective: 'traffic', integration: null, content: null, daily: '', start: '', end: '', countries: '', ageMin: '18', ageMax: '55' });

const dailyMinor = computed(() => mkParseMinor(form.daily));
const days = computed(() => (form.start && form.end ? Math.max(0, Math.round((parseDay(form.end) - parseDay(form.start)) / 86400000)) : 0));
const codes = computed(() => form.countries.split(/[\s,،]+/).map((c) => c.trim().toUpperCase()).filter((c) => /^[A-Z]{2}$/.test(c)));
const age = (v) => {
    const clean = mkNormalizeDigits(v);

    return /^\d+$/.test(clean) ? Number(clean) : null;
};
const ageOk = computed(() => {
    const a = age(form.ageMin);
    const b = age(form.ageMax);

    return (a === null || (a >= 13 && a <= 65)) && (b === null || (b >= 13 && b <= 65)) && (a === null || b === null || a <= b);
});
const valid = computed(() => form.name.trim().length >= 2 && dailyMinor.value !== null && dailyMinor.value > 0 && days.value > 0 && ageOk.value);

function datePart(iso, fallback) {
    return iso ? localDay(iso) : fallback;
}

async function loadChoices() {
    try {
        const [i, c] = await Promise.all([
            http.get(api(`/projects/${props.projectId}/integrations`)).catch(() => null),
            http.get(api(`/projects/${props.projectId}/content`), { params: { status: 'APPROVED,SCHEDULED,PUBLISHED', kind: 'ad' } }).catch(() => null),
        ]);
        accounts.value = (i?.data?.data?.integrations ?? []).filter((x) => x.status === 'CONNECTED');
        ads.value = c?.data?.data ?? [];
        if (! form.integration) form.integration = accounts.value[0]?.id ?? null;
    } catch {
        /* the form still works without the choices */
    }
}

watch(() => props.show, (open) => {
    if (! open) return;
    const e = props.existing;
    Object.assign(form, {
        name: e?.name ?? '',
        objective: e?.objective ?? 'traffic',
        integration: e?.integration_id ?? null,
        content: e?.content_id ?? null,
        daily: e ? mkMinorToInput(e.daily_budget_minor) : '',
        start: datePart(e?.start_at, addDays(today, 1)),
        end: datePart(e?.end_at, addDays(today, 8)),
        countries: (e?.targeting?.countries ?? [state.status?.country?.code].filter(Boolean)).join(', '),
        ageMin: String(e?.targeting?.age_min ?? 18),
        ageMax: String(e?.targeting?.age_max ?? 55),
    });
    loadChoices();
});

async function save() {
    // Today starts in half an hour (the platform refuses a start in the past); other days at 09:00, ends at 23:00.
    const startTime = form.start === today ? new Date(Date.now() + 30 * 60000) : null;
    const body = {
        name: form.name.trim(),
        objective: form.objective,
        integration: form.integration,
        content: form.content,
        daily_budget_minor: dailyMinor.value,
        start_at: startTime ? startTime.toISOString() : localIso(form.start, '09:00'),
        end_at: localIso(form.end, '23:00'),
        targeting: {
            countries: codes.value,
            ...(age(form.ageMin) !== null ? { age_min: age(form.ageMin) } : {}),
            ...(age(form.ageMax) !== null ? { age_max: age(form.ageMax) } : {}),
        },
    };
    if (props.existing) body.version = props.existing.version;
    busy.value = true;
    try {
        const { data } = props.existing
            ? await http.patch(api(`/campaigns/${props.existing.id}`), body)
            : await http.post(api(`/projects/${props.projectId}/campaigns`), body);
        toast.showSuccess(data.message || t('marketing.saved'));
        emit('saved', data.data);
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}
</script>
