<?php

namespace Modules\WatchEarn\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\WatchEarn\Exceptions\WatchEarnException;
use Modules\WatchEarn\Http\Resources\FeedAdResource;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdComment;
use Modules\WatchEarn\Models\AdReaction;
use Modules\WatchEarn\Models\AdReport;
use Modules\WatchEarn\Models\AdView;
use Modules\WatchEarn\Models\Reward;
use Modules\WatchEarn\Models\WeSetting;
use Modules\WatchEarn\Services\ViewService;

/** The viewer (WE-030..WE-035): the ads to watch, the session, comments, reactions, reports and my rewards. */
class WatchController extends Controller
{
    public function __construct(private readonly ViewService $views) {}

    /** GET watch-earn/feed — the player's settings and the ads to watch, the viewer's city first. */
    public function feed(Request $request)
    {
        $country = currentCountry();
        $settings = WeSetting::forCountry($country->id);
        $ads = $settings->usable() ? $this->views->feed($request->user(), $country)->load(['user', 'cities', 'country.currency']) : collect();

        return ApiResponse::success([
            'enabled' => $settings->usable(),
            'player' => [
                'mode' => $settings->viewing_mode,
                'image_seconds' => $settings->image_seconds,
                'interaction_window_minutes' => $settings->interaction_window_minutes,
            ],
            'ads' => FeedAdResource::collection($ads)->resolve(),
        ], __('api.retrieved'));
    }

    /**
     * GET watch-earn/ads/{ad} — one ad the viewer already watched (or commented on), opened from a push such as the
     * answer to a report on their comment. Not a way to watch it again: no session, no reward.
     */
    public function show(Request $request, Ad $ad)
    {
        $user = $request->user();
        $view = AdView::query()->where('ad_id', $ad->id)->where('user_id', $user->id)->first();
        if ($view === null && ! AdComment::withTrashed()->where('ad_id', $ad->id)->where('user_id', $user->id)->exists()) {
            throw new WatchEarnException('not_found', 404);
        }

        return ApiResponse::success([
            'ad' => (new FeedAdResource($ad->load(['user', 'cities', 'country.currency'])))->resolve(),
            'watched' => $view?->completed_at !== null,
            'can_interact' => (bool) $view?->canInteract(),
            'interaction_deadline_at' => $view?->interaction_deadline_at?->toISOString(),
        ], __('api.retrieved'));
    }

    /** POST watch-earn/ads/{ad}/start */
    public function start(Request $request, Ad $ad)
    {
        $data = $request->validate(['platform' => ['nullable', Rule::in(['android', 'ios', 'web'])]]);
        $view = $this->views->start($request->user(), currentCountry(), $ad, $data['platform'] ?? null);

        return ApiResponse::success(['view_id' => $view->uuid, 'started_at' => $view->started_at->toISOString(), 'seconds' => $ad->media_seconds], __('api.created'));
    }

    /** POST watch-earn/views/{view}/complete — the server checks the time, charges and rewards. */
    public function complete(Request $request, AdView $view)
    {
        $view = $this->views->complete($request->user(), $view);

        return ApiResponse::success([
            'view_id' => $view->uuid,
            'completed_at' => $view->completed_at?->toISOString(),
            'interaction_deadline_at' => $view->interaction_deadline_at?->toISOString(),
            'can_interact' => $view->canInteract(),
        ], __('api.updated'));
    }

    /** GET watch-earn/ads/{ad}/comments */
    public function comments(Request $request, Ad $ad)
    {
        $page = $this->views->comments($ad);

        return ApiResponse::success([
            'comments' => collect($page->items())->map(fn (AdComment $c) => ViewService::commentPayload($c))->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
            'reactions' => $this->views->reactionCounts($ad),
            'my_reaction' => AdReaction::query()->where('ad_id', $ad->id)->where('user_id', $request->user()->id)->value('emoji'),
        ], __('api.retrieved'));
    }

    /** POST watch-earn/ads/{ad}/comments */
    public function comment(Request $request, Ad $ad)
    {
        $data = $request->validate(['body' => ['required', 'string', 'min:1', 'max:1000']]);
        $comment = $this->views->comment($request->user(), $ad, trim($data['body']));

        return ApiResponse::created(ViewService::commentPayload($comment->load('user')));
    }

    /** DELETE watch-earn/ads/{ad}/comments/{comment} — my own. */
    public function deleteComment(Request $request, Ad $ad, AdComment $comment)
    {
        $this->views->deleteComment($request->user(), $ad, $comment);

        return ApiResponse::noContent();
    }

    /** POST watch-earn/ads/{ad}/reaction */
    public function react(Request $request, Ad $ad)
    {
        $data = $request->validate(['emoji' => ['required', 'string', 'max:16']]);
        $this->views->react($request->user(), $ad, $data['emoji']);

        return ApiResponse::success(['reactions' => $this->views->reactionCounts($ad), 'my_reaction' => $data['emoji']], __('api.updated'));
    }

    /** DELETE watch-earn/ads/{ad}/reaction */
    public function unreact(Request $request, Ad $ad)
    {
        $this->views->unreact($request->user(), $ad);

        return ApiResponse::success(['reactions' => $this->views->reactionCounts($ad), 'my_reaction' => null], __('api.updated'));
    }

    /** POST watch-earn/ads/{ad}/report */
    public function report(Request $request, Ad $ad)
    {
        $data = $request->validate([
            'reason' => ['required', Rule::in(AdReport::REASONS)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);
        $this->views->report($request->user(), $ad, $data['reason'], $data['note'] ?? null);

        return ApiResponse::success(null, __('watch_earn.reported'));
    }

    /** GET watch-earn/rewards — waiting and paid, and the list. */
    public function rewards(Request $request)
    {
        $user = $request->user();
        $country = currentCountry();
        $base = Reward::query()->where('user_id', $user->id)->where('country_id', $country->id);
        $page = (clone $base)->with('ad:id,uuid,title')->orderByDesc('id')->cursorPaginate(30);
        $currency = $country->currency;

        return ApiResponse::success([
            'currency' => ['code' => $currency?->code, 'decimals' => (int) ($currency?->decimal_places ?? 2)],
            'waiting_minor' => (int) (clone $base)->whereNull('released_at')->sum('amount_minor'),
            'paid_minor' => (int) (clone $base)->whereNotNull('released_at')->sum('amount_minor'),
            'items' => collect($page->items())->map(fn (Reward $r) => [
                'id' => $r->id,
                'kind' => $r->kind,
                'amount_minor' => $r->amount_minor,
                'ad' => $r->ad ? ['id' => $r->ad->uuid, 'title' => $r->ad->title] : null,
                'available_at' => $r->available_at?->toISOString(),
                'released_at' => $r->released_at?->toISOString(),
                'created_at' => $r->created_at?->toISOString(),
            ])->all(),
            'next_cursor' => $page->nextCursor()?->encode(),
        ], __('api.retrieved'));
    }
}
