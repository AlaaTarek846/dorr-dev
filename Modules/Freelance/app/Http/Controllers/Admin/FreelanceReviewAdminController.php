<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\Freelance\Services\Projects\ReviewObjections;
use Modules\Freelance\Services\Projects\SatisfactionSurveys;

/**
 * Ratings and satisfaction for staff (FL-706 / FL-702): the objections to ratings (keep it, hide its comment, or take the rating out
 * of the numbers) and how satisfied people are after a project and why not. Nothing here touches an account.
 */
class FreelanceReviewAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly ReviewObjections $objections, private readonly SatisfactionSurveys $surveys) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-reviews', [
            ['view', ['objections', 'satisfaction']],
            ['update', ['decide']],
        ]);
    }

    /** GET freelance-reviews/objections[?status=open|rejected|comment_hidden|rating_removed&per_page=] — `counts` per status. */
    public function objections(Request $request): JsonResponse
    {
        $f = $request->validate([
            'status' => ['nullable', Rule::in([ReviewObjections::OPEN, ReviewObjections::REJECTED, ReviewObjections::COMMENT_HIDDEN, ReviewObjections::RATING_REMOVED])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $queue = $this->objections->queue($f['status'] ?? null, (int) ($f['per_page'] ?? 20));

        return ApiResponse::success($queue['items'], __('api.retrieved'), 200, ApiPaginator::meta($queue['page']) + ['counts' => $queue['counts']]);
    }

    /** POST freelance-reviews/objections/{uuid}/decide {decision: reject|hide_comment|remove_rating, note?} */
    public function decide(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', Rule::in(['reject', 'hide_comment', 'remove_rating'])], 'note' => ['nullable', 'string', 'max:500']]);

        $done = $this->objections->decide($uuid, $request->user('admin_api'), $data['decision'], $data['note'] ?? null);
        app(\App\Services\General\AuditLogger::class)->record('freelance.review_objection.decided', null, null, ['objection' => $uuid, 'decision' => $data['decision']]);

        return ApiResponse::success($done, __('api.updated'));
    }

    /** GET freelance-reviews/satisfaction[?from=&to=] — the current month by default. */
    public function satisfaction(Request $request): JsonResponse
    {
        $f = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from']]);
        $from = isset($f['from']) ? Carbon::parse($f['from'])->startOfDay() : now()->startOfMonth();
        $to = isset($f['to']) ? Carbon::parse($f['to'])->endOfDay() : now()->endOfDay();

        return ApiResponse::success($this->surveys->summary($from, $to), __('api.retrieved'));
    }
}
