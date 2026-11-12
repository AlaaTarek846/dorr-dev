<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Services\Ai\FreelanceAi;
use Modules\Freelance\Services\Ai\ProjectBuilder;
use Modules\Freelance\Services\Ai\ProjectSummary;
use Modules\Freelance\Services\Ai\SmartMatching;
use Modules\Freelance\Services\Features\FreelanceCountry;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\User\Models\User;

/**
 * DORR AI for the freelance client (FS-401 / FS-501): the project written from an idea, and the best people for a project. Both are
 * DORR AI tools on the client's DORR AI plan; each has its own switch per country (`ai_project_builder`, `smart_matching`).
 * FS-801: the AI Project Manager's summary of a running project (`ai_project_manager`).
 */
class ClientAiController extends Controller
{
    public function __construct(private readonly FreelanceAi $ai, private readonly FreelanceFeatures $features) {}

    /** GET projects/ai/status — which of the two are on here, and whether the plan allows AI now (to lock the buttons first). */
    public function status(Request $request): JsonResponse
    {
        $me = $this->me($request);
        $country = FreelanceCountry::of($me);
        $entitlement = $this->ai->available() ? $this->ai->entitlement($me) : ['allowed' => false, 'reason' => 'unavailable'];

        return ApiResponse::success([
            'builder' => $country !== null && $this->ai->available() && $this->features->allows($country, 'ai_project_builder'),
            'matching' => $country !== null && $this->ai->available() && $this->features->allows($country, 'smart_matching'),
            'manager' => $country !== null && $this->ai->available() && $this->features->allows($country, 'ai_project_manager'),
            'allowed' => (bool) $entitlement['allowed'],
            'reason' => $entitlement['reason'] ?? null,
        ], __('api.retrieved'));
    }

    /** POST projects/ai/build {idea, answers?: [{question, answer}]} — a draft to fill the form with; nothing is saved. */
    public function build(Request $request, ProjectBuilder $builder): JsonResponse
    {
        $data = $request->validate([
            'idea' => ['required', 'string', 'min:10', 'max:2000'],
            'answers' => ['nullable', 'array', 'max:5'],
            'answers.*.question' => ['required', 'string', 'max:300'],
            'answers.*.answer' => ['nullable', 'string', 'max:500'],
        ]);
        $me = $this->me($request);
        $country = FreelanceCountry::of($me) ?? throw new FreelanceException('ai_unavailable', 503);
        $answers = array_values(array_filter((array) ($data['answers'] ?? []), fn ($a) => trim((string) ($a['answer'] ?? '')) !== ''));

        return ApiResponse::success($builder->build($me, $country, $data['idea'], $answers), __('api.retrieved'));
    }

    /** GET projects/{uuid}/matches?refresh=1 — the best freelancers and teams for my project, with the reasons. */
    public function matches(Request $request, string $uuid, ClientProjects $projects, SmartMatching $matching): JsonResponse
    {
        $fresh = $request->boolean('refresh');
        $project = $projects->find($this->me($request), $uuid);

        return ApiResponse::success($matching->for($project, $this->me($request), $fresh), __('api.retrieved'));
    }

    /**
     * GET projects/{uuid}/workroom/ai/summary?days=7&refresh=1 — FS-801: what happened on my running project lately, in a few lines,
     * with the next step for each side. Reads only; the owner's plan (a team member does not reach it).
     */
    public function summary(Request $request, string $uuid, WorkroomAccess $access, ProjectSummary $summary): JsonResponse
    {
        $data = $request->validate(['days' => ['nullable', 'integer', 'in:'.implode(',', ProjectSummary::PERIODS)]]);
        $context = $access->context($request, $uuid);

        return ApiResponse::success($summary->for($context->agreement, $this->me($request), (int) ($data['days'] ?? 7), $request->boolean('refresh')), __('api.retrieved'));
    }

    private function me(Request $request): User
    {
        return $request->user('user_api');
    }
}
