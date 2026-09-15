import { useAuthStore } from '../../stores/auth';

export default function guest({ next }) {
    const authStore = useAuthStore();

    if (authStore.isAuthenticated) {
        return next({ name: 'admin.dashboard' });
    }

    return next();
}
