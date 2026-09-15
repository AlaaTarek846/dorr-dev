<template>
    <div class="app-toast-container" aria-live="polite" aria-atomic="true">
        <TransitionGroup name="app-toast-fade">
            <div
                v-for="toast in toasts"
                :key="toast.id"
                class="app-toast"
                :class="`app-toast--${toast.type}`"
            >
                <span class="app-toast__icon">
                    <i :class="iconFor(toast.type)"></i>
                </span>
                <div class="app-toast__message">{{ toast.message }}</div>
                <button
                    type="button"
                    class="app-toast__close"
                    :aria-label="t('close')"
                    @click="remove(toast.id)"
                >
                    <i class="ri-close-line"></i>
                </button>
                <span
                    class="app-toast__progress"
                    :style="{ animationDuration: `${toast.duration}ms` }"
                ></span>
            </div>
        </TransitionGroup>
    </div>
</template>

<script setup>
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import { useToastStore } from '../../stores/toast';

const { t } = useI18n();
const toastStore = useToastStore();
const { toasts } = storeToRefs(toastStore);

function remove(id) {
    toastStore.remove(id);
}

function iconFor(type) {
    return {
        success: 'ri-check-line',
        danger: 'ri-close-circle-line',
        warning: 'ri-error-warning-line',
        info: 'ri-information-line',
    }[type] ?? 'ri-information-line';
}
</script>

<style scoped>
.app-toast-container {
    position: fixed;
    top: 1.25rem;
    left: 50%;
    transform: translateX(-50%);
    z-index: 1090;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    width: min(100vw - 2rem, 26rem);
    pointer-events: none;
}

.app-toast {
    position: relative;
    width: 100%;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem 1rem;
    background: #fff;
    border-radius: 0.5rem;
    box-shadow: 0 0.5rem 1.5rem rgba(15, 23, 42, 0.1);
    border: 1px solid rgba(15, 23, 42, 0.06);
    overflow: hidden;
    pointer-events: auto;
}

.app-toast__icon {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 50%;
    flex-shrink: 0;
    color: #fff;
    font-size: 1.125rem;
}

.app-toast__message {
    flex: 1;
    font-size: 0.9375rem;
    font-weight: 500;
    color: #495057;
    line-height: 1.45;
}

.app-toast__close {
    border: 0;
    background: transparent;
    color: #8c9097;
    padding: 0.125rem;
    line-height: 1;
    font-size: 1.125rem;
    cursor: pointer;
    flex-shrink: 0;
    border-radius: 0.25rem;
    transition: color 0.15s ease, background-color 0.15s ease;
}

.app-toast__close:hover {
    color: #495057;
    background-color: rgba(15, 23, 42, 0.05);
}

.app-toast__progress {
    position: absolute;
    inset-inline: 0;
    bottom: 0;
    height: 3px;
    transform-origin: left;
    animation-name: app-toast-progress;
    animation-timing-function: linear;
    animation-fill-mode: forwards;
}

[dir='rtl'] .app-toast__progress {
    transform-origin: right;
}

.app-toast--success .app-toast__progress {
    background-color: #26bf94;
}

.app-toast--danger .app-toast__progress {
    background-color: #e6533c;
}

.app-toast--warning .app-toast__progress {
    background-color: #f5b849;
}

.app-toast--info .app-toast__progress {
    background-color: #49b6f5;
}

.app-toast--success .app-toast__icon {
    background-color: #26bf94;
}

.app-toast--danger .app-toast__icon {
    background-color: #e6533c;
}

.app-toast--warning .app-toast__icon {
    background-color: #f5b849;
}

.app-toast--info .app-toast__icon {
    background-color: #49b6f5;
}

.app-toast-fade-enter-active,
.app-toast-fade-leave-active {
    transition: opacity 0.25s ease, transform 0.25s ease;
}

.app-toast-fade-enter-from,
.app-toast-fade-leave-to {
    opacity: 0;
    transform: translateY(-0.75rem);
}

@keyframes app-toast-progress {
    from {
        transform: scaleX(1);
    }

    to {
        transform: scaleX(0);
    }
}
</style>
