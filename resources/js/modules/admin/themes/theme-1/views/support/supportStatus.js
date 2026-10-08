/** Badge class for a ticket status (the same colours everywhere in the support screens). */
export function statusClass(status) {
    return {
        opened: 'bg-primary-transparent',
        reopened: 'bg-info-transparent',
        resolved: 'bg-success-transparent',
        closed: 'bg-danger-transparent',
    }[status] || 'bg-light text-default';
}

export const SUPPORT_STATUSES = ['opened', 'reopened', 'resolved', 'closed'];
