<?php

namespace Modules\Chat\Services\Verification;

use Modules\Admin\Models\Admin;
use Modules\Chat\Enums\VerificationRequestStatus as Status;
use Modules\Chat\Enums\VerificationTrack;
use Modules\Chat\Http\Middleware\EnsureVerificationPermission;
use Modules\Chat\Models\Verification\ChatVerificationBadge;
use Modules\Chat\Models\Verification\ChatVerificationRequest as VRequest;
use Modules\Chat\Models\Verification\ChatVerificationRequestDocument as Doc;
use Modules\Chat\Models\Verification\ChatVerificationRequestTransition as Move;
use Modules\Chat\Support\VerifiedAccounts;

/**
 * How a verification request reads (AV-013, AV-090): to the account — its status, the next step, the deadline,
 * what it was asked for and the decision with its reason — and to the staff, with the whole trail and the actions
 * this member of staff may take now (the buttons). Files are given as API paths, never as public links.
 */
class VerificationRequestPresenter
{
    /** @return array<string, mixed> */
    public function forAccount(VRequest $r): array
    {
        $r->loadMissing(['documents.documentType.translations', 'transitions']);

        return $this->base($r, "chat/verification/request/{$r->uuid}/documents") + [
            'next_step' => $this->nextStep($r),
            'can_cancel' => $r->isOpen() && $r->status !== Status::FinalReview,
            'can_complete' => $r->status === Status::NeedsCompletion,
            'badge' => $this->badge(ChatVerificationBadge::query()->where('user_id', $r->user_id)->first()),
            'history' => $r->transitions->map(fn (Move $m) => [
                'status' => $m->to_status->value,
                'by' => $m->actor_type,
                'at' => $m->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** @return array<string, mixed> */
    public function forStaff(VRequest $r, Admin $admin): array
    {
        $r->loadMissing(['user', 'documents.documentType.translations', 'transitions']);

        return $this->base($r, "chat-verification/requests/{$r->uuid}/documents") + [
            'user' => $r->user === null ? null : [
                'id' => $r->user->id,
                'name' => $r->user->name,
                'phone' => $r->user->phone,
                'country_id' => $r->user->country_id,
                'is_verified' => VerifiedAccounts::has($r->user),
            ],
            'identity_checked_by' => $r->identity_checked_by,
            'decided_by' => $r->decided_by,
            'deadline_paused_at' => $r->deadline_paused_at?->toIso8601String(),
            'deadline_remaining_seconds' => $r->deadline_remaining_seconds,
            'suspended_from' => $r->suspended_from?->value,
            'history' => $r->transitions->map(fn (Move $m) => [
                'from' => $m->from_status?->value,
                'to' => $m->to_status->value,
                'actor_type' => $m->actor_type,
                'actor_id' => $m->actor_id,
                'reason' => $m->reason,
                'at' => $m->created_at?->toIso8601String(),
            ])->values(),
            'badge' => $this->badge($badge = ChatVerificationBadge::query()->where('user_id', $r->user_id)->first()),
            'activation_timing' => app(VerificationBadgeService::class)->timingFor($r)->value,
            'activation_timing_custom' => $r->activation_timing !== null,
            'actions' => $this->actions($r, $admin, $badge),
        ];
    }

    /** @return array<string, mixed>|null the badge as both sides read it (AV-024); null = never verified */
    public function badge(?ChatVerificationBadge $b): ?array
    {
        return $b === null ? null : [
            'status' => $b->status->value,
            'type' => $b->type,
            'is_verified' => $b->shows(),
            'active_from' => $b->active_from?->toIso8601String(),
            'expires_at' => $b->expires_at?->toIso8601String(),
            'reason' => $b->reason,
        ];
    }

    /** @return array<string, mixed> a row of the staff list */
    public function row(VRequest $r): array
    {
        return [
            'uuid' => $r->uuid,
            'track' => $r->track->value,
            'status' => $r->status->value,
            'full_name' => $r->full_name,
            'user' => $r->user === null ? null : ['id' => $r->user->id, 'name' => $r->user->name, 'phone' => $r->user->phone, 'is_verified' => VerifiedAccounts::has($r->user)],
            'identity_checked' => $r->identity_checked_at !== null,
            'deadline_at' => $r->deadline_at?->toIso8601String(),
            'deadline_owner' => $r->deadline_owner,
            'deadline_paused' => $r->deadline_paused_at !== null,
            'overdue' => $r->deadline_at !== null && $r->deadline_at->isPast(),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function base(VRequest $r, string $filesBase): array
    {
        return [
            'uuid' => $r->uuid,
            'track' => $r->track->value,
            'status' => $r->status->value,
            'is_open' => $r->isOpen(),
            'full_name' => $r->full_name,
            'contact_phone' => $r->contact_phone,
            'contact_email' => $r->contact_email,
            'notes' => $r->notes,
            'deadline_at' => $r->deadline_at?->toIso8601String(),
            'deadline_owner' => $r->deadline_owner,
            'deadline_paused' => $r->deadline_paused_at !== null,
            'identity_checked' => $r->identity_checked_at !== null,
            'identity_checked_at' => $r->identity_checked_at?->toIso8601String(),
            'completion_reason' => $r->completion_reason,
            'completion_document_type_ids' => array_values($r->completion_document_type_ids ?? []),
            'decision_reason' => $r->decision_reason,
            'decided_at' => $r->decided_at?->toIso8601String(),
            'submitted_at' => $r->submitted_at?->toIso8601String(),
            'closed_at' => $r->closed_at?->toIso8601String(),
            'documents' => $r->currentDocuments()->map(fn (Doc $d) => [
                'id' => $d->id,
                'type' => $d->documentType === null ? null : ['id' => $d->documentType->id, 'code' => $d->documentType->code, 'name' => $d->documentType->translated('name')],
                'status' => $d->status,
                'review_note' => $d->review_note,
                'files' => collect(Doc::SIDES)->mapWithKeys(fn (string $side) => [$side => ($m = $d->file($side)) === null ? null
                    : ['name' => $m->file_name, 'mime' => $m->mime_type, 'size' => $m->size, 'path' => "{$filesBase}/{$d->id}/{$side}"]])->all(),
                'uploaded_at' => $d->created_at?->toIso8601String(),
            ])->values(),
        ];
    }

    /** What the account should do (or wait for) now — the app words it. */
    private function nextStep(VRequest $r): string
    {
        return match ($r->status) {
            Status::New, Status::UnderReview => 'wait_review',
            Status::NeedsCompletion => 'complete',
            Status::PreliminaryApproved => $r->track === VerificationTrack::Paid ? 'pay' : 'agreement',
            Status::InExecution => 'publish_ad',
            Status::AwaitingEvidence => 'upload_proof',
            Status::FinalReview => 'wait_final',
            Status::Suspended => 'wait_staff',
            Status::Completed => 'done',
            Status::Rejected => 'rejected',
            Status::Cancelled, Status::Expired => 'closed',
        };
    }

    /**
     * The buttons this member of staff may press now — the server checks again on press.
     *
     * @return list<string>
     */
    private function actions(VRequest $r, Admin $admin, ?ChatVerificationBadge $badge = null): array
    {
        $can = fn (string $action) => EnsureVerificationPermission::allows($admin, 'chat-verification-requests.'.$action);
        $s = $r->status;
        $actions = [];

        if ($can('review')) {
            if ($s === Status::New) {
                $actions[] = 'start_review';
            }
            if (in_array($s, [Status::New, Status::UnderReview], true)) {
                $actions[] = 'review_documents';
            }
            if ($s === Status::UnderReview && $r->identity_checked_at === null) {
                $actions[] = 'check_identity';
            }
            if ($s->canMoveTo(Status::NeedsCompletion) && $s !== Status::Suspended) {
                $actions[] = 'request_completion';
            }
            if ($r->isOpen() && $s !== Status::Suspended && $r->deadline_owner !== null) {
                $actions[] = 'extend_deadline';
                $actions[] = $r->deadline_paused_at === null ? 'pause_deadline' : 'resume_deadline';
            }
            if ($s === Status::Suspended) {
                array_push($actions, 'resume', 'request_completion');
            }
        }
        if ($can('approve')) {
            if ($s === Status::UnderReview && $r->identity_checked_at !== null) {
                $actions[] = 'approve';
            }
            if ($s->canMoveTo(Status::Rejected)) {
                $actions[] = 'reject';
            }
            if ($s === Status::Suspended) {
                $actions[] = 'expire';
            }
            if ($r->isOpen()) {
                $actions[] = 'cancel';
            }
        }

        // AV-022 / AV-021: the badge before the end (after the identity check), and this request's timing.
        if ($can('early-activate') && $r->isOpen() && $r->identity_checked_at !== null && ! ($badge?->shows() ?? false)) {
            $actions[] = 'early_activate';
        }
        if ($r->isOpen() && EnsureVerificationPermission::allows($admin, 'chat-verification-badges.update')) {
            $actions[] = 'set_timing';
        }

        return array_values(array_unique($actions));
    }
}
