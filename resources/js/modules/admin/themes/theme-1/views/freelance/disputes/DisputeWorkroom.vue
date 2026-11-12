<template>
    <div class="border rounded p-3 mt-3">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <h6 class="fs-13 fw-semibold mb-0 me-auto">{{ t('freelance.disputes.workroom.title') }}</h6>
            <button type="button" class="btn btn-sm btn-outline-primary" :disabled="loading" @click="toggle">
                <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span>{{ room ? t('freelance.disputes.workroom.hide') : t('freelance.disputes.workroom.show') }}
            </button>
        </div>
        <div v-if="!room" class="text-muted fs-11 mt-1">{{ t('freelance.disputes.workroom.hint') }}</div>

        <div v-if="room" class="mt-2">
            <ul class="nav nav-pills nav-style-3 mb-2 fs-12">
                <li v-for="tab in tabs" :key="tab" class="nav-item">
                    <button type="button" class="nav-link py-1" :class="{ active: active === tab }" @click="active = tab">{{ t(`freelance.disputes.workroom.${tab}`) }} ({{ count(tab) }})</button>
                </li>
            </ul>

            <div class="dispute-room">
                <div v-if="!count(active)" class="text-muted fs-12">{{ t('freelance.disputes.workroom.empty') }}</div>

                <template v-if="active === 'tasks'">
                    <div v-for="x in room.tasks" :key="x.uuid" class="fs-12 py-1 border-bottom">
                        <span class="badge me-1" :class="taskBadge(x.status)">{{ t(`freelance.disputes.workroom.task_status.${x.status}`) }}</span>
                        <span class="fw-semibold">{{ x.title }}</span>
                        <span class="text-muted fs-11"> · {{ t('freelance.disputes.workroom.assignee') }}: {{ side(x.assignee_side) }}<template v-if="x.due_on"> · {{ t('freelance.disputes.workroom.due', { date: x.due_on }) }}</template><template v-if="x.milestone"> · {{ x.milestone.title }}</template></span>
                        <div v-if="x.description" class="text-muted ps-2" style="white-space: pre-line">{{ x.description }}</div>
                    </div>
                </template>

                <template v-if="active === 'files'">
                    <div v-for="f in room.files" :key="f.uuid" class="fs-12 py-1 border-bottom">
                        <span class="fw-semibold">{{ f.title }}</span>
                        <span class="text-muted fs-11"> · {{ side(f.created_by_side) }}<template v-if="f.milestone"> · {{ f.milestone.title }}</template></span>
                        <div v-if="f.description" class="text-muted ps-2">{{ f.description }}</div>
                        <div v-for="v in f.versions" :key="v.number" class="ps-2">
                            <a href="#" @click.prevent="download(v)"><i class="ri-attachment-2 me-1"></i>{{ t('freelance.disputes.workroom.version', { n: v.number }) }} · {{ v.name }}</a>
                            <span class="text-muted fs-11"> · {{ side(v.by_side) }} · {{ formatCatalogDate(v.created_at, locale) }}<template v-if="v.note"> · {{ v.note }}</template></span>
                        </div>
                        <div v-for="m in notesOn(f.uuid)" :key="m.uuid" class="ps-3 text-muted">
                            <i class="ri-chat-1-line me-1"></i><span :class="sideClass(m.author_side)">{{ side(m.author_side) }}</span>
                            <template v-if="m.version"> (v{{ m.version }})</template>: {{ m.body }}
                        </div>
                    </div>
                    <div v-for="m in deliveryNotes" :key="m.uuid" class="fs-12 py-1 border-bottom text-muted">
                        <i class="ri-chat-1-line me-1"></i><span :class="sideClass(m.author_side)">{{ side(m.author_side) }}</span>: {{ m.body }}
                    </div>
                </template>

                <template v-if="active === 'timeline'">
                    <div v-for="e in room.timeline" :key="e.key" class="fs-12 py-1 border-bottom">
                        <span class="badge bg-light text-dark me-1">{{ e.kind }}</span>
                        <span v-if="e.actor" class="fw-semibold me-1" :class="sideClass(e.actor)">{{ side(e.actor) }}</span>
                        <span>{{ e.label }}</span>
                        <span v-if="e.by?.name" class="text-muted fs-11"> · {{ e.by.name }}</span>
                        <span class="text-muted fs-11"> · {{ formatCatalogDate(e.at, locale) }}</span>
                    </div>
                </template>

                <template v-if="active === 'decisions'">
                    <div v-for="x in room.decisions" :key="x.uuid" class="fs-12 py-1 border-bottom">
                        <span class="badge me-1" :class="decisionBadge(x.status)">{{ t(`freelance.disputes.workroom.decision_status.${x.status}`) }}</span>
                        <span class="fw-semibold">{{ x.title }}</span>
                        <span class="text-muted fs-11"> · {{ t('freelance.disputes.workroom.proposed_by', { side: side(x.proposed_by_side) }) }} · {{ formatCatalogDate(x.created_at, locale) }}</span>
                        <div v-if="x.body" class="text-muted ps-2" style="white-space: pre-line">{{ x.body }}</div>
                        <div v-if="x.answer_note" class="text-muted ps-2">{{ x.answer_note }}</div>
                        <div v-if="x.superseded_by" class="text-muted ps-2">{{ t('freelance.disputes.workroom.replaced_by', { title: x.superseded_by.title }) }}</div>
                    </div>
                </template>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import { formatCatalogDate } from '../../../../../../../utils/catalog';

// FS-150f: the shared Workroom of the disputed project, read only. Opening it and every file taken are on the audit log.
const props = defineProps({ dispute: { type: String, required: true } });
const { t, locale } = useI18n();
const { showError } = useToast();

const tabs = ['tasks', 'files', 'decisions', 'timeline'];
const room = ref(null);
const active = ref('tasks');
const loading = ref(false);

watch(() => props.dispute, () => (room.value = null));

const count = (tab) => room.value?.[tab]?.length ?? 0;
const side = (s) => (['client', 'freelancer'].includes(s) ? t(`freelance.disputes.side.${s}`) : '—');
const sideClass = (s) => (s === 'client' ? 'text-primary' : 'text-success');
const taskBadge = (s) => ({ todo: 'bg-light text-dark', doing: 'bg-info-transparent', done: 'bg-success-transparent', blocked: 'bg-danger-transparent' }[s] ?? 'bg-light text-dark');
const decisionBadge = (s) => ({ proposed: 'bg-warning-transparent', agreed: 'bg-success-transparent', declined: 'bg-danger-transparent', superseded: 'bg-secondary-transparent' }[s] ?? 'bg-light text-dark');
const notesOn = (uuid) => (room.value?.comments ?? []).filter((m) => m.subject.type === 'file' && m.subject.uuid === uuid);
const deliveryNotes = computed(() => (room.value?.comments ?? []).filter((m) => m.subject.type === 'delivery'));

async function toggle() {
    if (room.value) {
        room.value = null;
        return;
    }
    loading.value = true;
    try {
        const base = `/api/admin/v1/freelance-disputes/${props.dispute}/workroom`;
        const [{ data }, { data: line }] = await Promise.all([adminAxios.get(base), adminAxios.get(`${base}/timeline`)]);
        room.value = { ...data.data, comments: data.data.comments ?? [], timeline: line.data.entries ?? [] };
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function download(version) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/${version.path}`, { responseType: 'blob' });
        window.open(URL.createObjectURL(data), '_blank', 'noopener');
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    }
}
</script>

<style scoped>
.dispute-room {
    max-height: 420px;
    overflow-y: auto;
}
</style>
