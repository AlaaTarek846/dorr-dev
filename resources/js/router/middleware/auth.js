import { useAuthStore } from '../../stores/auth';

export default function auth({ next }) {
    const authStore = useAuthStore();

    if (! authStore.isAuthenticated) {
        return next({ name: 'admin.login' });
    }

    return next();
}
