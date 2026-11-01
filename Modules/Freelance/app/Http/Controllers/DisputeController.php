<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceDispute;
use Modules\Freelance\Models\FreelanceProject;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\Disputes;
use Modules\Freelance\Services\Projects\FreelancerProjects;
use Modules\Freelance\Services\Projects\MilestoneWork;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Disputes for both sides of a project (FL-704), one controller for both prefixes like the chat: the client at
 * `mobile/v1/freelance/projects/{uuid}/…` and the freelancer at `freelance/v1/projects/{uuid}/…`. A project or a dispute that isn't
 * theirs looks like it doesn't exist.
 */
class DisputeController extends Controller
{
    public function __construct(private readonly Disputes $disputes) {}

    /** POST projects/{uuid}/milestones/{milestone}/dispute {reason, details} */
    public function open(Request $request, string $uuid, string $milestone): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(FreelanceDispute::REASONS)],
            'details' => ['required', 'string', 'min:20', 'max:3000'],
        ]);
        [$me, $side, $project] = $this->context($request, $uuid);
        $m = app(MilestoneWork::class)->find($project, $milestone);
        $dispute = $this->disputes->open($m, $me, $side, $data['reason'], $data['details']);

        return ApiResponse::created($this->answer($project, $dispute->uuid, $me, $side), __('freelance.dispute_opened'));
    }

    /** GET projects/{uuid}/disputes/{dispute} */
    public function show(Request $request, string $uuid, string $dispute): JsonResponse
    {
        [$me, $side, $project] = $this->context($request, $uuid);

        return ApiResponse::success($this->answer($project, $dispute, $me, $side), __('api.retrieved'));
    }

    /** POST projects/{uuid}/disputes/{dispute}/evidence (multipart: body, file?) */
    public function evidence(Request $request, string $uuid, string $dispute): JsonResponse
    {
        $request->validate([
            'body' => ['required', 'string', 'min:5', 'max:3000'],
            'file' => ['nullable', 'file', 'max:'.(int) config('freelance.upload_max_kb', 20480), 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,zip', new \Modules\Freelance\Rules\CleanUpload],
        ]);
        [$me, $side, $project] = $this->context($request, $uuid);
        $this->disputes->addEvidence($this->disputes->forParty($project, $dispute, $me, $side), $me, $side, (string) $request->input('body'), $request->file('file'));

        return ApiResponse::created($this->answer($project, $dispute, $me, $side), __('api.created'));
    }

    /** POST projects/{uuid}/disputes/{dispute}/appeal {reason} */
    public function appeal(Request $request, string $uuid, string $dispute): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:20', 'max:2000']]);
        [$me, $side, $project] = $this->context($request, $uuid);
        $this->disputes->appeal($this->disputes->forParty($project, $dispute, $me, $side), $me, $side, $data['reason']);

        return ApiResponse::success($this->answer($project, $dispute, $me, $side), __('freelance.dispute_appealed'));
    }

    /** GET projects/{uuid}/disputes/{dispute}/files/{media} */
    public function file(Request $request, string $uuid, string $dispute, int $media): BinaryFileResponse
    {
        [$me, $side, $project] = $this->context($request, $uuid);
        $file = $this->disputes->file($this->disputes->forParty($project, $dispute, $me, $side), $media);

        return tap(response()->download($file->getPath(), \Modules\Freelance\Services\Projects\MilestoneDeliveries::displayName($file), ['Content-Type' => $file->mime_type]), fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** @return array<string, mixed> */
    private function answer(FreelanceProject $project, string $uuid, Model $me, string $side): array
    {
        return $this->disputes->present($this->disputes->forParty($project, $uuid, $me, $side), $side, "projects/{$project->uuid}/disputes/{$uuid}/files");
    }

    /** @return array{0: Model, 1: 'client'|'freelancer', 2: FreelanceProject} */
    private function context(Request $request, string $uuid): array
    {
        $freelancer = $request->user('freelance_api');
        if ($freelancer instanceof Freelancer) {
            return [$freelancer, 'freelancer', app(FreelancerProjects::class)->find($freelancer, $uuid)];
        }
        $client = $request->user('user_api');

        return [$client, 'client', app(ClientProjects::class)->find($client, $uuid)];
    }
}
