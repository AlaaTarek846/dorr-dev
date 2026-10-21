<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Modules\Rides\Models\RideCategory;

/**
 * Admin → Rides → Categories: the 11 kinds of ride, their names and lines in both languages,
 * colour, capacity, what each allows (cash, open destination, stops…) and the driver / vehicle
 * rules. A category reaches riders only when it is active AND switched on where they are.
 */
class RideCategoryController extends Controller implements HasMiddleware
{
    /** The flags a category may carry (RideCategory::flag). */
    public const FLAGS = ['cash_allowed', 'surge_allowed', 'open_destination_allowed', 'multi_stop_allowed', 'requires_schedule', 'pin_required'];

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-categories', [
            ['view', ['index']],
            ['update', ['update']],
        ]);
    }

    public function index(): JsonResponse
    {
        $rows = RideCategory::query()->with('translations')->orderBy('sort_order')->get()->map(fn (RideCategory $c) => $this->present($c))->values();

        return ApiResponse::success(['categories' => $rows, 'flags' => self::FLAGS], __('api.retrieved'));
    }

    public function update(Request $request, RideCategory $rideCategory, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:80'],
            'name.en' => ['required', 'string', 'max:80'],
            'tagline' => ['nullable', 'array'],
            'tagline.ar' => ['nullable', 'string', 'max:160'],
            'tagline.en' => ['nullable', 'string', 'max:160'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'capacity_min' => ['required', 'integer', 'between:1,60'],
            'capacity_max' => ['required', 'integer', 'between:1,60', 'gte:capacity_min'],
            'status' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'flags' => ['nullable', 'array'],
            'flags.*' => ['boolean'],
            'eligibility' => ['nullable', 'array'],
            'eligibility.min_year' => ['nullable', 'integer', 'between:1980,2100'],
            'eligibility.min_seats' => ['nullable', 'integer', 'between:1,60'],
            'eligibility.body_types' => ['nullable', 'array'],
            'eligibility.body_types.*' => ['string', 'max:16'],
            'eligibility.luxury_only' => ['nullable', 'boolean'],
            'eligibility.driver_gender' => ['nullable', 'in:male,female'],
            // D-254: the lowest captain rank this category takes (VIP…).
            'eligibility.min_rank' => ['nullable', 'string', 'exists:driver_ranks,slug'],
            'eligibility.required_features' => ['nullable', 'array'],
            'eligibility.required_features.*' => ['string', 'max:32'],
        ]);
        $before = $this->present($rideCategory->load('translations'));

        DB::transaction(function () use ($rideCategory, $data) {
            $rideCategory->fill([
                'color' => $data['color'] ?? $rideCategory->color,
                'capacity_min' => $data['capacity_min'],
                'capacity_max' => $data['capacity_max'],
                'status' => (bool) $data['status'],
                'sort_order' => $data['sort_order'] ?? $rideCategory->sort_order,
                'flags' => collect($data['flags'] ?? [])->only(self::FLAGS)->map(fn ($v) => (bool) $v)->all() + ((array) $rideCategory->flags),
                'eligibility' => array_filter((array) ($data['eligibility'] ?? $rideCategory->eligibility), fn ($v) => $v !== null && $v !== []),
            ]);
            $rideCategory->fillAllTranslations(['name' => $data['name'], 'tagline' => $data['tagline'] ?? []])->save();
        });

        $after = $this->present($rideCategory->fresh('translations'));
        $audit->record('ride_category.updated', $rideCategory, $before, $after);

        return ApiResponse::success($after, __('api.updated'));
    }

    /** @return array<string, mixed> */
    private function present(RideCategory $c): array
    {
        $by = fn (string $field) => $c->translations->mapWithKeys(fn ($t) => [$t->locale => $t->{$field}])->all();

        return [
            'id' => $c->id,
            'slug' => $c->slug,
            'name' => $c->translated('name'),
            'names' => $by('name'),
            'taglines' => $by('tagline'),
            'pricing_strategy' => $c->pricing_strategy->value,
            'capacity_min' => $c->capacity_min,
            'capacity_max' => $c->capacity_max,
            'color' => $c->color,
            'status' => (bool) $c->status,
            'sort_order' => $c->sort_order,
            'flags' => collect(self::FLAGS)->mapWithKeys(fn (string $f) => [$f => $c->flag($f)])->all(),
            'eligibility' => (object) ((array) $c->eligibility),
        ];
    }
}
