import { useUserAuthStore } from '../../stores/userAuth';

export default function userGuest({ next }) {
    const authStore = useUserAuthStore();

    if (authStore.isAuthenticated) {
        return next({ name: 'user.dashboard' });
    }

    return next();
}
