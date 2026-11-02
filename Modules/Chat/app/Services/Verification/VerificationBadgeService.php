<?php

namespace Modules\Chat\Services\Verification;

use App\Services\General\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\Admin;
use Modules\Chat\Enums\ConversationType;
use Modules\Chat\Enums\VerificationActivationTiming as Timing;
use Modules\Chat\Enums\VerificationBadgeStatus as Badge;
use Modules\Chat\Enums\VerificationRequestStatus;
use Modules\Chat\Enums\VerificationTrack;
use Modules\Chat\Exceptions\ChatException;
use Modules\Chat\Models\ChatParticipant;
use Modules\Chat\Models\Verification\ChatVerificationBadge;
use Modules\Chat\Models\Verification\ChatVerificationBadgeEvent;
use Modules\Chat\Models\Verification\ChatVerificationRequest as VRequest;
use Modules\Chat\Models\Verification\ChatVerificationSetting;
use Modules\Chat\Services\ChatBroadcaster;
use Modules\Chat\Support\ParticipantType;
use Modules\User\Models\User;

/**
 * The verification badge (docs/verification_tasks.md, batch 2): when it turns on (the track's timing, or
 * one set for a request), early activation after the first identity check, changes, holds and their end,
 * expiry and withdrawal. Its status is its own — apart from the request's and the subscription's (REQ-02-01).
 *
 * Every change writes the badge's trail and the audit log (with the reason), notifies the account and the
 * staff, and tells the account's direct contacts in real time (`account.verification.changed`) so the ✔
 * appears or goes next to the name on their screens without a reload.
 */
class VerificationBadgeService
{
    /** The real-time event the people who chat with the account receive. */
    public const PEER_EVENT = 'account.verification.changed';

    public function __construct(
        private readonly VerificationNotifier $notifier,
        private readonly AuditLogger $audit,
        private readonly ChatBroadcaster $broadcaster,
    ) {}

    public function of(User $user): ?ChatVerificationBadge
    {
        return ChatVerificationBadge::query()->where('user_id', $user->id)->first();
    }

    /** When this request's badge turns on: the one set for it, else its track's (AV-021). */
    public function timingFor(VRequest $request): Timing
    {
        return $request->activation_timing ?? ChatVerificationSetting::current()->activationFor($request->track);
    }

    /**
     * A step of the request happened (the identity check, the approval; later the payment and the ad proof):
     * the badge turns on when that step is the request's timing. Returns the badge when it did.
     *
     * @param  'identity_checked'|'approved'|'paid'|'proof_approved'  $step
     */
    public function stepReached(VRequest $request, string $step, ?Admin $by = null): ?ChatVerificationBadge
    {
        $timing = $this->timingFor($request);
        $due = match ($timing) {
            Timing::AfterIdentityCheck => $step === 'identity_checked',
            Timing::AfterApproval => $step === 'approved',
            Timing::AfterPayment => $step === 'paid',
            // Both must have happened; whichever comes second turns it on.
            Timing::AfterApprovalAndPayment => ($step === 'paid' && $request->decided_at !== null) || ($step === 'approved' && $request->paid_at !== null),
            Timing::AfterProofApproved => $step === 'proof_approved',
        };

        return $due ? $this->activate($request, Badge::Active, $by, 'timing:'.$timing->value) : null;
    }

    /** AV-022: a temporary (or a full) badge before the request ends — only after the first identity check (AT-11). */
    public function earlyActivate(Admin $admin, VRequest $request, Badge $status, string $reason): ChatVerificationBadge
    {
        if (! in_array($status, [Badge::Temporary, Badge::Active], true)) {
            throw new ChatException('verification_status_invalid', 422);
        }
        if (! $request->isOpen()) {
            throw new ChatException('verification_status_invalid', 409);
        }
        if ($request->identity_checked_at === null) {
            throw new ChatException('verification_identity_not_checked', 422);
        }

        $badge = $this->activate($request, $status, $admin, $reason, 'badge_early_activated');
        $this->audit->record('chat_verification.badge_early_activated', $badge, null, ['status' => $badge->status->value, 'request' => $request->uuid], $admin, $reason);

        return $badge;
    }

