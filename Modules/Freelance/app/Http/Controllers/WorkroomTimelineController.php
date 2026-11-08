<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\Freelance\Services\Workroom\WorkroomTimeline;

/**
 * The Workroom's timeline (FS-150c), reading only, for both prefixes (`mobile/v1/freelance/projects/{uuid}/workroom/timeline` and
 * `freelance/v1/projects/{uuid}/workroom/timeline`): what happened on the project, newest first, as far as the person's side may see.
 * Every role of the client's team reads it (`view`).
 */
class WorkroomTimelineController extends Controller
{
    public function __construct(
        private readonly WorkroomAccess $access,
        private readonly WorkroomTimeline $timeline,
    ) {}

    /** GET .../workroom/timeline[?kind=task|file|comment|decision|agreement|milestone|dispute|change|review|rights&mine=1&per_page=1..100&page=] */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $f = $request->validate([
            'kind' => ['nullable', 'string', 'alpha', 'max:20'],
            'mine' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $c = $this->access->context($request, $uuid);
        $page = $this->timeline->list($c, $f, 30);

        return ApiResponse::success(['events' => $page->getCollection()->values()], __('api.retrieved'), 200, ApiPaginator::meta($page));
    }
}
