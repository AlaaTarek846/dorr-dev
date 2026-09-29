<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\NotificationDevice;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * A mobile account's own notifications (users and providers — the route group decides which
 * guard). Everything is scoped to the authenticated account; another account's notification id
 * simply isn't found.
 */
class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $account = $request->user();
        $perPage = max(1, min((int) $request->input('per_page', 15), 100));

        $query = $request->boolean('unread') ? $account->unreadNotifications() : $account->notifications();
        $paginator = $query->latest()->paginate($perPage);

        return ApiResponse::fromPaginator(
            $paginator,
            NotificationResource::collection($paginator->items()),
            __('api.retrieved'),
        );
    }

    public function unreadCount(Request $request): JsonResponse
    {
        return ApiResponse::success(['count' => $request->user()->unreadNotifications()->count()], __('api.retrieved'));
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        $notification = $request->user()->notifications()->where('id', $id)->first();

        abort_if($notification === null, 404);

        $notification->markAsRead();

        return ApiResponse::success(['count' => $request->user()->unreadNotifications()->count()], __('api.updated'));
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success(['count' => 0], __('api.updated'));
    }

    /**
     * The phone tells us its OneSignal player id once it has one (and again on every launch — the
     * id can move between accounts when someone logs out and another logs in on the same phone).
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'player_id' => ['required', 'string', 'max:191'],
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
        ]);

        $account = $request->user();

        NotificationDevice::query()->updateOrCreate(
            ['player_id' => $data['player_id']],
            [
                'owner_type' => $account->getMorphClass(),
                'owner_id' => $account->getKey(),
                'platform' => $data['platform'] ?? null,
                'locale' => app()->getLocale(),
                'last_seen_at' => now(),
            ],
        );

        return ApiResponse::success([], __('api.updated'));
    }

    public function unregisterDevice(Request $request): JsonResponse
    {
        $data = $request->validate(['player_id' => ['required', 'string', 'max:191']]);
        $account = $request->user();

        $account->notificationDevices()->where('player_id', $data['player_id'])->delete();

        return ApiResponse::success([], __('api.updated'));
    }
}
