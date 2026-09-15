import { useUserAuthStore } from '../../stores/userAuth';

export default function userAuth({ next }) {
    const authStore = useUserAuthStore();

    if (! authStore.isAuthenticated) {
        return next({ name: 'user.login' });
    }

    return next();
}
