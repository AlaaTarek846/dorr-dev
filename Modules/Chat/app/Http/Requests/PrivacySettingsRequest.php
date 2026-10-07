<?php

namespace Modules\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Chat\Enums\PrivacyAudience;

class PrivacySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The settings to save, with the old on / off switch turned into the new levels.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        $data = $this->validated();

        if (array_key_exists('notification_preview', $data)) {
            $data['notification_privacy'] ??= $data['notification_preview'] ? 'all' : 'none';
            unset($data['notification_preview']);
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $audience = Rule::enum(PrivacyAudience::class);

        return [
            'last_seen' => ['sometimes', $audience],
            'profile_photo' => ['sometimes', $audience],
            'who_can_message' => ['sometimes', $audience],
            'who_can_add_to_groups' => ['sometimes', $audience],
            'who_can_call' => ['sometimes', $audience],
            // Who may send me an urgent message (it gets through my mute).
            'who_can_urgent' => ['sometimes', $audience],
            // Privacy mode every day between two times (null turns the schedule off).
            'privacy_schedule' => ['sometimes', 'nullable', 'array'],
            'privacy_schedule.from' => ['required_with:privacy_schedule', 'date_format:H:i'],
            'privacy_schedule.to' => ['required_with:privacy_schedule', 'date_format:H:i'],
            'privacy_schedule.days' => ['sometimes', 'array'],
            'privacy_schedule.days.*' => ['integer', 'between:0,6'],
            'privacy_schedule.timezone' => ['sometimes', 'timezone:all'],
            // Smart quiet (spec 115): same shape; scope = every chat or groups only.
            'quiet_schedule' => ['sometimes', 'nullable', 'array'],
            'quiet_schedule.from' => ['required_with:quiet_schedule', 'date_format:H:i'],
            'quiet_schedule.to' => ['required_with:quiet_schedule', 'date_format:H:i'],
            'quiet_schedule.days' => ['sometimes', 'array'],
            'quiet_schedule.days.*' => ['integer', 'between:0,6'],
            'quiet_schedule.timezone' => ['sometimes', 'timezone:all'],
            'quiet_scope' => ['sometimes', \Illuminate\Validation\Rule::in(['all', 'groups'])],
            'read_receipts' => ['sometimes', 'boolean'],
            'block_screenshots' => ['sometimes', 'boolean'],
            // What a chat push shows: all (name and text) · name (name, "New message") · none.
            'notification_privacy' => ['sometimes', Rule::in(['all', 'name', 'none'])],
            // Older apps: the on / off switch (on = all, off = none).
            'notification_preview' => ['sometimes', 'boolean'],
        ];
    }
}
