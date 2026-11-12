<?php

namespace Modules\Marketing\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Marketing\Models\MarketingPackage;
use Modules\Marketing\Models\MarketingSubscription;

/** The marketing packages (D5) and their price per country. */
class PackageController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('marketing-packages', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update']],
            ['delete', ['destroy']],
        ]);
    }

    public function index()
    {
        $rows = MarketingPackage::query()->with('prices')->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($rows->map(fn ($p) => $this->present($p))->values()->all(), __('api.retrieved'));
    }

    public function show(MarketingPackage $package)
    {
        return ApiResponse::success($this->present($package->load('prices')), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $package = DB::transaction(fn () => $this->save(new MarketingPackage, $this->validated($request)));

        return ApiResponse::created($this->present($package), __('api.created'));
    }

    public function update(Request $request, MarketingPackage $package)
    {
        $package = DB::transaction(fn () => $this->save($package, $this->validated($request, $package)));

        return ApiResponse::success($this->present($package), __('api.updated'));
    }

    /** A package someone bought is never removed: it is switched off (soft delete keeps their periods readable). */
    public function destroy(MarketingPackage $package)
    {
        if (MarketingSubscription::query()->where('package_id', $package->id)->exists()) {
            $package->update(['is_active' => false]);
        }
        $package->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?MarketingPackage $package = null): array
    {
        $req = $package === null ? 'required' : 'sometimes';

        return $request->validate([
            'code' => [$req, 'string', 'alpha_dash', 'max:40', Rule::unique('marketing_packages', 'code')->ignore($package?->id)],
            'name' => [$req, 'array'],
            'name.ar' => [$req, 'string', 'max:120'],
            'name.en' => [$req, 'string', 'max:120'],
            'description' => ['sometimes', 'nullable', 'array'],
            'description.ar' => ['nullable', 'string', 'max:1000'],
            'description.en' => ['nullable', 'string', 'max:1000'],
            'period_days' => [$req, 'integer', 'min:1', 'max:366'],
            'limits' => ['sometimes', 'nullable', 'array'],
            'limits.projects' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'limits.research_runs' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'limits.plan_generations' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'limits.members' => ['nullable', 'integer', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'prices' => ['sometimes', 'array'],
            'prices.*.country_id' => ['required', 'integer', 'distinct', 'exists:countries,id'],
            'prices.*.price_minor' => ['required', 'integer', 'min:1', 'max:100000000'],
            'prices.*.status' => ['sometimes', 'boolean'],
        ]);
    }

    /** @param  array<string, mixed>  $data */
    private function save(MarketingPackage $package, array $data): MarketingPackage
    {
        $package->fill(collect($data)->except('prices')->all());
        $package->save();
        if (array_key_exists('prices', $data)) {
            $keep = [];
            foreach ($data['prices'] as $row) {
                $keep[] = $package->prices()->updateOrCreate(['country_id' => $row['country_id']], ['price_minor' => $row['price_minor'], 'status' => $row['status'] ?? true])->id;
            }
            $package->prices()->whereNotIn('id', $keep)->delete();
        }

        return $package->load('prices');
    }

    private function present(MarketingPackage $p): array
    {
        return [
            'id' => $p->id,
            'code' => $p->code,
            'name' => $p->name,
            'description' => $p->description,
            'period_days' => $p->period_days,
            'limits' => collect(MarketingPackage::LIMITS)->mapWithKeys(fn ($k) => [$k => $p->limit($k)])->all(),
            'is_active' => $p->is_active,
            'sort_order' => $p->sort_order,
            'prices' => $p->prices->map(fn ($x) => ['country_id' => $x->country_id, 'price_minor' => $x->price_minor, 'status' => $x->status])->values()->all(),
            'subscribers' => MarketingSubscription::query()->current()->where('package_id', $p->id)->count(),
        ];
    }
}
