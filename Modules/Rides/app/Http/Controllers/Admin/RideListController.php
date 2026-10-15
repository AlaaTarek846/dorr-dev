<?php

namespace Modules\Rides\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Modules\Rides\Models\RideCancelReason;
use Modules\Rides\Models\RideRatingTag;

/**
 * Admin → Rides → Reasons & tags: the cancel reasons each side picks from, and the chips under the
 * stars. `{list}` is `cancel-reasons` or `rating-tags`; labels in both languages.
 */
class RideListController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('ride-lists', [
            ['view', ['index']],
            ['create', ['store']],
            ['update', ['update']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(string $list): JsonResponse
    {
        $class = $this->model($list);

        return ApiResponse::success($class::query()->with('translations')->orderBy('sort_order')->get()->map(fn (Model $m) => $this->present($m))->values(), __('api.retrieved'));
    }

    public function store(Request $request, string $list, AuditLogger $audit): JsonResponse
    {
        $class = $this->model($list);
        $data = $this->validated($request, $list);
        $model = DB::transaction(function () use ($class, $data) {
            $model = new $class(collect($data)->except('label')->all());
            $model->fillAllTranslations(['label' => $data['label']])->save();

            return $model;
        });
        $audit->record("ride_{$list}.created", $model, null, $data);

        return ApiResponse::success($this->present($model->fresh('translations')), __('api.created'), 201);
    }

    public function update(Request $request, string $list, int $id, AuditLogger $audit): JsonResponse
    {
        $model = $this->model($list)::query()->findOrFail($id);
        $data = $this->validated($request, $list);
        DB::transaction(function () use ($model, $data) {
            $model->fill(collect($data)->except('label')->all());
            $model->fillAllTranslations(['label' => $data['label']])->save();
        });
        $audit->record("ride_{$list}.updated", $model, null, $data);

        return ApiResponse::success($this->present($model->fresh('translations')), __('api.updated'));
    }

    public function destroy(string $list, int $id, AuditLogger $audit): JsonResponse
    {
        $model = $this->model($list)::query()->findOrFail($id);
        $model->delete();
        $audit->record("ride_{$list}.deleted", $model);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @return class-string<Model> */
    private function model(string $list): string
    {
        return match ($list) {
            'cancel-reasons' => RideCancelReason::class,
            'rating-tags' => RideRatingTag::class,
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, string $list): array
    {
        return $request->validate([
            'label' => ['required', 'array'],
            'label.ar' => ['required', 'string', 'max:160'],
            'label.en' => ['required', 'string', 'max:160'],
            'status' => ['required', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            ...($list === 'cancel-reasons' ? [
                'actor' => ['required', 'in:rider,driver,both'],
                'requires_note' => ['nullable', 'boolean'],
                'is_safety' => ['nullable', 'boolean'],
            ] : [
                'target' => ['required', 'in:driver,rider'],
                'sentiment' => ['required', 'in:positive,negative'],
            ]),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(Model $m): array
    {
        return [
            'id' => $m->getKey(),
            'label' => $m->translated('label'),
            'labels' => $m->translations->mapWithKeys(fn ($t) => [$t->locale => $t->label])->all(),
            'status' => (bool) $m->status,
            'sort_order' => (int) $m->sort_order,
            ...($m instanceof RideCancelReason
                ? ['actor' => $m->actor?->value, 'requires_note' => (bool) $m->requires_note, 'is_safety' => (bool) $m->is_safety]
                : ['target' => $m->target?->value, 'sentiment' => $m->sentiment]),
        ];
    }
}
