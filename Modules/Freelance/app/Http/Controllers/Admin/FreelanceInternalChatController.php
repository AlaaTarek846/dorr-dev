<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Freelance\Models\FreelanceDispute as Dispute;
use Modules\Freelance\Services\FreelancerTeam\TeamChat;

/**
 * FS-150d: the staff read a team's internal chat about a disputed project - only while the dispute is being decided (open or appealed), only
 * that project's threads (never the team's general thread or another project's), only reading, and only with their own permission
 * (`freelance-internal-chat.view`, given apart from the disputes desk). Every reading is written to the audit log
 * (`freelance.internal_chat.viewed`, the admin and the dispute) and shows on the audit page; the team's Owners are told the first time.
 */
class FreelanceInternalChatController extends Controller implements HasMiddleware
{
    public const ACTION = 'freelance.internal_chat.viewed';

    public function __construct(private readonly TeamChat $chat, private readonly AuditLogger $audit) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-internal-chat', [
            ['view', ['show']],
        ]);
    }

    /** GET freelance-disputes/{uuid}/internal-chat */
    public function show(string $uuid): JsonResponse
    {
        $dispute = Dispute::query()->where('uuid', $uuid)->with('project:id,uuid,title')->firstOrFail();
        $threads = $this->chat->forDispute($dispute);

        $first = ! AuditLog::query()->where('action', self::ACTION)->where('subject_type', $dispute->getMorphClass())->where('subject_id', $dispute->getKey())->exists();
        $this->audit->record(self::ACTION, $dispute, null, [
            'project' => $dispute->project?->uuid,
            'threads' => count($threads),
            'messages' => array_sum(array_map(fn (array $t) => count($t['messages']), $threads)),
        ]);
        if ($first && $threads !== []) {
            $this->chat->tellOwnersOfReview($dispute);
        }

        return ApiResponse::success([
            'dispute' => $dispute->uuid,
            'project' => ['uuid' => $dispute->project?->uuid, 'title' => $dispute->project?->title],
            'threads' => $threads,
        ], __('api.retrieved'));
    }
}
