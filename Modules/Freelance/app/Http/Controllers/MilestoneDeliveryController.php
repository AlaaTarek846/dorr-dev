<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\FreelancerProjects;
use Modules\Freelance\Services\Projects\MilestoneDeliveries;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The deliveries of a part (FL-601 / FL-602) and the client's checklist (FL-604), one controller for both prefixes (the freelancer at
 * `freelance/v1/projects/{uuid}/milestones/…`, the client at `mobile/v1/freelance/projects/{uuid}/milestones/…`). Only the two people of
 * the agreement see a delivery; anyone else gets 404.
 */
class MilestoneDeliveryController extends Controller
{
    public function __construct(private readonly MilestoneDeliveries $deliveries) {}

    /** GET …/deliveries — newest first, with the files and the changes asked on each. */
    public function index(Request $request, string $uuid, string $milestone): JsonResponse
    {
        [$m, $side] = $this->context($request, $uuid, $milestone);

        return ApiResponse::success($this->deliveries->list($m, "projects/{$uuid}/milestones/{$milestone}/deliveries"), __('api.retrieved'));
    }

    /**
     * GET …/deliveries/{delivery}/files/{media} — an image is shown in place, anything else is a download; nothing is ever opened or
     * run by the server, and the browser is told not to guess the type.
     */
    public function file(Request $request, string $uuid, string $milestone, string $delivery, int $media): BinaryFileResponse
    {
        [$m] = $this->context($request, $uuid, $milestone);
        $file = $this->deliveries->file($m, $delivery, $media);
        $inline = in_array($file->mime_type, MilestoneDeliveries::IMAGE_MIMES, true);

        $response = response()->download($file->getPath(), MilestoneDeliveries::displayName($file), ['Content-Type' => $file->mime_type, 'X-Content-Type-Options' => 'nosniff'], $inline ? 'inline' : 'attachment');

        return tap($response, fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** PUT …/checklist {items: [text, …]} — the client, before the work starts. */
    public function setChecklist(Request $request, string $uuid, string $milestone): JsonResponse
    {
        $data = $request->validate(['items' => ['present', 'array', 'max:'.MilestoneDeliveries::MAX_CHECKS], 'items.*' => ['required', 'string', 'max:200']]);
        [$m, $side] = $this->context($request, $uuid, $milestone);
        abort_unless($side === 'client', 403);
        $this->deliveries->setChecks($m, $data['items']);

        return ApiResponse::success($this->deliveries->checks($m), __('api.updated'));
    }

    /** POST …/checklist/{item}/check|uncheck — the client, while a delivery is being reviewed. */
    public function tick(Request $request, string $uuid, string $milestone, string $item, string $state): JsonResponse
    {
        [$m, $side] = $this->context($request, $uuid, $milestone);
        abort_unless($side === 'client', 403);
        $this->deliveries->tick($m, $item, $state === 'check');

        return ApiResponse::success($this->deliveries->checks($m), __('api.updated'));
    }

    /** @return array{0: Milestone, 1: 'client'|'freelancer'} */
    private function context(Request $request, string $uuid, string $milestone): array
    {
        $freelancer = $request->user('freelance_api');
        if ($freelancer instanceof Freelancer) {
            $project = app(FreelancerProjects::class)->find($freelancer, $uuid);
            $column = 'freelancer_id';
            $id = $freelancer->id;
            $side = 'freelancer';
        } else {
            /** @var User $client */
            $client = $request->user('user_api');
            $project = app(ClientProjects::class)->find($client, $uuid);
            $column = 'client_id';
            $id = $client->id;
            $side = 'client';
        }
        $m = Milestone::query()->where('project_id', $project->id)->where('uuid', $milestone)
            ->whereHas('agreement', fn ($q) => $q->where($column, $id))->with('agreement')->firstOrFail();

        return [$m, $side];
    }
}
