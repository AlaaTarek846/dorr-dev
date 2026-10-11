<?php

namespace Modules\Discover\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Modules\Discover\Models\DiscoverSetting;

/** Discover's switches (spec 182): on, where, organizer submissions, alerts, rooms, the AI. */
class DiscoverSettingController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('discover-settings', [
            ['view', ['show']],
            ['update', ['update']],
        ]);
    }

    public function show()
    {
        return ApiResponse::success($this->present(DiscoverSetting::current()), __('api.retrieved'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'enabled_countries' => ['sometimes', 'nullable', 'array'],
            'enabled_countries.*' => ['integer', 'distinct', 'exists:countries,id'],
            'submissions_enabled' => ['sometimes', 'boolean'],
            'auto_publish_verified' => ['sometimes', 'boolean'],
            'alerts_enabled' => ['sometimes', 'boolean'],
            'max_alerts_per_week' => ['sometimes', 'integer', 'min:0', 'max:21'],
            'room_close_hours' => ['sometimes', 'integer', 'min:0', 'max:720'],
            'ai_enabled' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('enabled_countries', $data)) {
            $data['enabled_countries'] = array_values(array_map('intval', $data['enabled_countries'] ?? [])) ?: null;
        }

        $setting = DiscoverSetting::query()->firstOrCreate([]);
        $setting->update($data);

        return ApiResponse::success($this->present($setting->refresh()), __('api.updated'));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DiscoverSetting $setting): array
    {
        return ['enabled_countries' => array_values($setting->enabled_countries ?? [])] + $setting->only((new DiscoverSetting)->getFillable());
    }
}
