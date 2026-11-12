<template>
    <div>
        <MkHeader :title="t('marketing.team')" :subtitle="project?.name || ''" :back="to('project', { projectId })">
            <button v-if="owner" type="button" class="btn btn-primary" @click="adding = true">
                <i class="ri-user-add-line me-1"></i>{{ t('marketing.add_member') }}
            </button>
        </MkHeader>

        <div class="row justify-content-center">
            <div class="col-xl-8">
                <div v-if="members === null" class="d-grid gap-2">
                    <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 64px"></div>
                </div>
                <div v-else class="card custom-card mk-card">
                    <ul class="list-group list-group-flush">
                        <li v-for="m in members" :key="m.id" class="list-group-item py-3">
                            <div class="d-flex align-items-center gap-3 flex-wrap">
                                <span class="mk-icon" :class="m.role === 'owner' ? 'bg-primary-transparent text-primary' : 'bg-light text-muted'">
                                    <i class="ri-user-3-line"></i>
                                </span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-semibold text-break">
                                        {{ m.name || m.phone }}<span v-if="m.is_me" class="text-muted fw-normal"> · {{ t('marketing.you') }}</span>
                                    </div>
                                    <div class="text-muted fs-12 mk-ltr d-inline-block">{{ m.phone }}</div>
                                </div>
                                <span class="badge" :class="m.role === 'owner' ? 'bg-primary-transparent' : 'bg-light text-muted'">{{ t(roleKey(m.role)) }}</span>
                                <div v-if="owner && m.role !== 'owner'" class="d-flex gap-2 mk-team-actions">
                                    <button type="button" class="btn btn-light btn-sm" @click="openRole(m)">
                                        <i class="ri-shield-user-line me-1"></i>{{ t('marketing.role') }}
                                    </button>
                                    <button type="button" class="btn btn-outline-danger btn-sm" @click="removing = m">
                                        <i class="ri-delete-bin-line me-1"></i>{{ t('marketing.remove') }}
                                    </button>
                                </div>
                            </div>
                        </li>
                    </ul>
                </div>

                <button v-if="project && !owner" type="button" class="btn btn-outline-danger mt-2" @click="leaving = true">
                    <i class="ri-logout-box-r-line me-1"></i>{{ t('marketing.leave') }}
                </button>
            </div>
        </div>

        <!-- Add a member -->
        <MkModal :show="adding" :title="t('marketing.add_member')" :busy="busy" @close="adding = false">
            <label class="form-label" for="mk-member-phone">{{ t('marketing.phone') }}</label>
            <input
                id="mk-member-phone"
                v-model="phone"
                type="tel"
                class="form-control mk-ltr"
                placeholder="+9665XXXXXXXX"
                maxlength="16"
                dir="ltr"
                @input="phone = mkNormalizeDigits(phone).replace(/[^\d+]/g, '').slice(0, 16)"
            >
            <label class="form-label mt-3 d-block">{{ t('marketing.role') }}</label>
            <RolePicker v-model="role" />
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="adding = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy || phone.length < 8" @click="add">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.add_member') }}
                </button>
            </template>
        </MkModal>

        <!-- Change a role -->
        <MkModal :show="editing !== null" :title="t('marketing.role')" :busy="busy" @close="editing = null">
            <RolePicker v-model="role" />
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="editing = null">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy || role === editing?.role" @click="changeRole">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.save') }}
                </button>
            </template>
        </MkModal>

        <!-- Remove / leave -->
        <MkModal :show="removing !== null" :title="t('marketing.remove')" :busy="busy" @close="removing = null">
            <p class="mb-0">{{ t('marketing.remove_confirm', { name: removing?.name || removing?.phone || '' }) }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="removing = null">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="busy" @click="remove">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.remove') }}
                </button>
            </template>
        </MkModal>
        <MkModal :show="leaving" :title="t('marketing.leave')" :busy="busy" @close="leaving = false">
            <p class="mb-0">{{ t('marketing.leave_confirm', { name: project?.name || '' }) }}</p>
            <template #footer>
                <button type="button" class="btn btn-light" :disabled="busy" @click="leaving = false">{{ t('marketing.cancel') }}</button>
                <button type="button" class="btn btn-danger" :disabled="busy" @click="leave">
                    <span v-if="busy" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.leave') }}
                </button>
            </template>
        </MkModal>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute } from 'vue-router';
import { mkNormalizeDigits, roleKey, useMk } from './marketingContext';
import MkHeader from './parts/MkHeader.vue';
import MkModal from './parts/MkModal.vue';
import RolePicker from './parts/RolePicker.vue';

/** The project team: the owner adds people by phone (same account kind as the owner) and gives a role; others may leave. */
const route = useRoute();
const { projectId } = route.params;
const { ctx, http, api, to, fail, t, toast, router } = useMk();

const project = ref(null);
const members = ref(null);
const adding = ref(false);
const editing = ref(null);
const removing = ref(null);
const leaving = ref(false);
const busy = ref(false);
const phone = ref('');
const role = ref('viewer');

const owner = computed(() => project.value?.abilities?.manage_team === true);

async function load() {
    try {
        const [p, m] = await Promise.all([
            http.get(api(`/projects/${projectId}`)),
            http.get(api(`/projects/${projectId}/members`)),
        ]);
        project.value = p.data.data;
        members.value = m.data.data ?? [];
    } catch (error) {
        if (members.value === null) members.value = [];
        fail(error);
    }
}

async function call(request, onDone) {
    busy.value = true;
    try {
        const { data } = await request();
        toast.showSuccess(data?.message);
        onDone?.();
        await load();
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

function openRole(m) {
    role.value = m.role;
    editing.value = m;
}

function add() {
    return call(() => http.post(api(`/projects/${projectId}/members`), { phone: phone.value, role: role.value }), () => {
        adding.value = false;
        phone.value = '';
        role.value = 'viewer';
    });
}

function changeRole() {
    const m = editing.value;

    return call(() => http.patch(api(`/projects/${projectId}/members/${m.id}`), { role: role.value }), () => { editing.value = null; });
}

function remove() {
    const m = removing.value;

    return call(() => http.delete(api(`/projects/${projectId}/members/${m.id}`)), () => { removing.value = null; });
}

async function leave() {
    busy.value = true;
    try {
        await http.post(api(`/projects/${projectId}/leave`));
        leaving.value = false;
        toast.showSuccess(t('marketing.left'));
        ctx.loadStatus();
        router.push(to('home'));
    } catch (error) {
        fail(error);
    } finally {
        busy.value = false;
    }
}

onMounted(load);
</script>

<style>
@media (max-width: 575.98px) {
    .mk-team-actions {
        width: 100%;
    }

    .mk-team-actions > .btn {
        flex: 1;
    }
}
</style>
