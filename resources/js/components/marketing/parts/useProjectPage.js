import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { relevantChange, useMk } from '../marketingContext';

/**
 * What every phase B / C page of one project needs: the project (abilities, currency), the country switch of its
 * section (`status.features[feature]`), and `onPush(fn)` — called once per realtime push for this project (or a
 * reconnect). Pages load on open; they never ask on a timer.
 */
export default function useProjectPage(feature) {
    const route = useRoute();
    const mk = useMk();
    const projectId = route.params.projectId;
    const project = ref(null);

    const abilities = computed(() => project.value?.abilities ?? {});
    const statusReady = computed(() => mk.state.status !== null);
    const enabled = computed(() => mk.feature(feature));
    const active = computed(() => project.value?.status === 'active');
    const currency = computed(() => project.value?.currency || mk.state.status?.country?.currency_code || '');

    async function loadProject() {
        try {
            const { data } = await mk.http.get(mk.api(`/projects/${projectId}`));
            project.value = data.data;
        } catch (error) {
            mk.fail(error);
        }
    }

    function onPush(fn) {
        watch(() => mk.state.changes, () => {
            if (relevantChange(mk.state, projectId)) {
                fn();
            }
        });
    }

    onMounted(() => {
        if (! mk.state.status) {
            mk.ctx.loadStatus();
        }
        loadProject();
    });

    return { ...mk, projectId, project, abilities, statusReady, enabled, active, currency, loadProject, onPush };
}
