<?php

namespace Modules\WatchEarn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Modules\WatchEarn\Enums\AdStatus;
use Modules\WatchEarn\Exceptions\WatchEarnException;
use Modules\WatchEarn\Http\Resources\AdResource;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdComment;
use Modules\WatchEarn\Models\CommentReport;
use Modules\WatchEarn\Models\WeSetting;
use Modules\WatchEarn\Services\AdService;
use Modules\WatchEarn\Services\CommentReportService;
use Modules\WatchEarn\Services\ExportService;
use Modules\WatchEarn\Services\AdWalletService;
use Modules\WatchEarn\Services\StatsService;

/** The advertiser in the app (WE-020..WE-026): the wizard's options, my ads, one ad's stats, and every change. */
class AdvertiserController extends Controller
{
    public function __construct(
        private readonly AdService $ads,
        private readonly AdWalletService $adWallets,
        private readonly StatsService $stats,
    ) {}

    /** GET watch-earn/advertiser/options — what the wizard needs: limits, the price, the minimum and the cities. */
    public function options(Request $request)
    {
        $country = currentCountry();
        $settings = WeSetting::forCountry($country->id);
        $adWallet = $this->adWallets->for($request->user(), $country);
        $class = 'Modules\\Discover\\Models\\DiscoverCity';
        $cities = class_exists($class)
            ? $class::query()->where('country_id', $country->id)->where('status', true)->with('translations')->orderBy('sort_order')->get()
                ->map(fn (Model $c) => ['id' => (int) $c->getKey(), 'name' => $c->translatedName()])->values()->all()
            : [];

        return ApiResponse::success([
            'enabled' => $settings->usable(),
            'country' => ['id' => $country->id, 'code' => $country->code, 'name' => $country->translatedName()],
            'currency' => ['code' => $country->currency?->code, 'decimals' => (int) ($country->currency?->decimal_places ?? 2)],
            'price_per_view_minor' => $settings->price_per_view_minor,
            'min_ad_wallet_minor' => $settings->min_ad_wallet_minor,
            'free_minor' => $adWallet->freeMinor(),
            'media' => [
                'image_mimes' => AdService::IMAGE_MIMES,
                'video_mimes' => AdService::VIDEO_MIMES,
                'image_seconds' => $settings->image_seconds,
                'image_max_mb' => AdService::IMAGE_MAX_MB,
                'video_max_seconds' => $settings->video_max_seconds,
                'video_max_mb' => $settings->video_max_mb,
            ],
            'cities' => $cities,
            'genders' => ['male', 'female'],
            'locales' => ['ar', 'en'],
        ], __('api.retrieved'));
    }

    /** POST watch-earn/advertiser/audience — the live "about N people" while choosing. */
    public function audience(Request $request)
    {
        $data = $request->validate([
            'cities' => ['nullable', 'array', 'max:200'],
            'cities.*' => ['integer'],
            'gender' => ['nullable', Rule::in(['male', 'female'])],
            'locale' => ['nullable', Rule::in(['ar', 'en'])],
        ]);

        return ApiResponse::success([
            'people' => $this->stats->audience(currentCountry(), array_map('intval', $data['cities'] ?? []), $data['gender'] ?? null, $data['locale'] ?? null, $request->user()->id),
        ], __('api.retrieved'));
    }

