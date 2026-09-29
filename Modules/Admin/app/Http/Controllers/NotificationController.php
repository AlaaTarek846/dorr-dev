<?php

namespace Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Resources\NotificationResource;
use Modules\Admin\Models\Admin;

class NotificationController extends Controller
{
    public function unread(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin_api');
        $notifications = $admin->unreadNotifications()->latest()->take(20)->get();
        $count = $admin->unreadNotifications()->count();

        return ApiResponse::success([
            'notifications' => NotificationResource::collection($notifications),
            'count' => $count,
        ], __('api.retrieved'));
    }

    public function markAsRead(Request $request, string $id): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin_api');
        $admin->notifications()->where('id', $id)->update(['read_at' => now()]);

        return ApiResponse::success([], __('api.updated'));
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin_api');
        $admin->unreadNotifications()->update(['read_at' => now()]);

        return ApiResponse::success([], __('api.updated'));
    }

    public function index(Request $request): JsonResponse
    {
        /** @var Admin $admin */
        $admin = $request->user('admin_api');
        $perPage = max(1, min((int) $request->input('per_page', 15), 100));
        $paginator = $admin->notifications()->latest()->paginate($perPage);

        return ApiResponse::fromPaginator(
            $paginator,
            NotificationResource::collection($paginator->items()),
            __('api.retrieved')
        );
    }
}
