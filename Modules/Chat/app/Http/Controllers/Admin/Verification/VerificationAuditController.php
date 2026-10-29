<?php

namespace Modules\Chat\Http\Controllers\Admin\Verification;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Admin\Models\Admin;
use Modules\Chat\Http\Middleware\EnsureVerificationPermission;
use Modules\Chat\Models\Verification\ChatVerificationNotification;

/**
 * Admin → Chat → Verification → Audit (AV-005, AV-006): the sensitive decisions — who, what, the
 * value before and after, when and why (REQ-09-04), staff turned away for lack of a permission
 * (AT-09) — and every notification a verification event sent (REQ-09-07). Read-only.
 */
class VerificationAuditController extends Controller implements HasMiddleware
{
    /** Every account-verification action starts with this. */
    public const PREFIX = 'chat_verification.';

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return EnsureVerificationPermission::map('chat-verification-audit', [
            ['view', ['index', 'notifications']],
        ]);
    }

    /** GET chat-verification/audit[?action=&admin_id=&subject_type=&subject_id=&from=&to=&per_page=] — newest first. */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'action' => ['nullable', 'string', 'max:64'],
            'admin_id' => ['nullable', 'integer'],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = AuditLog::query()
            ->where('action', 'like', self::PREFIX.'%')
            ->when(isset($f['action']), fn ($q) => $q->where('action', self::PREFIX.$f['action']))
            ->when(isset($f['admin_id']), fn ($q) => $q->where('actor_type', 'admin')->where('actor_id', $f['admin_id']))
            ->when(isset($f['subject_type']), fn ($q) => $q->where('subject_type', $f['subject_type']))
            ->when(isset($f['subject_id']), fn ($q) => $q->where('subject_id', $f['subject_id']))
            ->when(isset($f['from']), fn ($q) => $q->where('created_at', '>=', $f['from']))
            ->when(isset($f['to']), fn ($q) => $q->where('created_at', '<=', $f['to'].' 23:59:59'))
            ->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 20));

        $admins = Admin::query()->whereIn('id', $page->getCollection()->where('actor_type', 'admin')->pluck('actor_id')->filter()->unique())
            ->get(['id', 'name'])->keyBy('id');

        return ApiResponse::success(
            $page->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'action' => substr($log->action, strlen(self::PREFIX)),
                'actor' => $log->actor_type === 'admin'
                    ? ['type' => 'admin', 'id' => $log->actor_id, 'name' => $admins->get($log->actor_id)?->name]
                    : ['type' => $log->actor_type, 'id' => null, 'name' => null],
                'subject_type' => $log->subject_type,
                'subject_id' => $log->subject_id,
                'before' => $log->before,
                'after' => $log->after,
                'reason' => $log->reason,
                'ip' => $log->ip,
                'created_at' => $log->created_at?->toIso8601String(),
            ])->values(),
            __('api.retrieved'), 200,
            ApiPaginator::meta($page) + [
                'actions' => AuditLog::query()->where('action', 'like', self::PREFIX.'%')->distinct()->orderBy('action')->pluck('action')
                    ->map(fn (string $a) => substr($a, strlen(self::PREFIX)))->values(),
            ],
        );
    }

    /** GET chat-verification/notifications[?event=&audience=&status=&subject_type=&subject_id=&per_page=] — newest first. */
    public function notifications(Request $request): JsonResponse
    {
        $f = $request->validate([
            'event' => ['nullable', 'string', 'max:64'],
            'audience' => ['nullable', Rule::in(['user', 'admin'])],
            'status' => ['nullable', Rule::in(['sent', 'failed'])],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $page = ChatVerificationNotification::query()
            ->when(isset($f['event']), fn ($q) => $q->where('event', $f['event']))
            ->when(isset($f['audience']), fn ($q) => $q->where('audience', $f['audience']))
            ->when(isset($f['status']), fn ($q) => $q->where('status', $f['status']))
            ->when(isset($f['subject_type']), fn ($q) => $q->where('subject_type', $f['subject_type']))
            ->when(isset($f['subject_id']), fn ($q) => $q->where('subject_id', $f['subject_id']))
            ->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success(
            $page->getCollection()->map(fn (ChatVerificationNotification $n) => [
                'id' => $n->id,
                'event' => $n->event,
                'audience' => $n->audience,
                'recipient_type' => $n->recipient_type,
                'recipient_id' => $n->recipient_id,
                'subject_type' => $n->subject_type,
                'subject_id' => $n->subject_id,
                'push' => $n->push,
                'status' => $n->status,
                'error' => $n->error,
                'created_at' => $n->created_at?->toIso8601String(),
            ])->values(),
            __('api.retrieved'), 200,
            ApiPaginator::meta($page),
        );
    }
}
