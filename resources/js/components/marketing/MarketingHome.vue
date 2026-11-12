<template>
    <div>
        <MkHeader :title="t('marketing.title')" :subtitle="t('marketing.menu_sub')">
            <button v-if="open && subscription?.active" type="button" class="btn btn-primary" @click="creating = true">
                <i class="ri-add-line me-1"></i>{{ t('marketing.new_project') }}
            </button>
        </MkHeader>

        <!-- Status could not be read -->
        <div v-if="!status && state.statusError" class="card custom-card mk-card">
            <div class="card-body text-center py-5">
                <i class="ri-megaphone-line fs-1 text-muted d-block mb-2"></i>
                <p class="mb-3">{{ t('marketing.error_generic') }}</p>
                <button type="button" class="btn btn-light" @click="ctx.loadStatus()">{{ t('marketing.retry') }}</button>
            </div>
        </div>

        <!-- Loading -->
        <div v-else-if="!status" class="d-grid gap-3">
            <div class="mk-skeleton" style="height: 140px"></div>
            <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 70px"></div>
        </div>

        <!-- Not open in this country -->
        <div v-else-if="!status.available" class="card custom-card mk-card">
            <div class="card-body text-center py-5">
                <span class="mk-icon bg-info-transparent text-info mb-3"><i class="ri-earth-line"></i></span>
                <h6 class="fw-semibold">{{ t('marketing.unavailable_title') }}</h6>
                <p class="text-muted mb-0">{{ t('marketing.unavailable_text') }}</p>
            </div>
        </div>

        <template v-else>
            <div class="row g-3">
                <div class="col-xl-5 col-lg-6">
                    <PackageCard />
                    <div v-if="!status.research_ready" class="alert alert-warning mt-3 mb-0 fs-13">
                        <i class="ri-information-line me-1"></i>{{ t('marketing.research_not_ready') }}
                    </div>
                </div>

                <div class="col-xl-7 col-lg-6">
                    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
                        <h2 class="mk-section-title m-0 flex-grow-1">{{ t('marketing.projects') }}</h2>
                        <div class="btn-group btn-group-sm" role="group">
                            <button
                                v-for="f in filters"
                                :key="f.value"
                                type="button"
                                class="btn"
                                :class="statusFilter === f.value ? 'btn-primary' : 'btn-light'"
                                @click="statusFilter = f.value"
                            >{{ f.label }}</button>
                        </div>
                    </div>
                    <div class="position-relative mb-3">
                        <input
                            v-model="query"
                            type="search"
                            class="form-control ps-5"
                            maxlength="100"
                            :placeholder="t('marketing.search_projects')"
                        >
                        <i class="ri-search-line position-absolute top-50 translate-middle-y text-muted" style="inset-inline-start: 1rem"></i>
                    </div>

                    <div v-if="!loaded" class="d-grid gap-2">
                        <div v-for="i in 3" :key="i" class="mk-skeleton" style="height: 70px"></div>
                    </div>
                    <div v-else-if="projects.length === 0" class="card custom-card mk-card">
                        <div class="card-body text-center py-5">
                            <span class="mk-icon bg-primary-transparent text-primary mb-3"><i class="ri-briefcase-4-line"></i></span>
                            <h6 class="fw-semibold">{{ t('marketing.no_projects_title') }}</h6>
                            <p class="text-muted mb-0">{{ t('marketing.no_projects_text') }}</p>
                        </div>
                    </div>
                    <div v-else class="d-grid gap-2">
                        <MkNavCard
                            v-for="p in projects"
                            :key="p.id"
                            icon="ri-briefcase-4-line"
                            :tone="p.status === 'archived' ? 'secondary' : 'primary'"
                            :title="p.name"
                            :subtitle="projectSubtitle(p)"
                            :to="to('project', { projectId: p.id })"
                        >
                            <template #trailing>
                                <span v-if="p.status === 'archived'" class="badge bg-light text-muted">{{ t('marketing.archived_badge') }}</span>
                                <span v-else class="badge bg-primary-transparent">{{ t(roleKey(p.my_role)) }}</span>
                            </template>
                        </MkNavCard>
                        <button v-if="hasMore" type="button" class="btn btn-light" :disabled="loadingMore" @click="loadMore">
                            <span v-if="loadingMore" class="spinner-border spinner-border-sm me-1"></span>{{ t('marketing.load_more') }}
                        </button>
                    </div>
                </div>
            </div>
        </template>

        <NewProjectModal :show="creating" @close="creating = false" @created="onCreated" />
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { roleKey, stageKey, useMk } from './marketingContext';
import MkHeader from './parts/MkHeader.vue';
import MkNavCard from './parts/MkNavCard.vue';
import PackageCard from './parts/PackageCard.vue';
import NewProjectModal from './parts/NewProjectModal.vue';

const { ctx, state, http, api, to, fail, t, router } = useMk();

const status = computed(() => state.status);
const subscription = computed(() => state.status?.subscription);
const open = computed(() => state.status?.available === true);

const projects = ref([]);
const page = ref(1);
const hasMore = ref(false);
const loaded = ref(false);
const loadingMore = ref(false);
const query = ref('');
const statusFilter = ref('');
const creating = ref(false);
let searchTimer = null;

const filters = computed(() => [
    { value: '', label: t('marketing.filter_all') },
    { value: 'active', label: t('marketing.filter_active') },
    { value: 'archived', label: t('marketing.archived_badge') },
]);

function params(p) {
    return { page: p, q: query.value.trim() || undefined, status: statusFilter.value || undefined };
}

async function loadProjects() {
    if (! open.value) {
        return;
    }
    try {
        const { data } = await http.get(api('/projects'), { params: params(1) });
        projects.value = data.data ?? [];
        page.value = 1;
        hasMore.value = data.pagination?.has_more_pages === true;
    } catch (error) {
        fail(error);
    } finally {
        loaded.value = true;
    }
}

async function loadMore() {
    loadingMore.value = true;
    try {
        const { data } = await http.get(api('/projects'), { params: params(page.value + 1) });
        const seen = new Set(projects.value.map((p) => p.id));
        projects.value.push(...(data.data ?? []).filter((p) => ! seen.has(p.id)));
        page.value += 1;
        hasMore.value = data.pagination?.has_more_pages === true;
    } catch (error) {
        fail(error);
    } finally {
        loadingMore.value = false;
    }
}

function projectSubtitle(p) {
    return [p.industry, p.cities?.[0], t(stageKey(p.stage))].filter(Boolean).join(' · ');
}

function onCreated(project) {
    creating.value = false;
    ctx.loadStatus();
    router.push(to('project', { projectId: project.id }));
}

// A search waits for the person to stop typing (debounce, not polling).
watch(query, () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadProjects, 350);
});
watch(statusFilter, loadProjects);
watch(open, (isOpen) => isOpen && loadProjects());
// A job moved or the connection came back: refresh the usage once.
watch(() => state.changes, () => ctx.loadStatus());

onMounted(() => {
    if (open.value) {
        loadProjects();
    }
    ctx.loadStatus();
});

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>
