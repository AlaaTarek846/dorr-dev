<?php

namespace Modules\WatchEarn\Services;

use App\Support\LocaleResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Chat\Services\ChatBroadcaster;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\WatchEarn\Events\AdRealtimeEvent;
use Modules\WatchEarn\Models\Ad;
use Throwable;

/**
 * Push (OneSignal) for what must reach the advertiser or viewer with the app closed, and real-time events for the
 * screens that are open — never a timer in the app (AGENTS.md). Sent after the transaction commits; a failure never
 * fails the action.
 */
class WatchEarnNotifier
{
    public function __construct(
        private readonly ChatPushNotifier $push,
        private readonly ChatBroadcaster $realtime,
    ) {}

    /** approved · rejected · suspended · exhausted · low_balance · ended — to the advertiser. */
    public function advertiser(Ad $ad, string $key, array $vars = []): void
    {
        $vars = ['title' => $ad->title] + $vars;
        $headings = [];
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = (string) __("watch_earn.push.{$key}_title", $vars, $locale);
            $contents[$locale] = (string) __("watch_earn.push.{$key}_body", $vars, $locale);
        }

        $this->afterCommit(fn () => $this->push->toAccounts([['user', (int) $ad->user_id]], $headings, $contents, [
            'type' => 'watch_earn', 'event' => 'watch_earn.ad.'.$key, 'ad_id' => $ad->uuid,
        ]));
        $this->stats($ad);
    }

    /** "Your rewards are in your wallet" — one push per viewer per release run. */
    public function rewardsReleased(int $userId, string $amount): void
    {
        $headings = [];
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = (string) __('watch_earn.push.rewards_title', [], $locale);
            $contents[$locale] = (string) __('watch_earn.push.rewards_body', ['amount' => $amount], $locale);
        }

        $this->afterCommit(fn () => $this->push->toAccounts([['user', $userId]], $headings, $contents, ['type' => 'watch_earn', 'event' => 'watch_earn.rewards']));
    }

    /** Any one person, with `watch_earn.push.{key}_title/_body` (a comment report's answer, D21). */
    public function user(int $userId, string $key, array $vars = [], array $data = []): void
    {
        $headings = [];
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = (string) __("watch_earn.push.{$key}_title", $vars, $locale);
            $contents[$locale] = (string) __("watch_earn.push.{$key}_body", $vars, $locale);
        }

        $this->afterCommit(fn () => $this->push->toAccounts([['user', $userId]], $headings, $contents, ['type' => 'watch_earn', 'event' => 'watch_earn.'.$key] + $data));
    }

    /** The advertiser's stats page and list refresh with one request when this arrives. */
    public function stats(Ad $ad): void
    {
        $this->realtime->toAccounts([['user', (int) $ad->user_id]], 'watch_earn.ad.changed', [
            'ad_id' => $ad->uuid,
            'status' => $ad->status->value,
            'completed' => $ad->completed_count,
            'interactions' => $ad->interactions_count,
            'spent_minor' => $ad->spent_minor,
        ]);
    }

    /** To everyone with the ad open (a new comment, a reaction). */
    public function toViewers(Ad $ad, string $event, array $payload): void
    {
        $this->afterCommit(fn () => event(new AdRealtimeEvent($ad->uuid, $event, $payload)));
    }

    private function afterCommit(callable $send): void
    {
        DB::afterCommit(function () use ($send) {
            try {
                $send();
            } catch (Throwable $e) {
                Log::error('[WatchEarnNotifier] '.$e->getMessage());
            }
        });
    }
}
