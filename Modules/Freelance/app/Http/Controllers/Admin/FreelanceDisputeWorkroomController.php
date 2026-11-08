<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Freelance\Models\FreelanceDispute as Dispute;
use Modules\Freelance\Services\Workroom\DisputeWorkroom;
use Modules\Freelance\Services\Workroom\WorkroomTimeline;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * FS-150f: the staff read the shared Workroom of a disputed project (tasks, files and their versions, notes, decisions) - reading only, with
 * the disputes desk's `view` permission, in any state of the dispute. Opening it and every file taken from it are written to the audit log.
 */
class FreelanceDisputeWorkroomController extends Controller implements HasMiddleware
{
    public const VIEWED = 'freelance.dispute.workroom_viewed';

    public const TIMELINE = 'freelance.dispute.workroom_timeline_viewed';

    public const DOWNLOADED = 'freelance.dispute.workroom_file_downloaded';

    public function __construct(private readonly DisputeWorkroom $room, private readonly WorkroomTimeline $timeline, private readonly AuditLogger $audit) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-disputes', [
            ['view', ['show', 'download', 'timeline']],
        ]);
    }

    /** GET freelance-disputes/{uuid}/workroom */
    public function show(string $uuid): JsonResponse
    {
        $d = Dispute::query()->where('uuid', $uuid)->firstOrFail();
        $data = $this->room->build($d);
        $this->audit->record(self::VIEWED, $d, null, [
            'tasks' => count($data['tasks']), 'files' => count($data['files']), 'comments' => count($data['comments']), 'decisions' => count($data['decisions']),
        ]);

        return ApiResponse::success($data, __('api.retrieved'));
    }

    /** GET freelance-disputes/{uuid}/workroom/timeline - what happened on the project (the Workroom's steps and the agreement's approvals), newest first. */
    public function timeline(string $uuid): JsonResponse
    {
        $d = Dispute::query()->where('uuid', $uuid)->firstOrFail();
        $entries = $this->timeline->forDispute($d);
        $this->audit->record(self::TIMELINE, $d, null, ['entries' => count($entries)]);

        return ApiResponse::success(['dispute' => $uuid, 'entries' => $entries], __('api.retrieved'));
    }

    /** GET freelance-disputes/{uuid}/workroom/files/{file}/versions/{number}/download - the file as it was uploaded. */
    public function download(string $uuid, string $file, int $number): BinaryFileResponse
    {
        $d = Dispute::query()->where('uuid', $uuid)->firstOrFail();
        [$f, $v] = $this->room->version($d, $file, $number);
        $media = $v->stored() ?? abort(404);
        $this->audit->record(self::DOWNLOADED, $d, null, ['file' => $f->uuid, 'version' => $v->number]);

        $response = response()->download($media->getPath(), $v->original_name, ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff'], DisputeWorkroom::inline($media->mime_type) ? 'inline' : 'attachment');

        return tap($response, fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }
}
