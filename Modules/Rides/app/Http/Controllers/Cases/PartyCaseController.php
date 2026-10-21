<?php

namespace Modules\Rides\Http\Controllers\Cases;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\RideCase;
use Modules\Rides\Services\Cases\RideCases;

/**
 * A rider's or a captain's own cases (R-133..R-140, D-229..D-238, D-285..D-292): the same endpoints under
 * `mobile/v1/rides/cases` and `driver/v1/cases`, each person only ever seeing what they opened.
 */
class PartyCaseController extends Controller
{
    public function __construct(private readonly RideCases $cases) {}

    public function options(Request $request): JsonResponse
    {
        return ApiResponse::success($this->cases->options($this->party($request)), __('api.retrieved'));
    }

    public function index(Request $request): JsonResponse
    {
        $party = $this->party($request);
        $page = RideCase::query()->where('reporter_type', RideCases::partyType($party))->where('reporter_id', $party->getKey())
            ->with('ride')->latest('id')->paginate(20);
        $viewer = RideCases::partyType($party);

        return ApiResponse::fromPaginator($page, $page->getCollection()->map(fn (RideCase $c) => $this->summary($this->cases->present($c, $viewer)))->values(), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'max:16'],
            'ride_uuid' => ['nullable', 'uuid'],
            'category' => ['nullable', 'string', 'max:24'],
            'summary' => ['required', 'string', 'min:3', 'max:2000'],
        ]);
        $party = $this->party($request);
        $case = $this->cases->open($party, $data);

        return ApiResponse::success($this->cases->present($case, RideCases::partyType($party)), __('rides.cases.messages.opened'), 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $party = $this->party($request);

        return ApiResponse::success($this->cases->present($this->own($party, $uuid), RideCases::partyType($party)), __('api.retrieved'));
    }

    public function message(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:2000']]);
        $party = $this->party($request);
        $case = $this->own($party, $uuid);
        $this->cases->message($case, $party, $data['body']);

        return ApiResponse::success($this->cases->present($case->refresh(), RideCases::partyType($party)), __('api.created'), 201);
    }

    public function file(Request $request, string $uuid): JsonResponse
    {
        // O-63: a dash cam's video too (mp4 / mov / 3gp / webm), with its own, bigger limit.
        $video = str_starts_with((string) $request->file('file')?->getMimeType(), 'video/');
        $request->validate(['file' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf,mp4,mov,3gp,webm',
            'max:'.(int) ($video ? config('rides.cases.max_video_kb', 51200) : config('rides.cases.max_file_kb', 5120))]]);
        $party = $this->party($request);
        $case = $this->own($party, $uuid);
        $this->cases->attach($case, $party, $request->file('file'));

        return ApiResponse::success($this->cases->present($case->refresh(), RideCases::partyType($party)), __('api.created'), 201);
    }

    public function appeal(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:2000']]);
        $party = $this->party($request);
        $case = $this->cases->appeal($this->own($party, $uuid), $party, $data['note']);

        return ApiResponse::success($this->cases->present($case, RideCases::partyType($party)), __('rides.cases.messages.appealed'));
    }

    private function party(Request $request): Model
    {
        /** @var Model */
        return $request->user();
    }

    private function own(Model $party, string $uuid): RideCase
    {
        $case = RideCase::query()->where('uuid', $uuid)->first();
        if ($case === null || ! $case->isOwnedBy($party)) {
            throw new RidesException('case_not_found', 404);
        }

        return $case;
    }

    /**
     * The list needs the headline only.
     *
     * @param  array<string, mixed>  $case
     * @return array<string, mixed>
     */
    private function summary(array $case): array
    {
        return array_intersect_key($case, array_flip(['uuid', 'number', 'kind', 'kind_label', 'category', 'category_label', 'status', 'status_label', 'decided', 'ride', 'disputed_minor', 'currency', 'can_appeal', 'created_at']));
    }
}
