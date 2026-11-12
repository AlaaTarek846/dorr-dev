<template>
    <div>
        <MkHeader :title="t('marketing.discovery')" :subtitle="project?.name || ''" :back="to('project', { projectId })">
            <button v-if="canEdit" type="button" class="btn btn-primary" :disabled="!dirty || saving" @click="save">
                <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
            </button>
        </MkHeader>

        <div v-if="!discovery" class="d-grid gap-3">
            <div v-for="i in 4" :key="i" class="mk-skeleton" style="height: 110px"></div>
        </div>

        <div v-else class="row g-3 justify-content-center">
            <div class="col-xl-8">
                <div class="card custom-card mk-card mb-3">
                    <div class="card-body">
                        <div class="fw-semibold mb-2">{{ t('marketing.discovery_sub', { answered: discovery.answered, total: discovery.total }) }}</div>
                        <MkBar
                            :value="discovery.total ? (discovery.answered / discovery.total) * 100 : 0"
                            :color="discovery.complete ? 'var(--success-color, #26bf94)' : ''"
                        />
                        <div class="text-muted fs-13 mt-2">{{ discovery.complete ? t('marketing.discovery_done') : t('marketing.discovery_missing') }}</div>
                    </div>
                </div>

                <div class="d-grid gap-3">
                    <div v-for="q in discovery.questions" :key="q.key" class="card custom-card mk-card mb-0" :class="{ 'border border-danger': q.required && !q.answered && !hasDraft(q) }">
                        <div class="card-body">
                            <div class="d-flex align-items-start gap-2 mb-2">
                                <label :for="`mk-q-${q.key}`" class="fw-semibold flex-grow-1 mb-0 lh-base">{{ q.text }}</label>
                                <span v-if="q.required && !q.answered" class="badge bg-danger-transparent">{{ t('marketing.required') }}</span>
                                <span v-else-if="q.origin === 'ai'" class="badge bg-primary-transparent"><i class="ri-sparkling-2-line me-1"></i>{{ t('marketing.from_ai') }}</span>
                            </div>

                            <div v-if="q.type === 'money' || q.type === 'number'" class="input-group" :class="{ 'has-validation': invalid[q.key] }">
                                <input
                                    :id="`mk-q-${q.key}`"
                                    :value="textFor(q)"
                                    class="form-control mk-ltr"
                                    :class="{ 'is-invalid': invalid[q.key] }"
                                    :inputmode="q.type === 'money' ? 'decimal' : 'numeric'"
                                    :placeholder="q.type === 'money' ? '0.00' : '1'"
                                    :disabled="!canEdit"
                                    maxlength="16"
                                    @input="onText(q, $event.target.value)"
                                >
                                <span v-if="q.type === 'money' && project?.currency" class="input-group-text">{{ project.currency }}</span>
                            </div>
                            <textarea
                                v-else
                                :id="`mk-q-${q.key}`"
                                :value="textFor(q)"
                                class="form-control"
                                :class="{ 'is-invalid': invalid[q.key] }"
                                :rows="q.type === 'list' ? 3 : 2"
                                :placeholder="q.type === 'list' ? t('marketing.one_per_line') : ''"
                                :disabled="!canEdit"
                                @input="onText(q, $event.target.value)"
                            ></textarea>
                            <div v-if="invalid[q.key]" class="text-danger fs-12 mt-1">{{ t('marketing.answer_invalid') }}</div>
                            <div v-if="cleared[q.key]" class="text-muted fs-12 mt-1"><i class="ri-time-line me-1"></i>{{ t('marketing.dont_know_set') }}</div>
                            <button
                                v-else-if="canEdit && (q.answered || hasDraft(q))"
                                type="button"
                                class="btn btn-link btn-sm px-0 mt-1"
                                @click="dontKnow(q)"
                            >{{ t('marketing.dont_know') }}</button>
                        </div>
                    </div>
                </div>

                <div v-if="canEdit" class="mk-actions mt-3">
                    <button type="button" class="btn btn-light" :disabled="suggesting" @click="suggest">
                        <span v-if="suggesting" class="spinner-border spinner-border-sm me-1"></span>
                        <i v-else class="ri-sparkling-2-line me-1"></i>{{ t('marketing.suggest') }}
                    </button>
                    <button type="button" class="btn btn-primary" :disabled="!dirty || saving" @click="save">
                        <span v-if="saving" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { useRoute } from 'vue-router';
