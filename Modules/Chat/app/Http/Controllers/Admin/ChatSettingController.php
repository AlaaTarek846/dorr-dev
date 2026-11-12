<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\RecordingStorage;

/**
 * The chat limits screen (docs/chat-plan.md §10.0): group size, file size, edit window… One
 * row that always exists, so this screen only shows and edits.
 */
class ChatSettingController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-settings', [
            ['view', ['show']],
            ['update', ['update']],
        ]);
    }

    public function show()
    {
        return ApiResponse::success($this->present(ChatSetting::current()), __('api.retrieved'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'max_group_members' => ['sometimes', 'integer', 'min:2', 'max:5000'],
            // Educational and work groups (docs/groups_tasks.md GR-001/002): on/off, and how many one person may own (0 = no limit).
            'education_groups_enabled' => ['sometimes', 'boolean'],
            'work_groups_enabled' => ['sometimes', 'boolean'],
            'max_owned_kind_groups' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            // Days a deleted educational / work group can be brought back (GR-006).
            'group_restore_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            // Extra tools of educational / work groups switched off for everyone (GR-051).
            'group_tools_disabled' => ['sometimes', 'nullable', 'array'],
            'group_tools_disabled.*' => ['string', \Illuminate\Validation\Rule::in(\Modules\Chat\Http\Middleware\EnsureGroupTool::all())],
            'max_file_size_mb' => ['sometimes', 'integer', 'min:1', 'max:2048'],
            'edit_window_minutes' => ['sometimes', 'integer', 'min:0', 'max:10080'],
            'delete_for_everyone_window_minutes' => ['sometimes', 'integer', 'min:0', 'max:43200'],
            'deleted_message_retention_days' => ['sometimes', 'integer', 'min:0', 'max:365'],
            'max_folders' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'max_pinned_messages' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'max_forward_targets' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'story_duration_hours' => ['sometimes', 'integer', 'min:1', 'max:168'],
            'story_video_max_seconds' => ['sometimes', 'integer', 'min:5', 'max:600'],
            'max_call_participants' => ['sometimes', 'integer', 'min:2', 'max:100'],
            'stories_enabled' => ['sometimes', 'boolean'],
            // Public stories on the home page: on/off, and how many one person may have running for free.
            'public_stories_enabled' => ['sometimes', 'boolean'],
            'public_stories_free' => ['sometimes', 'integer', 'min:0', 'max:20'],
            // Hidden members in a group (paid once): on/off, and its price per country (minor units).
            'hidden_members_enabled' => ['sometimes', 'boolean'],
            'hidden_members_prices' => ['sometimes', 'nullable', 'array'],
            'hidden_members_prices.*.country_id' => ['required', 'integer', 'distinct', 'exists:countries,id'],
            'hidden_members_prices.*.amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
            // DORR Moments: occasions, greeting cards, effects — off without touching the chat.
            'moments_enabled' => ['sometimes', 'boolean'],
            'calendar_enabled' => ['sometimes', 'boolean'],
            'calls_enabled' => ['sometimes', 'boolean'],
            // Countries where calls are off (internet calls need a licence in some places).
            'calls_disabled_countries' => ['sometimes', 'nullable', 'array'],
            'calls_disabled_countries.*' => ['integer', 'distinct', 'exists:countries,id'],
            // Call recording (spec 92), only with everyone's yes; files are deleted after these days.
            'call_recording_enabled' => ['sometimes', 'boolean'],
            'call_recording_retention_days' => ['sometimes', 'integer', 'min:1', 'max:365'],
            // AI in the chat: translate, voice to text, summary, suggested replies.
            'ai_enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('calls_disabled_countries', $data)) {
            $data['calls_disabled_countries'] = array_values(array_map('intval', $data['calls_disabled_countries'] ?? [])) ?: null;
        }

        if (array_key_exists('hidden_members_prices', $data)) {
            $data['hidden_members_prices'] = collect($data['hidden_members_prices'] ?? [])
                ->map(fn ($row) => ['country_id' => (int) $row['country_id'], 'amount_minor' => (int) $row['amount_minor']])
                ->values()->all() ?: null;
        }

        $setting = ChatSetting::query()->firstOrCreate([]);
        $setting->update($data);

        return ApiResponse::success($this->present($setting->refresh()), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatSetting $setting): array
    {
        return [
            'calls_disabled_countries' => array_values($setting->calls_disabled_countries ?? []),
            'hidden_members_enabled' => $setting->hidden_members_enabled ?? true,
            'education_groups_enabled' => $setting->education_groups_enabled ?? true,
            'work_groups_enabled' => $setting->work_groups_enabled ?? true,
            'max_owned_kind_groups' => (int) ($setting->max_owned_kind_groups ?? 0),
            'group_restore_days' => (int) ($setting->group_restore_days ?? 30),
            'group_tools_disabled' => array_values($setting->group_tools_disabled ?? []),
            'group_tools' => \Modules\Chat\Http\Middleware\EnsureGroupTool::TOOLS,
            'hidden_members_prices' => array_values($setting->hidden_members_prices ?? []),
            'call_recording_enabled' => $setting->call_recording_enabled ?? true,
            'call_recording_retention_days' => $setting->callRecordingRetentionDays(),
            // Read only: recordings need the bucket keys in .env (CHAT_RECORDINGS_*).
            'call_recording_storage_ready' => app(RecordingStorage::class)->configured(),
        ] + $setting->only((new ChatSetting)->getFillable());
    }
}
