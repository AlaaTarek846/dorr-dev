<?php

namespace Modules\Rides\Http\Controllers\Lessons;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\LessonPack;
use Modules\Rides\Models\LessonSession;
use Modules\Rides\Services\Lessons\DrivingLessonService;

/** The trainer's side of driving lessons (O-26b): my packs, the lessons asked for, accept, done. */
class DriverLessonController extends Controller
{
    public function __construct(private readonly DrivingLessonService $lessons) {}

    public function packs(Request $request): JsonResponse
    {
        return ApiResponse::success($this->lessons->packsOf($this->driver($request))->map(fn (LessonPack $p) => LessonPresenter::pack($p))->values(), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'transmission' => ['required', Rule::in(['automatic', 'manual'])],
            'lessons' => ['required', 'integer', 'min:1', 'max:60'],
            'minutes_per_lesson' => ['required', 'integer', 'min:15', 'max:480'],
            'price_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'own_car' => ['nullable', 'boolean'],
            'area' => ['required', 'array'],
            'area.lat' => ['required', 'numeric', 'between:-90,90'],
            'area.lng' => ['required', 'numeric', 'between:-180,180'],
            'area.address' => ['required', 'string', 'max:255'],
        ]);

        return ApiResponse::success(LessonPresenter::pack($this->lessons->publish($this->driver($request), $data)), __('rides.messages.lessons_published'), 201);
    }

    public function status(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'paused'])]]);
        $pack = LessonPack::query()->where('uuid', $uuid)->first() ?? throw new RidesException('lessons_not_found', 404);

        return ApiResponse::success(LessonPresenter::pack($this->lessons->setStatus($this->driver($request), $pack, $data['status'])), __('api.updated'));
    }

    public function sessions(Request $request): JsonResponse
    {
        return ApiResponse::success($this->lessons->sessionsOf($this->driver($request))->map(fn (LessonSession $s) => LessonPresenter::session($s, true))->values(), __('api.retrieved'));
    }

    public function answer(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['approve' => ['required', 'boolean']]);

        return ApiResponse::success(LessonPresenter::session($this->lessons->answer($this->driver($request), $this->session($uuid), (bool) $data['approve']), true), __('api.updated'));
    }

    public function done(Request $request, string $uuid): JsonResponse
    {
        return ApiResponse::success(LessonPresenter::session($this->lessons->done($this->driver($request), $this->session($uuid)), true), __('rides.messages.lessons_done'));
    }

    private function session(string $uuid): LessonSession
    {
        return LessonSession::query()->where('uuid', $uuid)->with(['enrollment.pack', 'enrollment.user'])->first() ?? throw new RidesException('lessons_not_found', 404);
    }

    private function driver(Request $request): Driver
    {
        /** @var Driver */
        return $request->user();
    }
}
