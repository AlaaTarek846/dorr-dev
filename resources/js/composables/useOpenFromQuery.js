import { onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';

/**
 * Opens a record's modal when the page is reached from a notification: `?{key}=ID` in the address calls `open(ID)`,
 * then the query is dropped so a refresh does not open it again. Works on arrival and while already on the page.
 *
 * @param {string} key the query parameter (e.g. "withdrawal")
 * @param {(value: string) => Promise<void>|void} open what opens the record
 */
export default function useOpenFromQuery(key, open) {
    const route = useRoute();
    const router = useRouter();

    async function check() {
        const value = route.query[key];

        if (! value) {
            return;
        }

        try {
            await open(String(value));
        } finally {
            router.replace({ name: route.name, query: {} });
        }
    }

    watch(() => route.query[key], check);
    onMounted(check);
}
