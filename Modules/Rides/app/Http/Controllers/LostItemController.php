<?php

namespace Modules\Rides\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Driver\Models\Driver;
use Modules\Rides\Exceptions\RidesException;
use Modules\Rides\Models\Ride;
use Modules\Rides\Models\RideLostItem;
use Modules\Rides\Services\Safety\LostItems;

/** Lost items (D-294..D-297) — the rider (`mobile/v1/rides/…`) and the captain (`driver/v1/lost-items…`). */
class LostItemController extends Controller
{
    public function __construct(private readonly LostItems $items) {}

    /** The rider reports something left on one of their rides. */
    public function report(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate(['description' => ['required', 'string', 'min:3', 'max:500']]);
        $ride = Ride::query()->where('uuid', $uuid)->where('user_id', $request->user()->getKey())->first() ?? throw new RidesException('ride_not_found', 404);

        return ApiResponse::success(LostItems::present($this->items->report($request->user(), $ride, $data['description']), 'user'), __('rides.lost.reported'), 201);
    }

    /** Mine — the rider's reports, or the captain's requests. */
    public function index(Request $request): JsonResponse
    {
        $me = $request->user();
        $side = $me instanceof Driver ? 'driver' : 'user';
        $rows = RideLostItem::query()->where($side === 'driver' ? 'driver_id' : 'user_id', $me->getKey())->latest('id')->limit(50)->get();

        return ApiResponse::success($rows->map(fn (RideLostItem $i) => LostItems::present($i, $side))->values(), __('api.retrieved'));
    }

    public function show(Request $request, string $lost): JsonResponse
    {
        [$item, $side] = $this->find($request, $lost);

        return ApiResponse::success(LostItems::present($item, $side), __('api.retrieved'));
    }

    public function message(Request $request, string $lost): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:1000']]);
        [$item, $side] = $this->find($request, $lost);
        $this->items->message($request->user(), $item, $data['body']);

        return ApiResponse::success(LostItems::present($item->refresh(), $side), __('api.created'), 201);
    }

    /** The captain: found or not, and how it comes back. */
    public function answer(Request $request, string $lost): JsonResponse
    {
        $data = $request->validate(['found' => ['required', 'boolean'], 'plan' => ['nullable', 'string', 'max:300']]);
        [$item] = $this->find($request, $lost);

        return ApiResponse::success(LostItems::present($this->items->answer($request->user(), $item, (bool) $data['found'], $data['plan'] ?? null), 'driver'), __('api.updated'));
    }

    /** D-297: handed back — with a photo. */
    public function returned(Request $request, string $lost): JsonResponse
    {
        $request->validate(['photo' => ['nullable', 'image', 'max:8192']]);
        [$item] = $this->find($request, $lost);

        return ApiResponse::success(LostItems::present($this->items->returned($request->user(), $item, $request->file('photo')), 'driver'), __('api.updated'));
    }

    public function close(Request $request, string $lost): JsonResponse
    {
        [$item] = $this->find($request, $lost);

        return ApiResponse::success(LostItems::present($this->items->close($request->user(), $item), 'user'), __('api.updated'));
    }

    /** @return array{0: RideLostItem, 1: string} */
    private function find(Request $request, string $uuid): array
    {
        $me = $request->user();
        $side = $me instanceof Driver ? 'driver' : 'user';
        $item = RideLostItem::query()->where('uuid', $uuid)->where($side === 'driver' ? 'driver_id' : 'user_id', $me->getKey())->first()
            ?? throw new RidesException('lost_item_not_found', 404);

        return [$item, $side];
    }
}
