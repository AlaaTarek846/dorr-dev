<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelancePromo as Promo;

/**
 * The promo banners on the freelancer app's home and the client's freelance hub (`audience`): a list with a filter, add, change (multipart, so the image can be replaced), switch on /
 * off and delete. Permission group `freelance-promos`.
 */
class FreelancePromoAdminController extends Controller implements HasMiddleware
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-promos', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update', 'toggle']],
            ['delete', ['destroy']],
        ]);
    }

    /** GET freelance-promos[?state=showing|scheduled|ended|off&audience=freelancer|client|both&country_id=&search=&per_page=] */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'state' => ['nullable', Rule::in(['showing', 'scheduled', 'ended', 'off'])], 'country_id' => ['nullable', 'integer'],
            'audience' => ['nullable', Rule::in(Promo::AUDIENCES)],
            'search' => ['nullable', 'string', 'max:100'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $page = Promo::query()->with(['media', 'country.translations'])
            ->when(($f['state'] ?? null) === 'off', fn ($q) => $q->where('is_active', false))
            ->when(($f['state'] ?? null) === 'scheduled', fn ($q) => $q->where('is_active', true)->where('starts_at', '>', now()))
            ->when(($f['state'] ?? null) === 'ended', fn ($q) => $q->where('ends_at', '<=', now()))
            ->when(($f['state'] ?? null) === 'showing', fn ($q) => $q->where('is_active', true)
                ->where(fn ($w) => $w->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', now())))
            ->when(isset($f['country_id']), fn ($q) => $q->where('country_id', $f['country_id']))
            ->when(isset($f['audience']), fn ($q) => $q->where('audience', $f['audience']))
            ->when(! empty($f['search']), fn ($q) => $q->where('title', 'like', '%'.$f['search'].'%'))
            ->orderBy('sort_order')->orderByDesc('id')
            ->paginate((int) ($f['per_page'] ?? 20));

        return ApiResponse::success($page->getCollection()->map(fn (Promo $p) => $this->present($p))->values(), __('api.retrieved'), 200, ApiPaginator::meta($page),
            // The pages a banner may open, for each audience (`screens` stays the freelancer app's, as before).
            ['screens' => array_keys(Promo::SCREENS), 'screens_by_audience' => collect(Promo::AUDIENCES)->mapWithKeys(fn (string $a) => [$a => array_keys(Promo::screensFor($a))])->all()]);
    }

    /** GET freelance-promos/{uuid} */
    public function show(string $uuid): JsonResponse
    {
        return ApiResponse::success($this->present($this->find($uuid)), __('api.retrieved'));
    }

    /** POST freelance-promos (multipart) */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, null);
        $promo = Promo::query()->create($data + ['created_by_admin_id' => $request->user('admin_api')?->id]);
        $promo->setImage($request->file('image'));
        $this->audit->record('freelance_promo.created', $promo, [], $promo->only(array_keys($data)));

        return ApiResponse::success($this->present($promo->refresh()), __('api.created'), 201);
    }

    /** POST freelance-promos/{uuid} (multipart; `remove_image=1` takes the image away) */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $promo = $this->find($uuid);
        $data = $this->validated($request, $promo);
        $before = $promo->only(array_keys($data));
        $promo->update($data);
        if ($request->hasFile('image')) {
            $promo->setImage($request->file('image'));
        } elseif ($request->boolean('remove_image')) {
            $promo->deleteSingleMedia('image');
        }
        $this->audit->record('freelance_promo.updated', $promo, $before, $promo->only(array_keys($data)));

        return ApiResponse::success($this->present($promo->refresh()), __('api.updated'));
    }

    /** POST freelance-promos/{uuid}/toggle */
    public function toggle(string $uuid): JsonResponse
    {
        $promo = $this->find($uuid);
        $promo->update(['is_active' => ! $promo->is_active]);
        $this->audit->record('freelance_promo.toggled', $promo, ['is_active' => ! $promo->is_active], ['is_active' => $promo->is_active]);

        return ApiResponse::success($this->present($promo), __('api.updated'));
    }

    /** DELETE freelance-promos/{uuid} */
    public function destroy(string $uuid): JsonResponse
    {
        $promo = $this->find($uuid);
        $this->audit->record('freelance_promo.deleted', $promo, $promo->only(['title', 'target_type', 'target', 'audience', 'country_id']), []);
        $promo->clearMediaCollection('image');
        $promo->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    // -------------------------------------------------------------- inside

    private function find(string $uuid): Promo
    {
        return Promo::query()->where('uuid', $uuid)->with(['media', 'country.translations'])->firstOrFail();
    }

    /** @return array<string, mixed> [$promo] is the one changed (null: a new one) */
    private function validated(Request $request, ?Promo $promo): array
    {
        $need = $promo === null ? 'required' : 'sometimes';
        $data = $request->validate([
            'title' => [$need, 'array'], 'title.ar' => ['required_with:title', 'string', 'max:80'], 'title.en' => ['required_with:title', 'string', 'max:80'],
            'body' => ['sometimes', 'nullable', 'array'], 'body.ar' => ['nullable', 'string', 'max:160'], 'body.en' => ['nullable', 'string', 'max:160'],
            'cta' => ['sometimes', 'nullable', 'array'], 'cta.ar' => ['nullable', 'string', 'max:30'], 'cta.en' => ['nullable', 'string', 'max:30'],
            'target_type' => [$need, Rule::in(Promo::TARGETS)],
            'target' => ['nullable', 'string', 'max:500'],
            'audience' => ['sometimes', Rule::in(Promo::AUDIENCES)],
            'country_id' => ['sometimes', 'nullable', 'integer', 'exists:countries,id'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date', 'after:starts_at'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'is_active' => ['sometimes', 'boolean'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        unset($data['image']);

        // Where a tap leads: one of the app's pages (one both apps have for «both»), or a web page on https; nothing for "none". A page
        // already set is checked again when only the audience changes.
        $audience = $data['audience'] ?? $promo?->audience ?? Promo::FREELANCER;
        $type = $data['target_type'] ?? null;
        if ($type === null && $promo?->target_type === Promo::SCREEN && isset($data['audience']) && ! array_key_exists('target', $data)) {
            if (! array_key_exists((string) $promo->target, Promo::screensFor($audience))) {
                throw \Illuminate\Validation\ValidationException::withMessages(['target' => __('validation.in', ['attribute' => 'target'])]);
            }
        }
        if ($type !== null) {
            if ($type === Promo::SCREEN) {
                $request->validate(['target' => ['required', Rule::in(array_keys(Promo::screensFor($audience)))]]);
            } elseif ($type === Promo::URL) {
                $request->validate(['target' => ['required', 'url:https']]);
            } else {
                $data['target'] = null;
            }
        }

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(Promo $p): array
    {
        $state = ! $p->is_active ? 'off'
            : ($p->ends_at !== null && $p->ends_at->lte(now()) ? 'ended'
                : ($p->starts_at !== null && $p->starts_at->isFuture() ? 'scheduled' : 'showing'));

        return [
            'uuid' => $p->uuid, 'title' => $p->title, 'body' => $p->body, 'cta' => $p->cta,
            'target_type' => $p->target_type, 'target' => $p->target, 'audience' => $p->audience ?? Promo::FREELANCER,
            'country_id' => $p->country_id, 'country' => $p->country?->translatedName(),
            'starts_at' => $p->starts_at?->toIso8601String(), 'ends_at' => $p->ends_at?->toIso8601String(),
            'sort_order' => $p->sort_order, 'is_active' => $p->is_active, 'state' => $state,
            'image_path' => $p->imagePath(), 'created_at' => $p->created_at?->toIso8601String(),
        ];
    }
}
