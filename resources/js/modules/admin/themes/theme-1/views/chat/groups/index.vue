<template>
    <div>
        <WalletPageHeader :title="t('chat.groups.title')" :section="t('sidebar.chat')" :total="pagination?.total ?? null" />

        <!-- GR-053: the numbers -->
        <div class="row g-3 mb-3">
            <div v-for="tile in tiles" :key="tile.key" class="col-6 col-md-4 col-xl-2">
                <div class="card custom-card mb-0 h-100">
                    <div class="card-body py-3">
                        <div class="fs-12 text-muted">{{ t(`chat.groups.stat_${tile.key}`) }}</div>
                        <div class="fs-20 fw-semibold" :class="tile.class">{{ stats[tile.key] ?? '—' }}</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card custom-card">
            <div class="card-header d-flex align-items-center flex-wrap gap-2 py-3">
                <div class="input-group input-group-sm" style="max-width: 240px;">
                    <span class="input-group-text bg-white"><i class="ri-search-line text-muted"></i></span>
                    <input v-model="filters.search" type="search" class="form-control" :placeholder="t('chat.groups.search')">
                </div>
                <div class="btn-group btn-group-sm" role="group">
                    <button v-for="tab in kindTabs" :key="tab.value" type="button" class="btn" :class="filters.kind === tab.value ? 'btn-primary' : 'btn-outline-primary'" @click="filters.kind = tab.value">{{ tab.label }}</button>
                </div>
                <select v-model="filters.status" class="form-select form-select-sm" style="max-width: 170px;">
                    <option value="">{{ t('chat.portals.all') }}</option>
                    <option v-for="s in statuses" :key="s" :value="s">{{ t(`chat.groups.status_${s}`) }}</option>
                </select>
                <button type="button" class="btn btn-sm btn-light ms-auto" @click="openTemplates"><i class="ri-shield-user-line me-1"></i>{{ t('chat.groups.templates') }}</button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table text-nowrap table-striped table-hover mb-0">
                        <thead>
                            <tr>
                                <th class="ps-4">{{ t('chat.groups.group') }}</th>
                                <th>{{ t('chat.groups.kind') }}</th>
                                <th>{{ t('chat.groups.owner') }}</th>
                                <th>{{ t('chat.groups.members') }}</th>
                                <th>{{ t('chat.groups.status') }}</th>
                                <th>{{ t('chat.groups.created') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="loading"><td colspan="6" class="text-center py-5"><span class="spinner-border spinner-border-sm"></span></td></tr>
                            <tr v-else-if="!rows.length"><td colspan="6" class="text-center text-muted py-5">{{ t('wallet.common.empty') }}</td></tr>
                            <template v-else>
                                <tr v-for="row in rows" :key="row.id" role="button" @click="openGroup(row)">
                                    <td class="ps-4 fw-semibold">{{ row.name }}</td>
                                    <td><span class="badge" :class="row.kind === 'education' ? 'bg-info-transparent' : 'bg-warning-transparent'">{{ t(`chat.groups.kind_${row.kind}`) }}</span></td>
                                    <td>
                                        <div>{{ row.owner?.name || '—' }}</div>
                                        <div class="fs-12 text-muted" dir="ltr">{{ row.owner?.phone }}</div>
                                    </td>
                                    <td>{{ row.members_count }}</td>
                                    <td><span class="badge" :class="statusClass(row.status)">{{ t(`chat.groups.status_${row.status}`) }}</span></td>
                                    <td>{{ formatDate(row.created_at) }}</td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>
            <WalletPagination :pagination="pagination" @change="fetch" />
        </div>

        <!-- One group: details, members, roles, spaces, activity log (opening it is recorded) -->
        <div v-if="detail" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.45);" @click.self="detail = null">
            <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ detail.name }} <span class="badge ms-2" :class="statusClass(detail.status)">{{ t(`chat.groups.status_${detail.status}`) }}</span></h6>
                        <button type="button" class="btn-close" @click="detail = null"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning fs-12 py-2">{{ t('chat.groups.privacy_note') }}</div>
                        <ul class="nav nav-tabs mb-3">
                            <li v-for="tab in detailTabs" :key="tab" class="nav-item">
                                <a class="nav-link" :class="{ active: detailTab === tab }" href="#" @click.prevent="selectTab(tab)">{{ t(`chat.groups.tab_${tab}`) }}</a>
                            </li>
                        </ul>

                        <div v-if="detailTab === 'info'" class="row g-3 fs-13">
                            <div class="col-md-4"><span class="text-muted">{{ t('chat.groups.kind') }}:</span> {{ t(`chat.groups.kind_${detail.kind}`) }}</div>
                            <div class="col-md-4"><span class="text-muted">{{ t('chat.groups.owner') }}:</span> {{ detail.owner?.name }} <span dir="ltr">{{ detail.owner?.phone }}</span></div>
                            <div class="col-md-4"><span class="text-muted">{{ t('chat.groups.members') }}:</span> {{ detail.members_count }}</div>
                            <div class="col-md-4"><span class="text-muted">{{ t('chat.groups.join_mode') }}:</span> {{ detail.join_mode }}</div>
                            <div class="col-md-4"><span class="text-muted">{{ t('chat.groups.members_hidden') }}:</span> {{ detail.members_hidden ? '✓' : '—' }}</div>
                            <div v-if="detail.purge_after" class="col-md-4"><span class="text-muted">{{ t('chat.groups.purge_after') }}:</span> {{ formatDate(detail.purge_after) }}</div>
                            <div class="col-12">{{ detail.description }}</div>
                        </div>

                        <table v-if="detailTab === 'members'" class="table table-sm fs-13">
                            <thead><tr><th>{{ t('chat.groups.member') }}</th><th>{{ t('chat.groups.phone') }}</th><th>{{ t('chat.groups.chat_role') }}</th><th>{{ t('chat.groups.roles') }}</th></tr></thead>
                            <tbody>
                                <tr v-for="m in detail.members" :key="m.participant_id">
                                    <td>{{ m.name }}</td><td dir="ltr">{{ m.phone }}</td><td>{{ m.role }}</td>
                                    <td><span v-for="r in m.roles" :key="r" class="badge bg-light text-dark me-1">{{ r }}</span></td>
                                </tr>
                            </tbody>
                        </table>

                        <div v-if="detailTab === 'roles'">
                            <div v-for="r in detail.roles" :key="r.id" class="mb-2">
                                <span class="fw-semibold">{{ r.name }}</span>
                                <span v-if="r.is_default" class="badge bg-primary-transparent ms-1">{{ t('chat.groups.default_role') }}</span>
                                <div class="fs-12 text-muted">{{ (r.permissions || []).join(' · ') }}</div>
                            </div>
                        </div>

                        <table v-if="detailTab === 'spaces'" class="table table-sm fs-13">
                            <tbody>
                                <tr v-for="s in detail.spaces" :key="s.id"><td>{{ s.name }}</td><td>{{ s.type }}</td><td>{{ s.is_closed ? t('chat.groups.closed') : '' }}</td></tr>
                                <tr v-if="!detail.spaces.length"><td class="text-muted">—</td></tr>
                            </tbody>
                        </table>

                        <div v-if="detailTab === 'audit'">
                            <div v-if="auditLoading" class="text-center py-3"><span class="spinner-border spinner-border-sm"></span></div>
                            <table v-else class="table table-sm fs-13">
                                <tbody>
                                    <tr v-for="a in audit" :key="a.id">
                                        <td class="text-muted text-nowrap">{{ formatDateTime(a.created_at) }}</td>
                                        <td>{{ a.actor || '—' }}</td>
                                        <td>{{ a.text }}</td>
                                        <td class="fs-11 text-muted">{{ a.subject }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div v-if="canUpdate" class="modal-footer">
                        <button v-if="detail.status === 'deleted'" type="button" class="btn btn-sm btn-success" :disabled="busy" @click="restore"><i class="ri-arrow-go-back-line me-1"></i>{{ t('chat.groups.restore') }}</button>
                        <button v-else type="button" class="btn btn-sm" :class="detail.status === 'disabled' ? 'btn-success' : 'btn-danger'" :disabled="busy" @click="toggleDisabled">
                            {{ detail.status === 'disabled' ? t('chat.groups.enable') : t('chat.groups.disable') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- GR-052: the role templates (read only) -->
        <div v-if="templates" class="modal d-block" tabindex="-1" style="background: rgba(0,0,0,.45);" @click.self="templates = null">
            <div class="modal-dialog modal-lg modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">{{ t('chat.groups.templates') }}</h6>
                        <button type="button" class="btn-close" @click="templates = null"></button>
                    </div>
                    <div class="modal-body">
                        <div v-for="(kind, key) in templates" :key="key" class="mb-4">
                            <h6>{{ t(`chat.groups.kind_${key}`) }}</h6>
                            <div v-for="r in kind.roles" :key="r.template" class="mb-2">
                                <span class="fw-semibold">{{ r.name }}</span>
                                <span v-if="r.template === kind.leader" class="badge bg-success-transparent ms-1">{{ t('chat.groups.leader_role') }}</span>
                                <span v-if="r.template === kind.default" class="badge bg-primary-transparent ms-1">{{ t('chat.groups.default_role') }}</span>
                                <div class="fs-12 text-muted">{{ r.permissions.map((p) => permName(kind, p)).join(' · ') }}</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import adminAxios from '../../../../../../../api/adminAxios';
import WalletPageHeader from '../../../../../../../components/wallet/WalletPageHeader.vue';
import WalletPagination from '../../../../../../../components/wallet/WalletPagination.vue';
import useToast, { extractApiErrorMessage } from '../../../../../../../composables/useToast';
import useWalletList from '../../../../../../../composables/useWalletList';
import { usePermission } from '../../../../../../../composables/usePermission';

const { t, locale } = useI18n();
const { can } = usePermission();
const { showError, showSuccess } = useToast();

const canUpdate = computed(() => can('chat-groups.update'));
const { rows, loading, pagination, filters, fetch } = useWalletList('chat-groups', { defaults: { search: '', kind: '', status: '' } });
const kindTabs = computed(() => [
    { value: '', label: t('chat.portals.all') },
    { value: 'education', label: t('chat.groups.kind_education') },
    { value: 'work', label: t('chat.groups.kind_work') },
]);
const statuses = ['active', 'archived', 'disabled', 'deleted'];
const tiles = [
    { key: 'education' }, { key: 'work' }, { key: 'active_week', class: 'text-success' },
    { key: 'tasks_open' }, { key: 'reports_pending', class: 'text-danger' }, { key: 'disabled', class: 'text-warning' },
];
const stats = ref({});
const detail = ref(null);
const detailTab = ref('info');
const detailTabs = ['info', 'members', 'roles', 'spaces', 'audit'];
const audit = ref([]);
const auditLoading = ref(false);
const templates = ref(null);
const busy = ref(false);

const formatDate = (iso) => (iso ? new Date(iso).toLocaleDateString(locale.value) : '—');
const formatDateTime = (iso) => (iso ? new Date(iso).toLocaleString(locale.value) : '—');
const statusClass = (s) => ({ active: 'bg-success-transparent', archived: 'bg-secondary-transparent', disabled: 'bg-warning-transparent', deleted: 'bg-danger-transparent' }[s] || 'bg-light');
const permName = (kind, key) => kind.permissions.find((p) => p.key === key)?.name ?? key;

async function loadStats() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-groups/stats');
        stats.value = data.data ?? {};
    } catch {
        stats.value = {};
    }
}

async function openGroup(row) {
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/chat-groups/${row.id}`);
        detail.value = data.data;
        detailTab.value = 'info';
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

async function selectTab(tab) {
    detailTab.value = tab;
    if (tab !== 'audit' || !detail.value) return;
    auditLoading.value = true;
    try {
        const { data } = await adminAxios.get(`/api/admin/v1/chat-groups/${detail.value.id}/audit-log`);
        audit.value = data.data ?? [];
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        auditLoading.value = false;
    }
}

async function toggleDisabled() {
    if (!detail.value) return;
    const disabled = detail.value.status !== 'disabled';
    if (disabled && !window.confirm(t('chat.groups.disable_confirm'))) return;
    busy.value = true;
    try {
        const { data } = await adminAxios.patch(`/api/admin/v1/chat-groups/${detail.value.id}/disable`, { disabled });
        Object.assign(detail.value, data.data);
        showSuccess(t('chat.groups.saved'));
        fetch(pagination.value?.current_page ?? 1);
        loadStats();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = false;
    }
}

async function restore() {
    busy.value = true;
    try {
        const { data } = await adminAxios.post(`/api/admin/v1/chat-groups/${detail.value.id}/restore`);
        Object.assign(detail.value, data.data);
        showSuccess(t('chat.groups.saved'));
        fetch(pagination.value?.current_page ?? 1);
        loadStats();
    } catch (error) {
        showError(extractApiErrorMessage(error));
    } finally {
        busy.value = false;
    }
}

async function openTemplates() {
    try {
        const { data } = await adminAxios.get('/api/admin/v1/chat-groups/templates');
        templates.value = data.data;
    } catch (error) {
        showError(extractApiErrorMessage(error));
    }
}

onMounted(() => {
    fetch(1);
    loadStats();
});
</script>
