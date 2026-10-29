<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceAgreement;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\Agreements;
use Modules\Freelance\Services\Projects\ClientProjects;
use Modules\Freelance\Services\Projects\FreelanceReviews;
use Modules\Freelance\Services\Projects\FreelancerProjects;
use Modules\Freelance\Services\Projects\ReviewObjections;
use Modules\Freelance\Services\Projects\SatisfactionSurveys;
use Illuminate\Validation\Rule;

/**
 * Rating each other after a project (FL-701), for both sides: `GET|POST projects/{uuid}/review` (client at `mobile/v1/freelance`, freelancer
 * at `freelance/v1`), and for the freelancer `GET reviews` — their own numbers and the latest ratings (FL-703).
 */
class ReviewController extends Controller
{
    public function __construct(private readonly FreelanceReviews $reviews) {}

    public function show(Request $request, string $uuid): JsonResponse
    {
        [, $side, $a] = $this->context($request, $uuid);

        return ApiResponse::success($this->reviews->forAgreement($a, $side), __('api.retrieved'));
    }

    /** POST {scores: {criterion: 1..5}, comment?} */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'scores' => ['required', 'array'],
            'scores.*' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:1000'],
        ]);
        [$me, $side, $a] = $this->context($request, $uuid);
        $this->reviews->rate($a, $me, $side, $data['scores'], $data['comment'] ?? null);

        return ApiResponse::created($this->reviews->forAgreement($a->refresh(), $side), __('freelance.review_sent'));
    }

    /** POST {uuid}/review/objection {reason: abusive|false|unfair|other, note} (FL-706) - object to the rating about me, once. */
    public function object(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', Rule::in(ReviewObjections::REASONS)], 'note' => ['required', 'string', 'min:5', 'max:500']]);
        [$me, $side, $a] = $this->context($request, $uuid);
        app(ReviewObjections::class)->object($a, $me, $side, $data['reason'], $data['note']);

        return ApiResponse::created($this->reviews->forAgreement($a->refresh(), $side), __('freelance.objection_sent'));
    }

    /** GET {uuid}/survey (FL-702) - the short satisfaction survey of a finished project, for me. */
    public function survey(Request $request, string $uuid): JsonResponse
    {
        [, $side, $a] = $this->context($request, $uuid);

        return ApiResponse::success(app(SatisfactionSurveys::class)->forAgreement($a, $side), __('api.retrieved'));
    }

    /** POST {uuid}/survey {score: 1..5, reasons?: [], comment?} (FL-702) */
    public function answerSurvey(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'score' => ['required', 'integer', 'min:1', 'max:5'],
            'reasons' => ['nullable', 'array', 'max:8'],
            'reasons.*' => ['string', Rule::in(SatisfactionSurveys::REASONS)],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);
        [$me, $side, $a] = $this->context($request, $uuid);
        $surveys = app(SatisfactionSurveys::class);
        $surveys->answer($a, $me, $side, (int) $data['score'], $data['reasons'] ?? [], $data['comment'] ?? null);

        return ApiResponse::created($surveys->forAgreement($a, $side), __('freelance.survey_sent'));
    }

    /** GET reviews — the freelancer's own numbers and the latest ratings clients gave them. */
    public function mine(Request $request): JsonResponse
    {
        $me = $request->user('freelance_api');

        return ApiResponse::success($this->reviews->stats($me) + ['latest' => $this->reviews->latestAbout($me)], __('api.retrieved'));
    }

    /** @return array{0: Model, 1: 'client'|'freelancer', 2: FreelanceAgreement} */
    private function context(Request $request, string $uuid): array
    {
        $agreements = app(Agreements::class);
        $freelancer = $request->user('freelance_api');
        if ($freelancer instanceof Freelancer) {
            return [$freelancer, 'freelancer', $agreements->forFreelancer(app(FreelancerProjects::class)->find($freelancer, $uuid), $freelancer)];
        }
        $client = $request->user('user_api');

        return [$client, 'client', $agreements->current(app(ClientProjects::class)->find($client, $uuid)) ?? abort(404)];
    }
}
