<?php

namespace Modules\Freelance\Http\Controllers\Freelancer;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Skills\FreelanceSkillBadges;
use Modules\Freelance\Services\Skills\FreelanceSkillTests;

/**
 * FL-L08, skill tests: `GET skill-tests` (my skills that can be tested and where I stand), `POST skill-tests/{skill}/start` (the questions —
 * texts only), `POST skill-test-attempts/{attempt}/submit` (the answers, marked on the server). Behind the `skill_tests` switch of the country.
 */
class FreelancerSkillTestController extends Controller
{
    public function __construct(private readonly FreelanceSkillTests $tests, private readonly FreelanceSkillBadges $badges) {}

    public function index(Request $request): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');

        return ApiResponse::success($this->tests->overview($me) + ['badges' => $this->badges->of($me, PHP_INT_MAX)], __('api.retrieved'));
    }

    public function start(Request $request, int $skill): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');

        return ApiResponse::success($this->tests->start($me, $skill), __('api.retrieved'));
    }

    public function submit(Request $request, int $attempt): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');
        $data = $request->validate(['answers' => ['present', 'array', 'max:100'], 'answers.*' => ['nullable', 'integer', 'min:0', 'max:20']]);

        return ApiResponse::success($this->tests->submit($me, $attempt, $data['answers']), __('api.retrieved'));
    }
}
