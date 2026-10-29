/**
 * Keeps every open PrimeVue dropdown (Select, MultiSelect, CascadeSelect, TreeSelect) glued to its field
 * while the page, or a modal body, scrolls. PrimeVue appends the panel to <body> and positions it once, so it
 * would otherwise stay where it was drawn while the field moves away.
 *
 * Global on purpose: one listener for the whole app, no per-page code.
 */
const TRIGGERS = '.p-select-open, .p-multiselect-open, .p-cascadeselect-open, .p-treeselect-open';
const PANELS = '.p-select-overlay, .p-multiselect-overlay, .p-cascadeselect-overlay, .p-treeselect-overlay';

let frame = null;

function follow() {
    frame = null;

    const trigger = document.querySelector(TRIGGERS);
    const panel = document.querySelector(PANELS);
    if (!trigger || !panel) return;

    const field = trigger.getBoundingClientRect();
    const box = panel.getBoundingClientRect();
    // The panel opens below the field, or above it when there is no room: keep whichever side it is on.
    const above = box.top + box.height / 2 < field.top + field.height / 2;
    const top = (above ? field.top - box.height : field.bottom) + window.scrollY;
    const left = field.left + window.scrollX;

    panel.style.top = `${Math.round(top)}px`;
    panel.style.left = `${Math.round(left)}px`;
}

function schedule(event) {
    // Scrolling inside the panel itself (a long list) must not move it.
    if (event.target instanceof Element && event.target.closest?.(PANELS)) return;
    if (frame === null) frame = window.requestAnimationFrame(follow);
}

export function installPrimeOverlayFollow() {
    if (typeof window === 'undefined' || window.__primeOverlayFollow) return;
    window.__primeOverlayFollow = true;
    // Capture: scroll does not bubble, and the scrolling box may be the page or a modal.
    window.addEventListener('scroll', schedule, { capture: true, passive: true });
    window.addEventListener('resize', schedule, { passive: true });
}
