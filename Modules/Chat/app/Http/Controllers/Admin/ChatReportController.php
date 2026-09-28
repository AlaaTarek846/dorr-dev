<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatReport;
use Modules\Chat\Services\ChatReportService;

/**
 * Reports people sent about chats: the list, one report with its copied messages, and the review
 * (status + a note). The admin only sees what the reporter could see and chose to send.
 */
class ChatReportController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ChatReportService $reports) {}

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-reports', [
            ['view', ['index', 'show']],
            ['update', ['update']],
        ]);
    }

    public function index(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(ChatReport::STATUSES)],
            'report_type_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ChatReport::query()
            ->with(['type.translations', 'reportedUsers', 'conversation.group', 'reviewer'])
            ->withCount('messages')
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['report_type_id'] ?? null, fn ($q, $type) => $q->where('report_type_id', $type))
            ->latest('id')
            ->paginate((int) ($filters['per_page'] ?? 15));

        $data = $page->getCollection()->map(fn (ChatReport $report) => $this->reports->present($report))->values();

        return ApiResponse::success($data, __('api.retrieved'), 200, ApiPaginator::meta($page), [
            'pending_count' => ChatReport::query()->where('status', 'pending')->count(),
        ]);
    }

    public function show(ChatReport $chatReport)
    {
        $chatReport->load(['type.translations', 'reportedUsers', 'conversation.group', 'reviewer', 'messages'])->loadCount('messages');

        return ApiResponse::success($this->reports->present($chatReport, true), __('api.retrieved'));
    }

    public function update(Request $request, ChatReport $chatReport)
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ChatReport::STATUSES)],
            'admin_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $chatReport->update($data + [
            'reviewed_by' => $request->user()->id,
            'reviewed_at' => now(),
        ]);

        return $this->show($chatReport->refresh());
    }
}
