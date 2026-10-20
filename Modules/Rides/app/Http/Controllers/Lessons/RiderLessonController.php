<?php

namespace Modules\Rides\Http\Controllers\Lessons;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\LessonEnrollment;
use Modules\Rides\Models\LessonPack;
use Modules\Rides\Models\LessonSession;
use Modules\Rides\Services\Lessons\DrivingLessonService;
use Modules\User\Models\User;

/** The learner's driving lessons (O-26b): packs near me, buy one, book lessons, cancel, stop. */
class RiderLessonController extends Controller
{
    public function __construct(private readonly DrivingLessonService $lessons) {}

    public function packs(Request $request): JsonResponse
    {
        $data = $request->validate([
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'transmission' => ['nullable', Rule::in(['automatic', 'manual'])],
        ]);
        $packs = $this->lessons->browse($this->learner($request), (float) $data['lat'], (float) $data['lng'], $data['transmission'] ?? null);

        return ApiResponse::success($packs->map(fn (LessonPack $p) => LessonPresenter::pack($p))->values(), __('api.retrieved'));
    }

    public function enroll(Request $request, string $uuid): JsonResponse
    {
        $pack = LessonPack::query()->where('uuid', $uuid)->first() ?? throw new RidesException('lessons_not_found', 404);
        $enrollment = $this->lessons->enroll($this->learner($request), $pack, $request->header('Idempotency-Key'));

        return ApiResponse::success(LessonPresenter::enrollment($enrollment), __('rides.messages.lessons_enrolled'), 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $rows = LessonEnrollment::query()->where('user_id', $this->learner($request)->id)->latest('id')->limit(30)->get();

        return ApiResponse::success($rows->map(fn (LessonEnrollment $e) => LessonPresenter::enrollment($e))->values(), __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(LessonPresenter::enrollment($this->own($request, $uuid)), __('api.retrieved'));
    }

    public function book(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'starts_at' => ['required', 'date'],
            'pickup' => ['required', 'array'],
            'pickup.lat' => ['required', 'numeric', 'between:-90,90'],
            'pickup.lng' => ['required', 'numeric', 'between:-180,180'],
            'pickup.address' => ['required', 'string', 'max:255'],
        ]);
        $this->lessons->book($this->learner($request), $this->own($request, $uuid), $data);

        return ApiResponse::success(LessonPresenter::enrollment($this->own($request, $uuid)), __('rides.messages.lessons_booked'), 201);
    }

    public function cancelSession(Request $request, string $uuid): JsonResponse
    {
        $session = LessonSession::query()->where('uuid', $uuid)->with('enrollment.pack')->first() ?? throw new RidesException('lessons_not_found', 404);
        $this->lessons->cancelSession($this->learner($request), $session);

        return ApiResponse::success(LessonPresenter::enrollment($session->enrollment->refresh()), __('api.updated'));
    }

    public function quit(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(LessonPresenter::enrollment($this->lessons->quit($this->learner($request), $this->own($request, $uuid))), __('rides.messages.lessons_quit'));
    }

    private function own(Request $request, string $uuid): LessonEnrollment
    {
        return LessonEnrollment::query()->where('uuid', $uuid)->where('user_id', $this->learner($request)->id)->first()
            ?? throw new RidesException('lessons_not_found', 404);
    }

    private function learner(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
