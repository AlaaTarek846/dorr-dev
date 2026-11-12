<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Services\General\MobileRatingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** "Rate the app" for a freelancer - the same rating the DORR app collects (`mobile/v1/ratings`), one per person, shown on the same dashboard page. */
class FreelancerRatingController extends Controller
{
    public function __construct(private readonly MobileRatingService $service) {}

    public function mine(Request $request): JsonResponse
    {
        return $this->service->mine($request->user('freelance_api'), null, null);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stars' => ['required', 'numeric', 'between:1,5', 'multiple_of:0.25'],
            'comment' => ['nullable', 'string', 'min:5', 'max:300'],
        ], ['comment.min' => __('api.rating_comment_length'), 'comment.max' => __('api.rating_comment_length')]);

        return $this->service->submit($request->user('freelance_api'), $data);
    }
}