    /** GET watch-earn/advertiser/ads?status= */
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(array_map(fn (AdStatus $s) => $s->value, AdStatus::cases()))]]);
        $page = Ad::query()->where('user_id', $request->user()->id)
            ->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['cities', 'country.currency'])->orderByDesc('id')->paginate(20);

        return ApiResponse::fromPaginator($page, AdResource::collection($page->items())->resolve(), __('api.retrieved'));
    }

    /** GET watch-earn/advertiser/ads/{ad} */
    public function show(Request $request, Ad $ad)
    {
        return ApiResponse::success((new AdResource($this->mine($request, $ad)->load(['cities', 'country.currency'])))->resolve(), __('api.retrieved'));
    }

    /** GET watch-earn/advertiser/ads/{ad}/stats — the stats page (WE-024). */
    public function stats(Request $request, Ad $ad)
    {
        $ad = $this->mine($request, $ad)->load(['cities', 'country.currency']);

        return ApiResponse::success([
            'ad' => (new AdResource($ad))->resolve(),
            'stats' => $this->stats->forAd($ad),
        ], __('api.retrieved'));
    }

    /** GET watch-earn/advertiser/ads/{ad}/export?format=pdf|xlsx — the stats as a file (WE-024). */
    public function export(Request $request, Ad $ad, ExportService $exports)
    {
        $data = $request->validate(['format' => ['required', Rule::in(['pdf', 'xlsx'])]]);
        [$bytes, $name, $mime] = $exports->ad($this->mine($request, $ad), $data['format']);

        return response($bytes, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.$name.'"']);
    }

    /** POST watch-earn/advertiser/ads (multipart) — the wizard's last step: build, reserve, send to the review. */
    public function store(Request $request)
    {
        $data = $this->validated($request, creating: true);
        $ad = $this->ads->create($request->user(), currentCountry(), $data, $request->file('media'));

        return ApiResponse::created((new AdResource($ad->load(['cities', 'country.currency'])))->resolve());
    }

    /** POST watch-earn/advertiser/ads/{ad} (multipart) — a content edit: back to the review. */
    public function update(Request $request, Ad $ad)
    {
        $data = $this->validated($request, creating: false);
        $ad = $this->ads->update($this->mine($request, $ad), $data, $request->file('media'));

        return ApiResponse::success((new AdResource($ad->load(['cities', 'country.currency'])))->resolve(), __('api.updated'));
    }

    /** POST watch-earn/advertiser/ads/{ad}/budget — more budget, from the ad wallet's free balance. */
    public function budget(Request $request, Ad $ad)
    {
        $data = $request->validate(['amount_minor' => ['required', 'integer', 'min:1']]);

        return $this->respond($this->ads->addBudget($this->mine($request, $ad), (int) $data['amount_minor']));
    }

    /** POST watch-earn/advertiser/ads/{ad}/extend */
    public function extend(Request $request, Ad $ad)
    {
        $data = $request->validate(['ends_at' => ['required', 'date']]);

        return $this->respond($this->ads->extend($this->mine($request, $ad), Carbon::parse($data['ends_at'])));
    }

    public function pause(Request $request, Ad $ad)
    {
        return $this->respond($this->ads->pause($this->mine($request, $ad)));
    }

    public function resume(Request $request, Ad $ad)
    {
        return $this->respond($this->ads->resume($this->mine($request, $ad)));
    }

    public function stop(Request $request, Ad $ad)
    {
        return $this->respond($this->ads->stop($this->mine($request, $ad)));
    }

    /** POST watch-earn/advertiser/ads/{ad}/comments/{comment}/report — the advertiser can't delete a comment, only report it (D21). */
    public function reportComment(Request $request, Ad $ad, AdComment $comment, CommentReportService $reports)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(CommentReport::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $reports->report($request->user(), $this->mine($request, $ad), $comment, $data['reason'], $data['note'] ?? null);

        return ApiResponse::success(null, __('watch_earn.reported'));
    }

    private function respond(Ad $ad)
    {
        return ApiResponse::success((new AdResource($ad->load(['cities', 'country.currency'])))->resolve(), __('api.updated'));
    }

    private function mine(Request $request, Ad $ad): Ad
    {
        if ($ad->user_id !== $request->user()->id) {
            throw new WatchEarnException('not_found', 404);
        }

        return $ad;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $data = $request->validate([
            'media' => [$creating ? 'required' : 'nullable', 'file'],
            'media_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'title' => [$req, 'string', 'min:2', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
            'link_url' => ['nullable', 'url:http,https', 'max:500'],
            'cities' => ['nullable', 'array', 'max:200'],
            'cities.*' => ['integer'],
            'target_gender' => ['nullable', Rule::in(['male', 'female'])],
            'target_locale' => ['nullable', Rule::in(['ar', 'en'])],
            'starts_at' => [$creating ? 'required' : 'prohibited', 'date'],
            'ends_at' => [$creating ? 'required' : 'prohibited', 'date', 'after:starts_at'],
            'budget_minor' => [$creating ? 'required' : 'prohibited', 'integer', 'min:1'],
        ]);

        if ($creating) {
            $data['starts_at'] = Carbon::parse($data['starts_at']);
            $data['ends_at'] = Carbon::parse($data['ends_at']);
        }
        unset($data['media']);

        return $data;
    }
}