    /** AV-021: the moment this one request's badge turns on (someone allowed, with a reason). */
    public function setTiming(Admin $admin, VRequest $request, Timing $timing, string $reason): VRequest
    {
        if (! $request->isOpen()) {
            throw new ChatException('verification_status_invalid', 409);
        }
        $before = ['activation_timing' => $this->timingFor($request)->value];
        $request->forceFill(['activation_timing' => $timing])->save();
        $this->audit->record('chat_verification.request_activation_timing', $request, $before, ['activation_timing' => $timing->value], $admin, $reason);

        return $request->refresh();
    }

    /**
     * AV-023: the badge's type or its end changed (with a reason).
     *
     * @param  array{type?: string, expires_at?: ?CarbonInterface}  $changes
     */
    public function change(Admin $admin, User $user, array $changes, string $reason): ChatVerificationBadge
    {
        return $this->move($admin, $user, null, $reason, 'badge_changed', function (ChatVerificationBadge $b) use ($changes) {
            $before = ['type' => $b->type, 'expires_at' => $b->expires_at?->toIso8601String()];
            if (array_key_exists('type', $changes)) {
                $b->type = $changes['type'];
            }
            if (array_key_exists('expires_at', $changes)) {
                $b->expires_at = $changes['expires_at'];
            }

            return ['before' => $before, 'after' => ['type' => $b->type, 'expires_at' => $b->expires_at?->toIso8601String()]];
        });
    }

    /** AV-023 / AV-082: the ✔ is held (an investigation, a problem), keeping what it was. */
    public function suspend(Admin $admin, User $user, string $reason, string $event = 'badge_suspended'): ChatVerificationBadge
    {
        return $this->move($admin, $user, Badge::Suspended, $reason, $event, fn (ChatVerificationBadge $b) => ['suspended_from' => $b->status]);
    }

    /** AV-023: back to what it was before the hold. */
    public function reactivate(Admin $admin, User $user, string $reason): ChatVerificationBadge
    {
        $badge = $this->of($user) ?? throw new ChatException('verification_badge_not_found', 404);
        if ($badge->status !== Badge::Suspended) {
            throw new ChatException('verification_status_invalid', 409);
        }

        return $this->move($admin, $user, $badge->suspended_from ?? Badge::Active, $reason, 'badge_reactivated', fn () => ['suspended_from' => null]);
    }

    /** AV-025: taken away after a review — never by itself because an ad was late (REQ-02-05). */
    public function withdraw(Admin $admin, User $user, string $reason): ChatVerificationBadge
    {
        return $this->move($admin, $user, Badge::Withdrawn, $reason, 'badge_withdrawn', fn () => ['suspended_from' => null]);
    }

    /**
     * AV-025 (scheduled): badges past their end become `expired`; both sides are told.
     */
    public function expireDue(): int
    {
        $count = 0;
        ChatVerificationBadge::query()->whereIn('status', [Badge::Temporary->value, Badge::Active->value])
            ->whereNotNull('expires_at')->where('expires_at', '<=', now())->pluck('user_id')
            ->each(function (int $userId) use (&$count) {
                $user = User::query()->find($userId);
                if ($user !== null && $this->move(null, $user, Badge::Expired, 'expired', 'badge_expired') !== null) {
                    $count++;
                }
            });

        return $count;
    }

    /**
     * The request closed without completing (rejected, cancelled, expired): a temporary badge it gave is
     * taken back (AT-04). A full badge stays — taking it needs a review (withdraw()).
     */
    public function requestClosed(VRequest $request): void
    {
        if ($request->status === VerificationRequestStatus::Completed) {
            return;
        }
        $badge = ChatVerificationBadge::query()->where('user_id', $request->user_id)->where('request_id', $request->id)->first();
        if ($badge !== null && ($badge->status === Badge::Temporary || ($badge->status === Badge::Suspended && $badge->suspended_from === Badge::Temporary))) {
            $this->move(null, $request->user, Badge::Withdrawn, 'request_closed', 'badge_withdrawn');
        }
    }

    // ---------------------------------------------------------------- internals

