<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\Chat\Models\ChatPackage;

/**
 * The packages a merchant portal's listing and a channel's verification are sold in: a name per
 * language, N weeks / months / years, and a price per country (in that country's currency) — a
 * package with no price in a country isn't offered there.
 */
class ChatPackageController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-packages', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(Request $request)
    {
        $data = $request->validate(['kind' => ['nullable', Rule::in(ChatPackage::KINDS)]]);

        $rows = ChatPackage::query()->with(['translations', 'prices.country.currency'])
            ->when($data['kind'] ?? null, fn ($q, $kind) => $q->where('kind', $kind))
            ->orderBy('kind')->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (ChatPackage $package) => $this->present($package));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(ChatPackage $chatPackage)
    {
        return ApiResponse::success($this->present($chatPackage->load(['translations', 'prices.country.currency'])), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        return ApiResponse::created($this->present($this->save(new ChatPackage, $this->validated($request))), __('api.created'));
    }

    public function update(Request $request, ChatPackage $chatPackage)
    {
        return ApiResponse::success($this->present($this->save($chatPackage, $this->validated($request))), __('api.updated'));
    }

    public function status(Request $request, ChatPackage $chatPackage)
    {
        $chatPackage->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($chatPackage->load(['translations', 'prices.country.currency'])), __('api.updated'));
    }

    /** Past subscriptions keep their record (chat_package_id → null). */
    public function destroy(ChatPackage $chatPackage)
    {
        $chatPackage->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::in(ChatPackage::KINDS)],
            'period' => ['required', Rule::in(ChatPackage::PERIODS)],
            'period_count' => ['required', 'integer', 'min:1', 'max:60'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:100'],
            'translations.*.description' => ['nullable', 'string', 'max:500'],
            'prices' => ['required', 'array', 'min:1'],
            'prices.*.country_id' => ['required', 'integer', 'distinct', 'exists:countries,id'],
            'prices.*.amount_minor' => ['required', 'integer', 'min:1', 'max:100000000000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(ChatPackage $package, array $data): ChatPackage
    {
        DB::transaction(function () use ($package, $data) {
            $package->fill(collect($data)->only(['kind', 'period', 'period_count', 'status', 'sort_order'])->all())->save();

            foreach ($data['translations'] as $row) {
                $package->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name'], 'description' => $row['description'] ?? null]);
            }

            $countries = collect($data['prices'])->pluck('country_id')->all();
            $package->prices()->whereNotIn('country_id', $countries)->delete();
            foreach ($data['prices'] as $row) {
                $package->prices()->updateOrCreate(['country_id' => $row['country_id']], ['amount_minor' => $row['amount_minor']]);
            }
        });

        return $package->load(['translations', 'prices.country.currency']);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatPackage $package): array
    {
        return [
            'id' => $package->id,
            'kind' => $package->kind,
            'name' => $package->translatedName(),
            'period' => $package->period,
            'period_count' => $package->period_count,
            'status' => $package->status,
            'sort_order' => $package->sort_order,
            'translations' => $package->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name, 'description' => $t->description])->values(),
            'prices' => $package->prices->map(fn ($p) => [
                'country_id' => $p->country_id,
                'country_code' => $p->country?->code,
                'currency_code' => $p->country?->currency?->code,
                'amount_minor' => $p->amount_minor,
            ])->values(),
        ];
    }
}
