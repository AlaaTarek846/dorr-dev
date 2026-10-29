<?php

namespace Modules\Chat\Models\Verification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Modules\Chat\Enums\VerificationActivationTiming;
use Modules\Chat\Enums\VerificationTrack;

/**
 * Account verification's settings — one row, edited from the admin dashboard (AV-003). Read
 * everywhere through current(), cached and forgotten on every save. The column defaults are the
 * owner's starting values; nothing here is hard-coded in the services.
 */
class ChatVerificationSetting extends Model
{
    private const CACHE_KEY = 'chat.verification.settings';

    /** When the admin never set the renewal reminders: a week before, and the day before. */
    public const DEFAULT_RENEWAL_REMINDER_DAYS = [7, 1];

    protected $fillable = [
        'paid_enabled',
        'celebrity_enabled',
        'review_deadline_days',
        'completion_deadline_days',
        'deadline_warning_hours',
        'escalation_after_hours',
        'paid_activation',
        'celebrity_activation',
        'renewal_reminder_days',
        'subscription_grace_days',
        'exemption_default_type',
        'exemption_default_months',
        'exemption_warning_days',
        'exemption_grace_days',
        'ad_default_duration_days',
        'proof_deadline_days',
        'refund_window_hours',
        'refund_on_rejection',
        'refund_on_cancel_before_decision',
        'appeal_window_days',
        'appeal_response_days',
        'referral_requires_phone_verification',
    ];

    protected function casts(): array
    {
        return [
            'paid_enabled' => 'boolean',
            'celebrity_enabled' => 'boolean',
            'review_deadline_days' => 'integer',
            'completion_deadline_days' => 'integer',
            'deadline_warning_hours' => 'integer',
            'escalation_after_hours' => 'integer',
            'renewal_reminder_days' => 'array',
            'subscription_grace_days' => 'integer',
            'exemption_default_months' => 'integer',
            'exemption_warning_days' => 'integer',
            'exemption_grace_days' => 'integer',
            'ad_default_duration_days' => 'integer',
            'proof_deadline_days' => 'integer',
            'refund_window_hours' => 'integer',
            'refund_on_rejection' => 'boolean',
            'refund_on_cancel_before_decision' => 'boolean',
            'appeal_window_days' => 'integer',
            'appeal_response_days' => 'integer',
            'referral_requires_phone_verification' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget(self::CACHE_KEY));
    }

    public function trackEnabled(VerificationTrack $track): bool
    {
        return (bool) match ($track) {
            VerificationTrack::Paid => $this->paid_enabled,
            VerificationTrack::Celebrity => $this->celebrity_enabled,
        };
    }

    /** When the badge turns on by default for a track (REQ-02-02). */
    public function activationFor(VerificationTrack $track): VerificationActivationTiming
    {
        $value = $track === VerificationTrack::Paid ? $this->paid_activation : $this->celebrity_activation;

        return VerificationActivationTiming::tryFrom((string) $value) ?? ($track === VerificationTrack::Paid
            ? VerificationActivationTiming::AfterApprovalAndPayment
            : VerificationActivationTiming::AfterProofApproved);
    }

    /** @return list<int> days before the end, largest first */
    public function renewalReminderDays(): array
    {
        $days = array_map('intval', $this->renewal_reminder_days ?? self::DEFAULT_RENEWAL_REMINDER_DAYS);
        rsort($days);

        return array_values(array_unique($days));
    }

    public static function current(): self
    {
        return Cache::rememberForever(self::CACHE_KEY, fn () => self::query()->firstOrCreate([])->refresh());
    }
}
