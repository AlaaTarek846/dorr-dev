import { reactive } from 'vue';

export function useConfirmDelete() {
    const state = reactive({
        show: false,
        title: '',
        message: '',
        loading: false,
        payload: null,
    });

    function open({ title, message, payload = null }) {
        state.title = title;
        state.message = message;
        state.payload = payload;
        state.loading = false;
        state.show = true;
    }

    function close() {
        if (state.loading) {
            return;
        }

        state.show = false;
        state.payload = null;
    }

    function setLoading(value) {
        state.loading = value;
    }

    return {
        state,
        open,
        close,
        setLoading,
    };
}
