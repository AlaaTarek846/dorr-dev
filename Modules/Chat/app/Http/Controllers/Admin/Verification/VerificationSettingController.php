<?php

namespace Modules\Chat\Http\Controllers\Admin\Verification;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\VerificationActivationTiming;
use Modules\Chat\Enums\VerificationExemptionType;
use Modules\Chat\Http\Middleware\EnsureVerificationPermission;
use Modules\Chat\Models\Verification\ChatVerificationSetting;

/**
 * Admin → Chat → Verification → Settings (AV-003, REQ-09-02): the deadlines, the activation timing of
 * each track, renewal and exemption periods, refunds and appeals. One row that always exists; a
 * change reaches what comes next and is written to the audit log with its before and after.
 */
class VerificationSettingController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return EnsureVerificationPermission::map('chat-verification-settings', [
            ['view', ['show']],
            ['update', ['update']],
        ]);
    }

    public function show(): JsonResponse
    {
        return ApiResponse::success($this->present(ChatVerificationSetting::current()), __('api.retrieved'));
    }

    public function update(Request $request, AuditLogger $audit): JsonResponse
    {
        $days = ['sometimes', 'integer', 'min:1', 'max:365'];
        $data = $request->validate([
            'paid_enabled' => ['sometimes', 'boolean'],
            'celebrity_enabled' => ['sometimes', 'boolean'],
            'review_deadline_days' => $days,
            'completion_deadline_days' => $days,
            'deadline_warning_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
            'escalation_after_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'paid_activation' => ['sometimes', Rule::in(VerificationActivationTiming::values())],
            'celebrity_activation' => ['sometimes', Rule::in(VerificationActivationTiming::values())],
            'renewal_reminder_days' => ['sometimes', 'array', 'min:1', 'max:5'],
            'renewal_reminder_days.*' => ['integer', 'distinct', 'min:1', 'max:90'],
            'subscription_grace_days' => ['sometimes', 'integer', 'min:0', 'max:60'],
            'exemption_default_type' => ['sometimes', Rule::in(VerificationExemptionType::values())],
            'exemption_default_months' => ['sometimes', 'integer', 'min:1', 'max:60'],
            'exemption_warning_days' => $days,
            'exemption_grace_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'ad_default_duration_days' => $days,
            'proof_deadline_days' => $days,
            'refund_window_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'refund_on_rejection' => ['sometimes', 'boolean'],
            'refund_on_cancel_before_decision' => ['sometimes', 'boolean'],
            'appeal_window_days' => $days,
            'appeal_response_days' => $days,
            'referral_requires_phone_verification' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('renewal_reminder_days', $data)) {
            $data['renewal_reminder_days'] = array_values(array_map('intval', $data['renewal_reminder_days']));
        }

        $setting = ChatVerificationSetting::query()->firstOrCreate([])->refresh();
        $before = $this->present($setting);
        $setting->update($data);
        $after = $this->present($setting->refresh());

        $changed = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $audit->record('chat_verification.settings_updated', $setting, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
        }

        return ApiResponse::success($after, __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(ChatVerificationSetting $setting): array
    {
        return ['renewal_reminder_days' => $setting->renewalReminderDays()]
            + $setting->only(array_diff((new ChatVerificationSetting)->getFillable(), ['renewal_reminder_days']));
    }
}
