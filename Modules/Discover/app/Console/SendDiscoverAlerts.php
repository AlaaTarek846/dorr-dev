<?php

namespace Modules\Discover\Console;

use App\Support\LocaleResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatPrivacySetting;
use Modules\Chat\Services\ChatPushNotifier;
use Modules\Chat\Support\ParticipantType;
use Modules\Discover\Models\DiscoverEvent;
use Modules\Discover\Models\DiscoverFollow;
use Modules\Discover\Models\DiscoverPreference;
use Modules\Discover\Models\DiscoverSetting;

/**
 * "Don't miss it" (spec 172), hourly: confirmed events coming up within my window, in my interests,
 * where I live or in places I follow. Never the same event twice, never past the admin's weekly
 * limit, nothing during my quiet hours, nothing I already said "interested" to.
 */
class SendDiscoverAlerts extends Command
{
    protected $signature = 'discover:alerts';

    protected $description = 'Tell people about upcoming events that match their interests';

    public function handle(ChatPushNotifier $push): int
    {
        $settings = DiscoverSetting::current();
        if (! $settings->enabled || ! $settings->alerts_enabled || $settings->max_alerts_per_week < 1) {
            return self::SUCCESS;
        }

        $sent = 0;
        DiscoverPreference::query()->where('alerts', true)->whereNotNull('categories')->orderBy('id')
            ->chunkById(200, function ($rows) use ($push, $settings, &$sent) {
                foreach ($rows as $prefs) {
                    if ($this->alertOne($prefs, $settings, $push)) {
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} Discover alert(s).");

        return self::SUCCESS;
    }

    private function alertOne(DiscoverPreference $prefs, DiscoverSetting $settings, ChatPushNotifier $push): bool
    {
        $categories = $prefs->categoryIds();
        $owner = $categories === [] ? null : ParticipantType::modelClassFor($prefs->owner_type)::query()->find($prefs->owner_id);
        if ($owner === null) {
            return false;
        }
        $quiet = ChatPrivacySetting::query()->where('owner_type', $prefs->owner_type)->where('owner_id', $prefs->owner_id)->first();
        if ($quiet?->quietOn()) {
            return false; // next hour
        }

        $log = DB::table('discover_alert_log')->where('owner_type', $prefs->owner_type)->where('owner_id', $prefs->owner_id);
        $left = $settings->max_alerts_per_week - (clone $log)->where('sent_at', '>=', now()->subDays(7))->count();
        if ($left <= 0) {
            return false;
        }

        $follows = DiscoverFollow::query()->where('owner_type', $prefs->owner_type)->where('owner_id', $prefs->owner_id)->get();
        $cityIds = $follows->where('kind', 'city')->pluck('target_id')->all();
        $countryIds = array_values(array_filter([...$follows->where('kind', 'country')->pluck('target_id')->all(), $owner->country_id ?? null]));
        if (! $settings->onIn($owner->country_id ?? null) && $cityIds === [] && $countryIds === []) {
            return false;
        }

        $events = DiscoverEvent::query()->public()->where('status', 'confirmed')
            ->whereIn('category_id', $categories)
            ->whereBetween('starts_at', [now()->addHours(3), now()->addDays(max(1, $prefs->alert_days))])
            ->when($prefs->family_only, fn ($q) => $q->where('family_friendly', true))
            ->where(fn ($q) => $q->whereIn('city_id', $cityIds ?: [0])->orWhereIn('country_id', $countryIds ?: [0]))
            ->whereNotExists(fn ($q) => $q->from('discover_alert_log as l')->whereColumn('l.event_id', 'discover_events.id')
                ->where('l.owner_type', $prefs->owner_type)->where('l.owner_id', $prefs->owner_id))
            ->whereNotExists(fn ($q) => $q->from('discover_interests as i')->whereColumn('i.event_id', 'discover_events.id')
                ->where('i.owner_type', $prefs->owner_type)->where('i.owner_id', $prefs->owner_id))
            ->orderBy('starts_at')->limit($left)->get();
        if ($events->isEmpty()) {
            return false;
        }

        DB::table('discover_alert_log')->insert($events->map(fn (DiscoverEvent $e) => [
            'owner_type' => $prefs->owner_type, 'owner_id' => $prefs->owner_id, 'event_id' => $e->id, 'sent_at' => now(),
        ])->all());

        $first = $events->first();
        $headings = [];
        $contents = [];
        foreach (LocaleResolver::supported() as $locale) {
            $headings[$locale] = (string) __('discover.push.alert_title', [], $locale);
            $contents[$locale] = $events->count() === 1
                ? $first->title
                : (string) __('discover.push.alert_many', ['title' => $first->title, 'count' => $events->count() - 1], $locale);
        }
        $push->toAccounts([[$prefs->owner_type, (int) $prefs->owner_id]], $headings, $contents, [
            'type' => 'discover', 'event' => 'discover.alert', 'event_id' => $events->count() === 1 ? $first->uuid : null,
        ]);

        return true;
    }
}
