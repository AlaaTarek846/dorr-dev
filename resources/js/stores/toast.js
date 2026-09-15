import { defineStore } from 'pinia';

let toastId = 0;

export const useToastStore = defineStore('toast', {
    state: () => ({
        toasts: [],
    }),

    actions: {
        push({ message, type = 'success', duration = 4000 }) {
            const id = ++toastId;

            this.toasts.push({
                id,
                message,
                type,
                duration,
            });

            if (duration > 0) {
                setTimeout(() => this.remove(id), duration);
            }

            return id;
        },

        remove(id) {
            this.toasts = this.toasts.filter((toast) => toast.id !== id);
        },
    },
});
