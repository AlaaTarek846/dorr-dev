<?php

namespace Modules\Chat\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Models\Country;
use App\Models\Language;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Chat\Models\ChatCategory;
use Modules\Chat\Models\ChatPackage;
use Modules\Chat\Models\ChatPortal;
use Modules\Chat\Services\ChatPackageService;
use Modules\Chat\Services\PortalService;

/**
 * Merchant portals (docs/remaining_chat.md ج.3): the portals page, my portals, add / edit with
 * the name and description per language (the AI fills the other languages), and the categories
 * channels and portals pick from. Paying for a listing goes through wallet/checkouts with the
 * `chat_portal_listing` purpose.
 */
class PortalController extends Controller
{
    public function __construct(
        private readonly PortalService $portals,
        private readonly ChatPackageService $packages,
    ) {}

    /** GET chat/categories */
    public function categories()
    {
        $rows = ChatCategory::query()->active()->with(['translations', 'media'])->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ChatCategory $c) => $c->brief());

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    /**
     * GET chat/portals/languages — the languages a portal's name and description are kept in
     * (one tab each in the form), the app's current one first.
     */
    public function languages()
    {
        $current = app()->getLocale();
        $rows = Language::query()->where('status', true)->where('stores_translation', true)
            ->with(['translations', 'translation'])->orderBy('id')->get()
            ->map(fn ($l) => ['code' => strtolower($l->code), 'name' => $l->translatedName() ?? strtoupper($l->code), 'direction' => $l->direction])
            ->sortBy(fn ($l) => $l['code'] === $current ? 0 : 1)->values();

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    /** GET chat/portals?search=&category_id= — grouped by category. */
    public function index(Request $request)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:60'], 'category_id' => ['nullable', 'integer']]);

        return ApiResponse::success($this->portals->directory($data['search'] ?? null, isset($data['category_id']) ? (int) $data['category_id'] : null), __('api.retrieved'));
    }

    /** GET chat/portals/mine */
    public function mine(Request $request)
    {
        return ApiResponse::success($this->portals->mine($request->user())->values(), __('api.retrieved'));
    }

    /** GET chat/portals/packages — the listing packages priced in my country. */
    public function packages()
    {
        return ApiResponse::success($this->packages->forCountry(ChatPackage::KIND_PORTAL, $this->country())->values(), __('api.retrieved'));
    }

    /** GET chat/portals/{portal} — mine (with every language and the listing history). */
    public function show(Request $request, ChatPortal $portal)
    {
        $me = $request->user();
        abort_unless($portal->isOwnedBy($me), 404);

        return ApiResponse::success($this->portals->present($portal->load(['translations', 'media', 'category.translations', 'category.media']), true)
            + ['history' => $this->packages->history('portal', $portal->id)->values()], __('api.retrieved'));
    }

    /** POST chat/portals (multipart: website_url, category_id, translations[], logo) */
    public function store(Request $request)
    {
        $portal = $this->portals->save($request->user(), null, $this->validated($request, true), $request->file('logo'));

        return ApiResponse::created($this->portals->present($portal, true), __('api.created'));
    }

    /** POST chat/portals/{portal} (multipart, so the logo can change too) */
    public function update(Request $request, ChatPortal $portal)
    {
        $portal = $this->portals->save($request->user(), $portal, $this->validated($request, false), $request->file('logo'));

        return ApiResponse::success($this->portals->present($portal, true), __('api.updated'));
    }

    public function destroy(Request $request, ChatPortal $portal)
    {
        $this->portals->delete($request->user(), $portal);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** POST chat/portals/{portal}/open — counts a view, answers the website to open. */
    public function open(Request $request, ChatPortal $portal)
    {
        return ApiResponse::success($this->portals->present($this->portals->open($request->user(), $portal)), __('api.retrieved'));
    }

    /** POST chat/portals/translate — `{from, name, description}` → the other languages. */
    public function translate(Request $request)
    {
        $data = $request->validate([
            'from' => ['required', 'string', 'max:10'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success($this->portals->translate(strtolower($data['from']), $data), __('api.retrieved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'website_url' => [$required, 'url:http,https', 'max:500'],
            'category_id' => ['sometimes', 'nullable', 'integer'],
            'translations' => [$required, 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['nullable', 'string', 'max:120'],
            'translations.*.description' => ['nullable', 'string', 'max:2000'],
            'logo' => [$creating ? 'required' : 'nullable', 'image', 'max:5120'],
        ]);
    }

    private function country(): Country
    {
        $country = currentCountry();
        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        return $country;
    }
}
