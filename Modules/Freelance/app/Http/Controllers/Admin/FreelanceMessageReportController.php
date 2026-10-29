<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceMessageReport as Report;
use Modules\Freelance\Services\Chat\MessageReports;

/**
 * The reported chat messages (FL-709): the open ones first, the text as it was when reported, and the decision (dismiss, or uphold -
 * optionally hiding the message from the room). Information for a person; nothing restricts an account from here (FL-708).
 */
class FreelanceMessageReportController extends Controller implements HasMiddleware
{
    public function __construct(private readonly MessageReports $reports) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-message-reports', [
            ['view', ['index', 'file']],
            ['update', ['decide']],
        ]);
    }

    /** GET freelance-message-reports[?status=open|dismissed|upheld&reason=&per_page=] — `counts` per status. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([Report::OPEN, Report::DISMISSED, Report::UPHELD])],
            'reason' => ['nullable', Rule::in(MessageReports::REASONS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Report::query()->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))->when(isset($f['reason']), fn ($q) => $q->where('reason', $f['reason']))
            ->with(['message', 'conversation.client', 'conversation.freelancer'])
            ->orderByRaw("case status when 'open' then 0 else 1 end")->orderByDesc('id')->paginate((int) ($f['per_page'] ?? 20));
        $counts = Report::query()->select('status', DB::raw('count(*) as n'))->groupBy('status')->pluck('n', 'status');

        return ApiResponse::success($page->getCollection()->map(fn (Report $r) => $this->reports->present($r))->values(), __('api.retrieved'), 200, \App\Support\Api\ApiPaginator::meta($page) + [
            'counts' => ['open' => (int) ($counts['open'] ?? 0), 'dismissed' => (int) ($counts['dismissed'] ?? 0), 'upheld' => (int) ($counts['upheld'] ?? 0)],
        ]);
    }

    /** GET freelance-message-reports/{uuid}/file - the file that was in the reported message, as it was when reported. */
    public function file(string $uuid): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        $file = Report::query()->where('uuid', $uuid)->firstOrFail()->getSingleMedia('evidence');
        abort_if($file === null, 404);

        return tap(response()->download($file->getPath(), \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($file), ['Content-Type' => $file->mime_type]), fn ($r) => $r->setPrivate());
    }

    /** POST freelance-message-reports/{uuid}/decide {decision: dismiss|uphold, hide_message?: bool, note?} */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['dismiss', 'uphold'])], 'hide_message' => ['nullable', 'boolean'], 'note' => ['nullable', 'string', 'max:500']]);
        $report = Report::query()->where('uuid', $uuid)->firstOrFail();
        $done = $this->reports->decide($report, $request->user('admin_api'), $data['decision'], $data['note'] ?? null, (bool) ($data['hide_message'] ?? false));
        app(\App\Services\General\AuditLogger::class)->record('freelance.message_report.decided', $report, null, ['decision' => $data['decision'], 'hide_message' => (bool) ($data['hide_message'] ?? false)]);

        return ApiResponse::success($this->reports->present($done->load(['message', 'conversation.client', 'conversation.freelancer'])), __('api.updated'));
    }
}
