<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Admin\Models\Admin;

/** What staff did in Freelance (FL-806): the audit log, only the freelance actions, newest first, with who did it. Read only. */
class FreelanceAuditController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-audit', [['view', ['index']]]);
    }

    /** GET freelance-audit[?action=&actor_id=&subject_id=&from=&to=&per_page=] — `action` matches the start (`freelance.dispute`). */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:60'], 'actor_id' => ['nullable', 'integer'], 'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = AuditLog::query()->where('action', 'like', 'freelance%')
            ->when(isset($f['action']), fn ($q) => $q->where('action', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $f['action']).'%'))
            ->when(isset($f['actor_id']), fn ($q) => $q->where('actor_type', 'admin')->where('actor_id', $f['actor_id']))
            ->when(isset($f['subject_id']), fn ($q) => $q->where('subject_id', $f['subject_id']))
            ->when(isset($f['from']), fn ($q) => $q->where('created_at', '>=', $f['from']))
            ->when(isset($f['to']), fn ($q) => $q->where('created_at', '<=', $f['to'].' 23:59:59'))
            ->latest('id')->paginate((int) ($f['per_page'] ?? 30));
        $names = Admin::query()->whereIn('id', $page->getCollection()->where('actor_type', 'admin')->pluck('actor_id')->unique())->pluck('name', 'id');

        return ApiResponse::success($page->getCollection()->map(fn (AuditLog $a) => [
            'id' => $a->id, 'action' => $a->action,
            'actor' => ['type' => $a->actor_type, 'id' => $a->actor_id, 'name' => $a->actor_type === 'admin' ? ($names[$a->actor_id] ?? null) : null],
            'subject' => ['type' => $a->subject_type === null ? null : class_basename($a->subject_type), 'id' => $a->subject_id],
            'before' => $a->before, 'after' => $a->after, 'ip' => $a->ip, 'at' => $a->created_at?->toIso8601String(),
        ])->values(), __('api.retrieved'), 200, ApiPaginator::meta($page));
    }
}
