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
 * FS-602: `POST skill-tests/{skill}/challenge` {target_level, link?, note} (the practical challenge for advanced / expert) and
 * `POST skill-challenges/{uuid}/withdraw`.
 */
class FreelancerSkillTestController extends Controller
{
    public function __construct(
        private readonly FreelanceSkillTests $tests,
        private readonly FreelanceSkillBadges $badges,
        private readonly \Modules\Freelance\Services\Skills\FreelanceSkillChallenges $challenges,
    ) {}

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

    public function challenge(Request $request, int $skill): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');
        $data = $request->validate([
            'target_level' => ['required', 'string', 'in:'.implode(',', \Modules\Freelance\Models\FreelanceSkillChallenge::TARGETS)],
            'link' => ['required', 'url:http,https', 'max:255'],
            'note' => ['required', 'string', 'min:20', 'max:'.\Modules\Freelance\Services\Skills\FreelanceSkillChallenges::NOTE_MAX],
        ]);
        $c = $this->challenges->submit($me, $skill, $data);

        return ApiResponse::created(['challenge' => $this->challenges->present($c, false)], __('freelance.skill_challenge_sent'));
    }

    public function withdrawChallenge(Request $request, string $uuid): JsonResponse
    {
        /** @var Freelancer $me */
        $me = $request->user('freelance_api');

        return ApiResponse::success(['challenge' => $this->challenges->present($this->challenges->withdraw($me, $uuid), false)], __('api.updated'));
    }
}
