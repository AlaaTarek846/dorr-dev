/** The badge colour of a verification request status (list and detail share it). */
export function statusClass(status) {
    return {
        new: 'bg-primary-transparent',
        under_review: 'bg-info-transparent',
        needs_completion: 'bg-warning-transparent',
        preliminary_approved: 'bg-success-transparent',
        in_execution: 'bg-info-transparent',
        awaiting_evidence: 'bg-warning-transparent',
        final_review: 'bg-info-transparent',
        completed: 'bg-success',
        rejected: 'bg-danger-transparent',
        cancelled: 'bg-secondary-transparent',
        expired: 'bg-secondary-transparent',
        suspended: 'bg-danger',
    }[status] ?? 'bg-light';
}