import { mkMinorToInput, mkNormalizeDigits, mkParseMinor, useMk } from './marketingContext';
import MkHeader from './parts/MkHeader.vue';
import MkBar from './parts/MkBar.vue';

/**
 * Discovery: the missing questions stand out; the budget is never guessed — "I don't know yet" clears an answer and it
 * is asked again. Lists are one item per line. Only changed answers are sent.
 */
const route = useRoute();
const projectId = route.params.projectId;
const { http, api, to, fail, t, toast } = useMk();

const project = ref(null);
const discovery = ref(null);
const drafts = reactive({});
const cleared = reactive({});
const invalid = reactive({});
const saving = ref(false);
const suggesting = ref(false);

const canEdit = computed(() => project.value?.abilities?.edit === true);
const dirty = computed(() => Object.keys(drafts).length > 0 || Object.keys(cleared).length > 0);

function answerText(q) {
    const v = q.value;
    if (v === null || v === undefined) return '';
    if (Array.isArray(v)) return v.join('\n');
    if (q.type === 'money') return mkMinorToInput(v);

    return String(v);
}

function textFor(q) {
    if (cleared[q.key]) return '';

    return q.key in drafts ? drafts[q.key] : answerText(q);
}

function hasDraft(q) {
    return (drafts[q.key] ?? '').trim() !== '';
}

function onText(q, value) {
    drafts[q.key] = value;
    delete cleared[q.key];
    delete invalid[q.key];
}

function dontKnow(q) {
    cleared[q.key] = true;
    delete drafts[q.key];
    delete invalid[q.key];
}

function body() {
    const answers = {};
    const clear = [];
    Object.keys(invalid).forEach((k) => delete invalid[k]);

    for (const q of discovery.value?.questions ?? []) {
        if (cleared[q.key]) {
            clear.push(q.key);
            continue;
        }
        if (! (q.key in drafts)) continue;
        const text = drafts[q.key];

        if (q.type === 'list') {
            const items = [...new Set(text.split('\n').map((s) => s.trim()).filter(Boolean))].slice(0, 20);
            if (items.length) answers[q.key] = items; else clear.push(q.key);
        } else if (q.type === 'money') {
            if (! text.trim()) { clear.push(q.key); continue; }
            const minor = mkParseMinor(text);
            if (minor === null) invalid[q.key] = true; else answers[q.key] = minor;
        } else if (q.type === 'number') {
            if (! text.trim()) { clear.push(q.key); continue; }
            const clean = mkNormalizeDigits(text);
            const n = /^\d+$/.test(clean) ? Number(clean) : NaN;
            if (! Number.isSafeInteger(n) || n < 1) invalid[q.key] = true; else answers[q.key] = n;
        } else if (text.trim()) {
            answers[q.key] = text.trim();
        } else {
            clear.push(q.key);
        }
    }

    return Object.keys(invalid).length ? null : { answers, clear };
}

function resetDrafts() {
    Object.keys(drafts).forEach((k) => delete drafts[k]);
    Object.keys(cleared).forEach((k) => delete cleared[k]);
}

async function save() {
    const payload = body();
    if (! payload) {
        return;
    }
    saving.value = true;
    try {
        const { data } = await http.put(api(`/projects/${projectId}/discovery`), payload);
        discovery.value = data.data;
        resetDrafts();
        toast.showSuccess(t('marketing.saved'));
    } catch (error) {
        fail(error);
    } finally {
        saving.value = false;
    }
}

async function suggest() {
    suggesting.value = true;
    try {
        const { data } = await http.post(api(`/projects/${projectId}/discovery/suggest`));
        discovery.value = data.data;
        toast.showSuccess(data.message);
    } catch (error) {
        fail(error);
    } finally {
        suggesting.value = false;
    }
}

onMounted(async () => {
    try {
        const [p, d] = await Promise.all([
            http.get(api(`/projects/${projectId}`)),
            http.get(api(`/projects/${projectId}/discovery`)),
        ]);
        project.value = p.data.data;
        discovery.value = d.data.data;
    } catch (error) {
        fail(error);
    }
});
</script>
