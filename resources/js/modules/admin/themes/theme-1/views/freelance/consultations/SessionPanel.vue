<template>
    <!-- The video session (FL-L16): the room, who came and left, the recordings (the evidence on a complaint) and who has taken them. -->
    <div class="border rounded-3 mb-3 session-panel" :class="{ 'border-warning session-evidence': evidence }">
        <button type="button" class="session-head w-100 d-flex align-items-center gap-2 px-3 py-2 text-start" @click="toggle">
            <i class="ri-vidicon-line fs-16" :class="evidence ? 'text-warning' : 'text-primary'"></i>
            <span class="fw-semibold fs-13">{{ t('freelance.consultations.room.title') }}</span>
            <span v-if="evidence" class="badge bg-warning-transparent">{{ t('freelance.consultations.room.evidence') }}</span>
            <span v-if="data" class="badge ms-1" :class="stateBadge">{{ stateText }}</span>
            <i class="ms-auto" :class="expanded ? 'ri-arrow-up-s-line' : 'ri-arrow-down-s-line'"></i>
        </button>

        <div v-if="expanded" class="px-3 pb-3 pt-1">
            <div v-if="evidence" class="text-muted fs-12 mb-2">{{ t('freelance.consultations.room.evidence_hint') }}</div>

            <div v-if="loading && !data" class="text-center text-muted py-4"><span class="spinner-border spinner-border-sm me-2"></span>{{ t('freelance.consultations.room.loading') }}</div>
            <div v-else-if="failed && !data" class="text-center py-3">
                <div class="text-muted fs-13 mb-2">{{ t('toast.error') }}</div>
                <button type="button" class="btn btn-sm btn-light" @click="load"><i class="ri-refresh-line me-1"></i>{{ t('freelance.consultations.room.reload') }}</button>
            </div>

            <template v-if="data">
                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                    <button type="button" class="btn btn-sm btn-light ms-auto" :disabled="loading" @click="load">
                        <span v-if="loading" class="spinner-border spinner-border-sm me-1"></span><i v-else class="ri-refresh-line me-1"></i>{{ t('freelance.consultations.room.reload') }}
                    </button>
                    <button v-if="canUpdate && data.can?.close" type="button" class="btn btn-sm btn-danger" :disabled="acting" @click="act('close')">
                        <i class="ri-door-closed-line me-1"></i>{{ t('freelance.consultations.room.close') }}
                    </button>
                    <button v-if="canUpdate && data.can?.reopen" type="button" class="btn btn-sm btn-success" :disabled="acting" @click="act('reopen')">
                        <i class="ri-door-open-line me-1"></i>{{ t('freelance.consultations.room.reopen') }}
                    </button>
                </div>

                <div v-if="!data.recording_available" class="alert alert-danger fs-13 py-2 mb-2">
                    <i class="ri-error-warning-line me-1"></i>{{ t('freelance.consultations.room.recording_off') }}
                </div>
                <div v-if="!data.available" class="alert alert-warning fs-13 py-2 mb-2">
                    <i class="ri-error-warning-line me-1"></i>{{ t('freelance.consultations.room.livekit_off') }}
                </div>

                <!-- The room and the consent -->
                <ul class="list-group mb-3 fs-13">
                    <li class="list-group-item d-flex justify-content-between flex-wrap gap-2">
                        <span>{{ t('freelance.consultations.room.state') }}</span><span class="fw-semibold">{{ stateText }}<span v-if="data.closed" class="text-muted fw-normal ms-1">· {{ when(data.closed.at) }}</span></span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between flex-wrap gap-2">
                        <span>{{ t('freelance.consultations.room.window') }}</span><span>{{ when(data.opens_at) }} – {{ when(data.ends_at) }}</span>
                    </li>
                    <li class="list-group-item d-flex justify-content-between flex-wrap gap-2">
                        <span>{{ t('freelance.consultations.room.in_room') }}</span>
                        <span>
                            <span v-for="s in SIDES" :key="s" class="badge ms-1" :class="data.in_room?.[s] ? 'bg-success-transparent' : 'bg-light text-muted'">{{ t(`freelance.consultations.room.side.${s}`) }}</span>
                        </span>
                    </li>
                    <li v-for="s in SIDES" :key="`c-${s}`" class="list-group-item d-flex justify-content-between flex-wrap gap-2">
                        <span>{{ t('freelance.consultations.room.consent', { side: t(`freelance.consultations.room.side.${s}`) }) }}</span>
                        <span v-if="data.consent?.[s]"><i class="ri-checkbox-circle-line text-success me-1"></i>{{ when(data.consent[s]) }}</span>
                        <span v-else class="text-danger">{{ t('freelance.consultations.room.no_consent') }}</span>
                    </li>
                </ul>

                <!-- Who came and left -->
                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.room.attendance') }}</h6>
                <div v-if="freelancerNeverCame" class="alert alert-danger fs-13 py-2 mb-2"><i class="ri-user-unfollow-line me-1"></i>{{ t('freelance.consultations.room.freelancer_never_came') }}</div>
                <div v-if="!attendance.length" class="text-muted fs-13 mb-3">{{ t('freelance.consultations.room.no_attendance') }}</div>
                <ul v-else class="list-unstyled attendance fs-13 mb-3">
                    <li v-for="(a, i) in attendance" :key="i" class="d-flex align-items-center flex-wrap gap-2">
                        <span class="badge" :class="a.side === 'freelancer' ? 'bg-primary-transparent' : 'bg-info-transparent'">{{ t(`freelance.consultations.room.side.${a.side}`) }}</span>
                        <span><i class="ri-login-box-line text-success me-1"></i>{{ when(a.joined_at) }}</span>
                        <span v-if="a.left_at"><i class="ri-logout-box-line text-secondary me-1"></i>{{ when(a.left_at) }}</span>
                        <span v-else class="text-success fs-12">{{ t('freelance.consultations.room.still_in') }}</span>
                        <span v-if="a.left_at" class="text-muted fs-11">({{ span(a.joined_at, a.left_at) }})</span>
                    </li>
                </ul>

                <!-- The recordings -->
                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.room.recordings') }}</h6>
                <div v-if="!data.recordings?.length" class="text-muted fs-13 mb-2">{{ t('freelance.consultations.room.no_recordings') }}</div>
                <div v-for="r in data.recordings" :key="r.uuid" class="border rounded-3 p-2 mb-2">
                    <div class="d-flex align-items-center flex-wrap gap-2 fs-13">
                        <span class="fw-semibold">{{ t('freelance.consultations.room.part_n', { n: r.part }) }}</span>
                        <span class="badge" :class="recBadge(r.status)">{{ t(`freelance.consultations.room.rec_status.${r.status}`) }}</span>
                        <span class="text-muted fs-12">{{ duration(r.duration_seconds) }} · {{ size(r.size_bytes) }}</span>
                        <span class="text-muted fs-12">{{ when(r.started_at) }} – {{ when(r.stopped_at) }}</span>
                        <span class="ms-auto d-flex gap-1">
                            <button v-if="r.watch_url" type="button" class="btn btn-sm btn-primary" @click="togglePlay(r)">
                                <i :class="playing[r.uuid] ? 'ri-eye-off-line' : 'ri-play-circle-line'" class="me-1"></i>{{ playing[r.uuid] ? t('freelance.consultations.room.hide') : t('freelance.consultations.room.watch') }}
                            </button>
                            <a v-if="r.download_url" :href="r.download_url" class="btn btn-sm btn-light" target="_blank" rel="noopener"><i class="ri-download-2-line me-1"></i>{{ t('freelance.consultations.room.download') }}</a>
                        </span>
                    </div>
                    <!-- The link is only loaded when the admin opens the part: every use is written down. -->
                    <div v-if="playing[r.uuid]" class="mt-2">
                        <video class="w-100 rounded-3 bg-dark" controls autoplay preload="auto" :src="r.watch_url" @error="expired[r.uuid] = true"></video>
                        <div v-if="expired[r.uuid]" class="text-danger fs-12 mt-1">
                            {{ t('freelance.consultations.room.player_error') }}
                            <button type="button" class="btn btn-link btn-sm p-0 align-baseline" @click="load">{{ t('freelance.consultations.room.reload') }}</button>
                        </div>
                    </div>
                </div>
                <div class="text-muted fs-12 mb-3"><i class="ri-shield-check-line me-1"></i>{{ t('freelance.consultations.room.logged_hint') }}</div>

                <!-- When they go -->
                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.room.deletion') }}</h6>
                <div class="fs-13 mb-3">
                    <span v-if="data.recordings_deleted_at" class="text-danger"><i class="ri-delete-bin-line me-1"></i>{{ t('freelance.consultations.room.deleted_at', { at: when(data.recordings_deleted_at) }) }}</span>
                    <span v-else-if="data.recordings_held" class="text-warning"><i class="ri-lock-line me-1"></i>{{ t('freelance.consultations.room.held') }}</span>
                    <span v-else-if="data.recordings_delete_at"><i class="ri-time-line me-1"></i>{{ t('freelance.consultations.room.delete_at', { at: when(data.recordings_delete_at) }) }}</span>
                    <span v-else class="text-muted">{{ t('freelance.consultations.room.no_delete_date') }}</span>
                </div>

                <!-- Who has taken them -->
                <h6 class="fw-semibold fs-13 mb-2">{{ t('freelance.consultations.room.views') }}</h6>
                <div v-if="!data.views?.length" class="text-muted fs-13">{{ t('freelance.consultations.room.no_views') }}</div>
                <div v-else class="table-responsive views">
                    <table class="table table-sm table-striped mb-0 fs-12 align-middle">
                        <thead>
                            <tr>
                                <th>{{ t('freelance.consultations.room.part') }}</th>
                                <th>{{ t('freelance.consultations.room.viewer') }}</th>
                                <th>{{ t('freelance.consultations.room.action') }}</th>
                                <th>{{ t('freelance.consultations.room.at') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(v, i) in data.views" :key="i">
                                <td>{{ v.part }}</td>
                                <td>{{ t(`freelance.consultations.room.side.${v.viewer}`) }} <span class="text-muted">#{{ v.viewer_id }}</span></td>
                                <td>{{ t(`freelance.consultations.room.view_action.${v.action}`) }}</td>
                                <td>{{ whenDb(v.at) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import useToast, { extractApiErrorMessage, extractApiMessage } from '../../../../../../../composables/useToast';

const props = defineProps({
    uuid: { type: String, required: true },
    /** A complaint: the recordings are the evidence for the decision — open and marked at once. */
    evidence: { type: Boolean, default: false },
    canUpdate: { type: Boolean, default: false },
});

const { t, locale } = useI18n();
const { showSuccess, showError } = useToast();
const SIDES = ['client', 'freelancer'];
const base = '/api/admin/v1/freelance-consultations';

const expanded = ref(props.evidence);
const data = ref(null);
const loading = ref(false);
const failed = ref(false);
const acting = ref(false);
const playing = reactive({});
const expired = reactive({});

const when = (iso) => (iso ? new Date(iso).toLocaleString(locale.value === 'ar' ? 'ar-EG' : 'en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '—');
// The views log comes straight from the table ("Y-m-d H:i:s", the app's UTC clock, no offset).
const whenDb = (s) => when(s && /^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/.test(s) ? `${s.replace(' ', 'T')}Z` : s);
const duration = (s) => (s == null ? '—' : `${Math.floor(s / 60)}:${String(Math.round(s % 60)).padStart(2, '0')}`);
const size = (b) => (b == null ? '—' : b >= 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`);
const span = (from, to) => duration(Math.max(0, (new Date(to) - new Date(from)) / 1000));
const recBadge = (s) => ({ recording: 'bg-danger-transparent', processing: 'bg-info-transparent', moving: 'bg-info-transparent', stored: 'bg-success-transparent', deleted: 'bg-secondary-transparent' }[s] ?? 'bg-light text-default');

const attendance = computed(() => [...(data.value?.attendance ?? [])].sort((a, b) => new Date(a.joined_at) - new Date(b.joined_at)));
const freelancerNeverCame = computed(() => !!data.value && new Date() >= new Date(data.value.starts_at) && !attendance.value.some((a) => a.side === 'freelancer'));

const stateKey = computed(() => {
    const d = data.value;
    if (!d) return null;
    if (d.closed) return d.closed.by === 'staff' ? 'closed_staff' : 'closed_time';
    if (d.room_open) return 'open';
    if (new Date() < new Date(d.opens_at)) return 'not_yet';
    return 'closed';
});
const stateText = computed(() => (stateKey.value ? t(`freelance.consultations.room.states.${stateKey.value}`, { at: when(data.value.opens_at) }) : ''));
const stateBadge = computed(() => ({ open: 'bg-success-transparent', closed_staff: 'bg-danger-transparent', not_yet: 'bg-info-transparent' }[stateKey.value] ?? 'bg-secondary-transparent'));

function toggle() {
    expanded.value = !expanded.value;
    if (expanded.value && !data.value && !loading.value) load();
}

function stopPlayers() {
    Object.keys(playing).forEach((k) => delete playing[k]);
    Object.keys(expired).forEach((k) => delete expired[k]);
}

function togglePlay(r) {
    playing[r.uuid] = !playing[r.uuid];
    expired[r.uuid] = false;
}

/** Fresh links (each lasts a few minutes): players open again only when the admin presses play. */
async function load() {
    loading.value = true;
    failed.value = false;
    try {
        const { data: res } = await adminAxios.get(`${base}/${props.uuid}/session`);
        data.value = res.data;
        stopPlayers();
    } catch (error) {
        failed.value = true;
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        loading.value = false;
    }
}

async function act(action) {
    if (action === 'close' && !window.confirm(t('freelance.consultations.room.close_confirm'))) return;
    acting.value = true;
    try {
        const response = await adminAxios.post(`${base}/${props.uuid}/session/${action}`);
        showSuccess(extractApiMessage(response, t('toast.success')));
        data.value = { ...response.data.data, views: data.value?.views ?? [] };
        stopPlayers();
    } catch (error) {
        showError(extractApiErrorMessage(error, t('toast.error')));
    } finally {
        acting.value = false;
    }
}

watch(() => props.uuid, () => {
    data.value = null;
    expanded.value = props.evidence;
    if (expanded.value) load();
}, { immediate: true });
</script>

<style scoped>
.session-head {
    background: transparent;
    border: 0;
}

.session-evidence {
    border-width: 2px !important;
    background: rgba(255, 193, 7, 0.04);
}

.attendance li {
    padding: 4px 0;
}

.views {
    max-height: 240px;
    overflow-y: auto;
}

video {
    max-height: 360px;
}
</style>
