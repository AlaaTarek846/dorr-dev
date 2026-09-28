<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatConversation;
use Modules\Chat\Models\ChatReportType;
use Modules\Chat\Models\ChatTheme;
use Modules\Chat\Services\ChatReportService;
use Modules\Chat\Services\ChatThemeService;

/**
 * What the admin made for people to pick from: chat themes and report reasons, and sending a report.
 * (Picking a theme for a chat is `PATCH conversations/{id}/settings` with `theme_id`.)
 */
class ThemeReportController extends Controller
{
    public function themes(ChatThemeService $themes)
    {
        return ApiResponse::success($themes->active()->map(fn (ChatTheme $t) => $t->present())->values(), __('api.retrieved'));
    }

    public function reportTypes()
    {
        $types = ChatReportType::query()->active()->with('translation')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ChatReportType $type) => ['id' => $type->id, 'name' => $type->translatedName()]);

        return ApiResponse::success($types, __('api.retrieved'));
    }

    public function report(Request $request, ChatConversation $conversation, ChatReportService $reports)
    {
        $data = $request->validate([
            'report_type_id' => ['required', 'integer'],
            'details' => ['nullable', 'string', 'max:1000'],
            'participant_ids' => ['nullable', 'array', 'max:50'],
            'participant_ids.*' => ['integer'],
            'block' => ['nullable', 'boolean'],
            'leave' => ['nullable', 'boolean'],
        ]);

        $report = $reports->report($request->user(), $conversation, $data);

        return ApiResponse::created(['id' => $report->id], __('chat.report_sent'));
    }
}
