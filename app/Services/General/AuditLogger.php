<?php

namespace App\Services\General;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\Models\Admin;

/**
 * Records a sensitive change (docs/rides-structure.md §9): the admin who made it — or `system`
 * for a scheduled job — the subject, and its values before and after.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  string|null  $reason  why, as the staff wrote it (a rejection, a suspension…)
     */
    public function record(string $action, ?Model $subject = null, ?array $before = null, ?array $after = null, ?Model $actor = null, ?string $reason = null): AuditLog
    {
        $actor ??= request()?->user('admin_api');

        return AuditLog::query()->create([
            'actor_type' => $actor instanceof Admin ? 'admin' : 'system',
            'actor_id' => $actor instanceof Admin ? $actor->getKey() : null,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => app()->runningInConsole() ? null : request()?->ip(),
        ]);
    }
}
