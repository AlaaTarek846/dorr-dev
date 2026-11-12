<template>
    <div>
        <MkHeader :title="item?.title || t(contentKindKey('post'))" :subtitle="project?.name || ''" :back="backTo" />

        <MkUnavailable v-if="statusReady && !enabled" />
        <div v-else-if="!item" class="d-grid gap-3">
            <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 100px"></div>
        </div>

        <div v-else class="row g-3">
            <div class="col-xl-8">
                <!-- Header -->
                <div class="card custom-card mk-card mb-3">
                    <div class="card-body">
                        <div class="d-flex align-items-start gap-3 flex-wrap">
                            <span class="mk-icon" :class="item.kind === 'ad' ? 'bg-pink-transparent text-pink' : 'bg-info-transparent text-info'">
                                <i :class="isTask ? 'ri-task-line' : item.kind === 'ad' ? 'ri-megaphone-line' : 'ri-image-line'"></i>
                            </span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold fs-15 text-break">{{ item.title }}</div>
                                <div class="text-muted fs-13">{{ meta }}</div>
                            </div>
                            <span class="badge mk-pill" :class="`bg-${status.tone}-transparent`">{{ t(status.key) }}</span>
                        </div>
                        <div v-if="item.planned_at" class="mt-3 fw-semibold fs-13"><i class="ri-time-line me-1 text-muted"></i>{{ dateTime(item.planned_at) }}</div>
                        <p v-if="item.objective" class="text-muted fs-13 mb-0 mt-2">{{ item.objective }}</p>
                        <p v-if="item.cost_estimate_minor != null" class="text-muted fs-13 mb-0 mt-1">
                            {{ t('marketing.cost_estimate', { amount: mkMoney(item.cost_estimate_minor, currency) }) }}
                        </p>
                    </div>
                </div>

                <div v-if="item.status === 'REJECTED' && item.review_note" class="alert alert-warning fs-13">
                    {{ t('marketing.returned_note', { note: item.review_note }) }}
                </div>
                <div v-if="item.status === 'PUBLISHED' && item.published_url" class="card custom-card mk-card mb-3">
                    <div class="card-body py-2 d-flex align-items-center gap-2">
                        <i class="ri-link text-success"></i>
                        <a :href="item.published_url" target="_blank" rel="noopener noreferrer nofollow" class="mk-ltr text-truncate">{{ item.published_url }}</a>
                    </div>
                </div>

                <!-- Variants (A/B) -->
                <template v-if="!isTask && item.variants?.length">
                    <div class="d-flex align-items-center gap-2 mk-section-title">
                        <span class="flex-grow-1">{{ t('marketing.variants') }}</span>
                        <button type="button" class="btn btn-light btn-sm" @click="copy">
                            <i class="ri-file-copy-line me-1"></i>{{ t('marketing.copy') }}
                        </button>
                    </div>
                    <div v-if="item.variants.length > 1" class="d-flex flex-wrap gap-2 mb-2">
                        <button
                            v-for="v in item.variants"
                            :key="v.key"
                            type="button"
                            class="btn btn-sm rounded-pill"
                            :class="v.key === shown.key ? 'btn-primary' : 'btn-outline-light text-default'"
                            @click="variantKey = v.key"
                        >
                            {{ t('marketing.variant', { key: v.key }) }}<i v-if="v.key === item.selected" class="ri-check-line ms-1"></i>
                        </button>
                    </div>
                    <div class="card custom-card mk-card mb-2">
                        <div class="card-body">
                            <h3 v-if="shown.headline" class="fs-16 fw-bold mb-2">{{ shown.headline }}</h3>
                            <p v-if="shown.body" class="mk-prewrap mb-0">{{ shown.body }}</p>
                            <span v-if="shown.cta" class="btn btn-primary btn-sm rounded-pill mt-3 pe-none">{{ shown.cta }}</span>
                            <div v-if="shown.hashtags?.length" class="d-flex flex-wrap gap-1 mt-3">
                                <span v-for="h in shown.hashtags" :key="h" class="badge bg-info-transparent">{{ h }}</span>
                            </div>
                            <div v-if="shown.visual_brief" class="mt-3 p-3 rounded bg-light d-flex gap-2">
                                <i class="ri-image-2-line text-muted"></i>
                                <div>
                                    <div class="text-muted fs-12 fw-semibold">{{ t('marketing.visual_brief') }}</div>
                                    <div class="fs-13">{{ shown.visual_brief }}</div>
                                </div>
                            </div>
                            <div v-if="shownBlocked" class="text-danger fs-12 fw-semibold mt-2">{{ t('marketing.variant_blocked') }}</div>
                        </div>
                    </div>
                    <button
                        v-if="canEdit && item.variants.length > 1 && shown.key !== item.selected && item.status !== 'PUBLISHED'"
                        type="button"
                        class="btn btn-light btn-sm"
                        :disabled="busy !== null"
                        @click="selectVariant"
                    >
                        <span v-if="busy === 'select'" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.use_variant', { key: shown.key }) }}
                    </button>
                </template>

                <template v-if="!isTask">
                    <h2 class="mk-section-title">{{ t('marketing.claims') }}</h2>
                    <ClaimsBlock :claims="item.claims || []" />
                </template>
            </div>

            <!-- Steps -->
            <div class="col-xl-4">
                <div class="card custom-card mk-card">
                    <div class="card-body d-grid gap-2">
                        <template v-if="isTask">
                            <button v-if="canEdit" type="button" class="btn" :class="item.status === 'DONE' ? 'btn-light' : 'btn-primary'" :disabled="busy !== null" @click="act('done', { done: item.status !== 'DONE' })">
                                <span v-if="busy === 'done'" class="spinner-border spinner-border-sm me-1"></span>
                                {{ item.status === 'DONE' ? t('marketing.task_reopen') : t('marketing.task_done') }}
                            </button>
                        </template>
                        <template v-else-if="['DRAFT', 'REJECTED'].includes(item.status) && canEdit">
                            <button type="button" class="btn btn-primary" :disabled="busy !== null || item.has_blocking_issues" @click="act('submit', {}, t('marketing.sent_review'))">
                                <span v-if="busy === 'submit'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-send-plane-line me-1"></i>{{ t('marketing.send_review') }}
                            </button>
                            <div v-if="item.has_blocking_issues" class="text-danger fs-12">{{ t('marketing.fix_blocking') }}</div>
                        </template>
                        <template v-else-if="item.status === 'POLICY_REVIEW' && abilities.approve">
                            <button type="button" class="btn btn-success" :disabled="busy !== null" @click="act('approve', { lock_version: item.lock_version }, t('marketing.approved_toast'))">
                                <span v-if="busy === 'approve'" class="spinner-border spinner-border-sm me-1"></span>
                                <i v-else class="ri-verified-badge-line me-1"></i>{{ t('marketing.approve_content') }}
                            </button>
                            <button type="button" class="btn btn-light" :disabled="busy !== null" @click="openNote('return')">
                                <i class="ri-arrow-go-back-line me-1"></i>{{ t('marketing.return') }}
                            </button>
                        </template>
                        <div v-else-if="item.status === 'POLICY_REVIEW'" class="alert alert-info fs-13 mb-0">{{ t('marketing.waiting_review') }}</div>
                        <template v-else-if="['APPROVED', 'SCHEDULED'].includes(item.status) && canEdit">
                            <button type="button" class="btn btn-success" :disabled="busy !== null" @click="openNote('posted')">
                                <i class="ri-checkbox-circle-line me-1"></i>{{ t('marketing.mark_posted') }}
                            </button>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-light flex-fill" :disabled="busy !== null" @click="openSchedule">
                                    <i class="ri-time-line me-1"></i>{{ item.status === 'SCHEDULED' ? t('marketing.reschedule') : t('marketing.schedule') }}
                                </button>
                                <button type="button" class="btn btn-light flex-fill" :disabled="busy !== null" @click="openNote('not_posted')">
                                    {{ t('marketing.not_posted') }}
                                </button>
                            </div>
                            <div class="text-muted fs-12">{{ t('marketing.post_yourself') }}</div>
                        </template>

                        <template v-if="canEdit && item.status !== 'PUBLISHED'">
                            <div class="d-flex gap-2 mt-2">
                                <button type="button" class="btn btn-outline-light text-default flex-fill" @click="editing = true">
                                    <i class="ri-edit-2-line me-1"></i>{{ t('marketing.edit') }}
                                </button>
                                <button type="button" class="btn btn-outline-danger flex-fill" @click="deleting = true">
                                    <i class="ri-delete-bin-line me-1"></i>{{ t('marketing.delete_item') }}
                                </button>
                            </div>
                            <div v-if="['APPROVED', 'SCHEDULED', 'POLICY_REVIEW'].includes(item.status)" class="text-muted fs-12">{{ t('marketing.edit_resets') }}</div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- Return / posted / not posted -->
        <MkModal :show="noteMode !== null" :title="noteTitle" :busy="busy !== null" @close="noteMode = null">
            <template v-if="noteMode === 'posted'">
                <label class="form-label" for="mk-post-url">{{ t('marketing.post_link') }}</label>
                <input id="mk-post-url" v-model="noteForm.url" type="url" class="form-control mk-ltr" placeholder="https://" maxlength="500" :class="{ 'is-invalid': !urlValid }">
            </template>
            <label class="form-label" :class="{ 'mt-3': noteMode === 'posted' }" for="mk-note">{{ t('marketing.note_optional') }}</label>
            <textarea id="mk-note" v-model="noteForm.note" class="form-control" rows="3" maxlength="500"></textarea>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="noteMode = null">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null || !urlValid" @click="sendNote">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ noteMode === 'return' ? t('marketing.return') : t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <!-- Schedule -->
        <MkModal :show="scheduling" :title="t('marketing.schedule')" :busy="busy !== null" @close="scheduling = false">
            <label class="form-label" for="mk-schedule">{{ t('marketing.when') }}</label>
            <input id="mk-schedule" v-model="scheduleAt" type="datetime-local" class="form-control mk-ltr" :min="minSchedule">
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="scheduling = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null || !scheduleValid" @click="schedule">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <!-- Edit -->
        <MkModal :show="editing" :title="t('marketing.edit')" :busy="busy !== null" wide @close="editing = false">
            <label class="form-label" for="mk-edit-title">{{ t('marketing.item_title') }}</label>
            <input id="mk-edit-title" v-model="edit.title" class="form-control" maxlength="160">
            <label class="form-label mt-3" for="mk-edit-objective">{{ t('marketing.objective') }}</label>
            <input id="mk-edit-objective" v-model="edit.objective" class="form-control" maxlength="300">
            <template v-if="!isTask && edit.variants.length">
                <div v-if="edit.variants.length > 1" class="d-flex flex-wrap gap-2 mt-3">
                    <button
                        v-for="(v, i) in edit.variants"
                        :key="v.key"
                        type="button"
                        class="btn btn-sm rounded-pill"
                        :class="edit.tab === i ? 'btn-primary' : 'btn-outline-light text-default'"
                        @click="edit.tab = i"
                    >{{ t('marketing.variant', { key: v.key }) }}</button>
                </div>
                <label class="form-label mt-3" for="mk-edit-headline">{{ t('marketing.headline') }}</label>
                <input id="mk-edit-headline" v-model="edit.variants[edit.tab].headline" class="form-control" maxlength="120">
                <label class="form-label mt-3" for="mk-edit-body">{{ t('marketing.body') }}</label>
                <textarea id="mk-edit-body" v-model="edit.variants[edit.tab].body" class="form-control" rows="5" maxlength="2200"></textarea>
                <label class="form-label mt-3" for="mk-edit-cta">{{ t('marketing.cta') }}</label>
                <input id="mk-edit-cta" v-model="edit.variants[edit.tab].cta" class="form-control" maxlength="60">
            </template>
            <div v-if="!isTask && ['APPROVED', 'SCHEDULED', 'POLICY_REVIEW'].includes(item?.status)" class="alert alert-warning fs-13 mt-3 mb-0">{{ t('marketing.edit_resets') }}</div>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="editing = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy !== null || edit.title.trim().length < 2" @click="saveEdit">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <MkModal :show="deleting" :title="t('marketing.delete_item')" :busy="busy !== null" @close="deleting = false">
            <p class="mb-0">{{ item?.title }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy !== null" @click="deleting = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="busy !== null" @click="destroy">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.delete_item') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { channelKey, contentKindKey, contentStatus, formatKey, mkMoney, toLocalInput } from './marketingContext';
import useProjectPage from './parts/useProjectPage';
import MkHeader from './parts/MkHeader.vue';
import MkModal from './parts/MkModal.vue';
import MkUnavailable from './parts/MkUnavailable.vue';
import ClaimsBlock from './parts/ClaimsBlock.vue';

/**
 * One post, ad or task (U07): the A/B variants, the claims review and the steps its state allows — send for review,
 * approve this exact wording (owner or manager), return with a note, schedule, then "I posted it". DORR never posts.
 */
const route = useRoute();
const itemId = route.params.itemId;
const {
    projectId, project, abilities, statusReady, enabled, currency, http, api, to, fail, dateTime, t, toast, router, onPush,
} = useProjectPage('content');

const item = ref(null);
const variantKey = ref(null);
const busy = ref(null);
const editing = ref(false);
const deleting = ref(false);
const scheduling = ref(false);
const scheduleAt = ref('');
const noteMode = ref(null);
const noteForm = reactive({ url: '', note: '' });

const isTask = computed(() => item.value?.kind === 'task');
const canEdit = computed(() => abilities.value.edit === true);
const status = computed(() => contentStatus(item.value?.status));
const backTo = computed(() => to(item.value && item.value.kind !== 'task' && ! item.value.planned_at ? 'studio' : 'calendar', { projectId }));
const meta = computed(() => [
    t(contentKindKey(item.value?.kind)),
    t(channelKey(item.value?.channel)),
    item.value?.format ? t(formatKey(item.value.format)) : null,
].filter(Boolean).join(' · '));
const shown = computed(() => {
    const list = item.value?.variants ?? [];

    return list.find((v) => v.key === (variantKey.value ?? item.value?.selected)) ?? list[0] ?? {};
});
const shownBlocked = computed(() => (item.value?.claims ?? []).some((c) => c.variant === shown.value.key && c.severity === 'blocking'));
const urlValid = computed(() => noteMode.value !== 'posted' || ! noteForm.url.trim() || /^https?:\/\//i.test(noteForm.url.trim()));
const noteTitle = computed(() => ({ return: t('marketing.return'), posted: t('marketing.mark_posted'), not_posted: t('marketing.not_posted') }[noteMode.value] ?? ''));
const minSchedule = computed(() => toLocalInput(new Date(Date.now() + 5 * 60000).toISOString()));
const scheduleValid = computed(() => !! scheduleAt.value && new Date(scheduleAt.value).getTime() > Date.now() + 60000);

async function load() {
    if (! enabled.value) return;
    try {
        const { data } = await http.get(api(`/content/${itemId}`));
        item.value = data.data;
    } catch (error) {
        fail(error);
    }
}

async function run(key, request, okMessage = null) {
    busy.value = key;
    try {
        const { data } = await request();
        if (data?.data?.id) item.value = data.data;
        toast.showSuccess(okMessage || data?.message || t('marketing.saved'));

        return true;
    } catch (error) {
        fail(error);
        load();

        return false;
    } finally {
        busy.value = null;
    }
}

function act(action, body = {}, okMessage = null) {
    return run(action, () => http.post(api(`/content/${itemId}/${action}`), body), okMessage);
}

function selectVariant() {
    return run('select', () => http.patch(api(`/content/${itemId}`), { lock_version: item.value.lock_version, selected: shown.value.key }));
}

function openNote(mode) {
    noteForm.url = '';
    noteForm.note = '';
    noteMode.value = mode;
}

async function sendNote() {
    const note = noteForm.note.trim() || undefined;
    const ok = noteMode.value === 'return'
        ? await act('reject', { note })
        : await act('outcome', { published: noteMode.value === 'posted', url: noteForm.url.trim() || undefined, note });
    if (ok) noteMode.value = null;
}

function openSchedule() {
    const current = item.value?.planned_at && new Date(item.value.planned_at).getTime() > Date.now() + 60000 ? item.value.planned_at : null;
    scheduleAt.value = current ? toLocalInput(current) : toLocalInput(new Date(Date.now() + 3600000).toISOString());
    scheduling.value = true;
}

async function schedule() {
    if (await act('schedule', { planned_at: new Date(scheduleAt.value).toISOString() })) scheduling.value = false;
}

async function copy() {
    const v = shown.value;
    const text = [v.headline, v.body, v.cta, (v.hashtags ?? []).join(' ')].filter((s) => s && String(s).trim()).join('\n\n');
    try {
        await navigator.clipboard.writeText(text);
        toast.showSuccess(t('marketing.copied'));
    } catch {
        toast.showError(t('marketing.error_generic'));
    }
}

// ---------------------------------------------------------------- edit
const edit = reactive({ title: '', objective: '', variants: [], tab: 0 });

watch(editing, (open) => {
    if (! open || ! item.value) return;
    edit.title = item.value.title ?? '';
    edit.objective = item.value.objective ?? '';
    edit.variants = (item.value.variants ?? []).map((v) => ({ ...v, headline: v.headline ?? '', body: v.body ?? '', cta: v.cta ?? '' }));
    edit.tab = Math.max(0, edit.variants.findIndex((v) => v.key === item.value.selected));
});

async function saveEdit() {
    const body = { lock_version: item.value.lock_version, title: edit.title.trim(), objective: edit.objective.trim() || null };
    if (! isTask.value && edit.variants.length) {
        body.variants = edit.variants.map((v) => ({
            headline: v.headline.trim(),
            body: v.body.trim(),
            cta: v.cta.trim(),
            hashtags: v.hashtags ?? [],
            ...(v.visual_brief ? { visual_brief: v.visual_brief } : {}),
        }));
        if (item.value.selected) body.selected = item.value.selected;
    }
    if (await run('edit', () => http.patch(api(`/content/${itemId}`), body))) editing.value = false;
}

async function destroy() {
    busy.value = 'delete';
    try {
        await http.delete(api(`/content/${itemId}`));
        deleting.value = false;
        toast.showSuccess(t('marketing.deleted_item'));
        router.push(backTo.value);
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