    /** Turns the badge on (creating it the first time), from the request that earned it. */
    private function activate(VRequest $request, Badge $status, ?Admin $by, string $reason, string $event = 'badge_activated'): ChatVerificationBadge
    {
        $user = $request->user ?? User::query()->findOrFail($request->user_id);
        $type = $request->track === VerificationTrack::Celebrity ? 'celebrity' : 'standard';

        $badge = DB::transaction(function () use ($user, $request, $status, $by, $reason, $type) {
            $badge = ChatVerificationBadge::query()->lockForUpdate()->firstOrNew(['user_id' => $user->id]);
            $from = $badge->exists ? $badge->status : null;
            if ($from === $status && $badge->request_id === $request->id) {
                return $badge; // already on
            }
            if ($from !== null && ! $from->canMoveTo($status)) {
                throw new ChatException('verification_status_invalid', 409, [], ['badge' => $from->value]);
            }
            $badge->fill([
                'status' => $status,
                'type' => $type,
                'request_id' => $request->id,
                'active_from' => $badge->active_from && $from?->shows() ? $badge->active_from : now(),
                'suspended_from' => null,
                'reason' => null,
            ])->save();
            $this->trail($badge, $from, $status, $by, $reason);

            return $badge;
        });

        $this->announce($event, $user, $badge);

        return $badge;
    }

    /**
     * One change of an existing badge, locked; `$work` may set more fields and return the audit's before/after.
     *
     * @param  (\Closure(ChatVerificationBadge): array<string, mixed>)|null  $work
     */
    private function move(?Admin $admin, User $user, ?Badge $to, string $reason, string $event, ?\Closure $work = null): ChatVerificationBadge
    {
        $badge = DB::transaction(function () use ($admin, $user, $to, $reason, $work, $event) {
            $badge = ChatVerificationBadge::query()->lockForUpdate()->where('user_id', $user->id)->first()
                ?? throw new ChatException('verification_badge_not_found', 404);
            $from = $badge->status;
            if ($to !== null && $to !== $from && ! $from->canMoveTo($to)) {
                throw new ChatException('verification_status_invalid', 409, [], ['badge' => $from->value]);
            }
            $extra = $work ? $work($badge) : [];
            $diff = isset($extra['before']) ? $extra : null;
            unset($extra['before'], $extra['after']);
            $badge->forceFill($extra + ['status' => $to ?? $from, 'reason' => $this->isSystemReason($reason) ? null : $reason])->save();
            $this->trail($badge, $from, $badge->status, $admin, $reason, $diff);
            $this->audit->record('chat_verification.'.$event, $badge, $diff['before'] ?? ['status' => $from->value], $diff['after'] ?? ['status' => $badge->status->value], $admin, $reason);

            return $badge;
        });

        $this->announce($event, $user, $badge, $this->isSystemReason($reason) ? '' : $reason);

        return $badge;
    }

    private function trail(ChatVerificationBadge $badge, ?Badge $from, Badge $to, ?Model $actor, ?string $reason, ?array $changes = null): void
    {
        ChatVerificationBadgeEvent::query()->create([
            'badge_id' => $badge->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_type' => $actor instanceof Admin ? 'admin' : 'system',
            'actor_id' => $actor?->getKey(),
            'reason' => $reason,
            'changes' => $changes,
        ]);
    }

    /** Both sides are told; the people the account chats with see the ✔ change live (AV-02A). */
    private function announce(string $event, User $user, ChatVerificationBadge $badge, string $reason = ''): void
    {
        $this->notifier->notify($event, $user, $badge, ['reason' => $reason]);

        $shows = $badge->shows();
        DB::afterCommit(function () use ($user, $shows) {
            $peers = ChatParticipant::query()
                ->whereIn('conversation_id', ChatParticipant::query()->of($user)->whereNull('left_at')
                    ->whereHas('conversation', fn ($q) => $q->where('type', ConversationType::Direct->value))
                    ->select('conversation_id'))
                ->whereNot(fn ($q) => $q->where('participant_type', ParticipantType::aliasFor($user))->where('participant_id', $user->getKey()))
                ->get(['participant_type', 'participant_id'])
                ->map(fn ($p) => [$p->participant_type, (int) $p->participant_id])
                ->all();
            $payload = ['participant' => ParticipantType::key($user), 'user_id' => $user->id, 'is_verified' => $shows];
            $this->broadcaster->toAccounts([['user', $user->id], ...$peers], self::PEER_EVENT, $payload);
        });
    }

    /** Reasons the system writes for itself (not shown to the account as if staff wrote them). */
    private function isSystemReason(string $reason): bool
    {
        return in_array($reason, ['expired', 'request_closed'], true) || str_starts_with($reason, 'timing:');
    }
}
