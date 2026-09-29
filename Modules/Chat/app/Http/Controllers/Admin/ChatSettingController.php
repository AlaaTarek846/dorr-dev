<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Chat\Models\ChatSetting;

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
            'calls_enabled' => ['sometimes', 'boolean'],
        ]);

        $setting = ChatSetting::query()->firstOrCreate([]);
        $setting->update($data);

        return ApiResponse::success($this->present($setting->refresh()), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatSetting $setting): array
    {
        return $setting->only((new ChatSetting)->getFillable());
    }
}
