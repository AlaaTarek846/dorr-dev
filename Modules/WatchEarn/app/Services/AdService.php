<?php

namespace Modules\WatchEarn\Services;

use App\Models\Country;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\User\Models\User;
use Modules\WatchEarn\Enums\AdStatus;
use Modules\WatchEarn\Exceptions\WatchEarnException;
use Modules\WatchEarn\Models\Ad;
use Modules\WatchEarn\Models\AdCity;
use Modules\WatchEarn\Models\WeSetting;
use Throwable;

/**
 * An advertiser's ads (WE-020..WE-023) and the admin's review (WE-041/042). One ad = one image or video, one
 * country (D9), its budget reserved from the ad wallet when sent (D14) and locked until the ad closes.
 */
class AdService
{
    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public const VIDEO_MIMES = ['video/mp4', 'video/quicktime', 'video/webm'];

    public const IMAGE_MAX_MB = 10;

    public function __construct(
        private readonly AdWalletService $adWallets,
        private readonly WatchEarnNotifier $notify,
    ) {}

    public function settingsFor(Country $country): WeSetting
    {
        $settings = WeSetting::forCountry($country->id);
        if (! $settings->usable()) {
            throw new WatchEarnException('off', 403);
        }

        return $settings;
    }

    /**
     * WE-022 — builds the ad, stores its file, reserves the budget and sends it to the review. One request, at the
     * wizard's last step.
     *
     * @param  array{title: string, body?: ?string, link_url?: ?string, starts_at: CarbonInterface, ends_at: CarbonInterface, budget_minor: int, cities?: list<int>, target_gender?: ?string, target_locale?: ?string, media_seconds?: ?int}  $data
     */
    public function create(User $user, Country $country, array $data, UploadedFile $media): Ad
    {
        $settings = $this->settingsFor($country);
        $budget = (int) $data['budget_minor'];

        if ($budget < $settings->price_per_view_minor) {
            throw new WatchEarnException('budget_too_small', 422, ['amount' => $this->money($settings->price_per_view_minor, $country)]);
        }
        $this->assertDates($data['starts_at'], $data['ends_at']);
        $cities = $this->citiesIn($country, $data['cities'] ?? []);
        [$type, $seconds] = $this->inspect($media, $settings, $data['media_seconds'] ?? null);

        $adWallet = $this->adWallets->for($user, $country);
        if ($adWallet->freeMinor() < max($settings->min_ad_wallet_minor, $budget)) {
            throw new WatchEarnException('insufficient_ad_wallet', 422, [], [
                'free_minor' => $adWallet->freeMinor(),
                'needed_minor' => max($settings->min_ad_wallet_minor, $budget),
            ]);
        }

        $uuid = (string) Str::uuid();
        $path = $media->storeAs("watch-earn/{$uuid}", 'v1.'.($media->guessExtension() ?: $media->getClientOriginalExtension()), Ad::DISK);

        try {
            return DB::transaction(function () use ($user, $country, $data, $settings, $budget, $cities, $type, $seconds, $media, $path, $uuid, $adWallet) {
                $ad = Ad::query()->create([
                    'uuid' => $uuid,
                    'user_id' => $user->id,
                    'ad_wallet_id' => $adWallet->id,
                    'country_id' => $country->id,
                    'status' => AdStatus::PendingReview,
                    'title' => $data['title'],
                    'body' => $data['body'] ?? null,
                    'link_url' => $data['link_url'] ?? null,
                    'media_type' => $type,
                    'media_path' => $path,
                    'media_mime' => $media->getMimeType(),
                    'media_size' => $media->getSize(),
                    'media_seconds' => $seconds,
                    'target_gender' => $data['target_gender'] ?? null,
                    'target_locale' => $data['target_locale'] ?? null,
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'],
                    'price_per_view_minor' => $settings->price_per_view_minor,
                    'platform_share_minor' => $settings->platform_share_minor,
                    'view_reward_minor' => $settings->view_reward_minor,
                    'interaction_reward_minor' => $settings->interaction_reward_minor,
                    'unclaimed_interaction' => $settings->unclaimed_interaction,
                    'submitted_at' => now(),
                ]);
                $this->syncCities($ad, $cities);
                $this->adWallets->reserve($ad, $budget);

                return $ad;
            });
        } catch (Throwable $e) {
            Storage::disk(Ad::DISK)->delete($path);
            throw $e;
        }
    }

    /**
     * WE-023 — a content edit (text, link, file, audience) goes back to the review; who already saw it keeps it
     * (REQ-005), a new file bumps the version. A rejected ad comes back with its budget reserved again.
     */
    public function update(Ad $ad, array $data, ?UploadedFile $media = null): Ad
    {
        $this->assertNotClosed($ad, allowRejected: true);
        $country = $ad->country;
        $settings = $this->settingsFor($country);
        $cities = array_key_exists('cities', $data) ? $this->citiesIn($country, $data['cities'] ?? []) : null;
        $file = null;
        if ($media !== null) {
            [$type, $seconds] = $this->inspect($media, $settings, $data['media_seconds'] ?? null);
            $version = $ad->media_version + 1;
            $file = [$type, $seconds, $media->storeAs("watch-earn/{$ad->uuid}", "v{$version}.".($media->guessExtension() ?: $media->getClientOriginalExtension()), Ad::DISK), $version];
        }

        return DB::transaction(function () use ($ad, $data, $media, $file, $cities) {
            $ad = Ad::query()->lockForUpdate()->findOrFail($ad->id);
            $old = $ad->media_path;

            $ad->fill(array_intersect_key($data, array_flip(['title', 'body', 'link_url', 'target_gender', 'target_locale'])));
            if ($file !== null) {
                [$type, $seconds, $path, $version] = $file;
                $ad->fill(['media_type' => $type, 'media_seconds' => $seconds, 'media_path' => $path, 'media_mime' => $media->getMimeType(), 'media_size' => $media->getSize(), 'media_version' => $version]);
            }
            if ($cities !== null) {
                $this->syncCities($ad, $cities);
            }

            // A rejected ad gave its budget back; sent again, it reserves the same unspent amount again.
            $again = $ad->status === AdStatus::Rejected ? $ad->budget_minor - $ad->spent_minor : 0;
            $ad->fill(['status' => AdStatus::PendingReview, 'submitted_at' => now(), 'reject_reason' => null, 'closed_at' => null]);
            if ($again > 0) {
                $ad->budget_minor = $ad->spent_minor;
                $this->adWallets->reserve($ad, $again);
            }
            $ad->save();

            if ($file !== null && $old) {
                DB::afterCommit(fn () => Storage::disk(Ad::DISK)->delete($old));
            }

            return $ad->refresh();
        });
    }

    /** WE-023 — more budget (no review). An ad that ran out comes back. */
    public function addBudget(Ad $ad, int $amountMinor): Ad
    {
        return DB::transaction(function () use ($ad, $amountMinor) {
            $ad = Ad::query()->lockForUpdate()->findOrFail($ad->id);
            $this->assertNotClosed($ad);

            $this->adWallets->reserve($ad, $amountMinor);

            $ad->low_balance_notified_at = null;
            if ($ad->status === AdStatus::Exhausted && $ad->reservedMinor() >= $ad->price_per_view_minor) {
                $ad->status = AdStatus::Active;
            }
            $ad->save();
            $this->notify->stats($ad);

            return $ad;
        });
    }

    /** WE-023 — a later end date (no review). */
    public function extend(Ad $ad, CarbonInterface $endsAt): Ad
    {
        return DB::transaction(function () use ($ad, $endsAt) {
            $ad = Ad::query()->lockForUpdate()->findOrFail($ad->id);
            $this->assertNotClosed($ad);
            if ($endsAt->lessThanOrEqualTo($ad->ends_at)) {
                throw new WatchEarnException('end_not_later');
            }

            $ad->forceFill(['ends_at' => $endsAt])->save();
            $this->notify->stats($ad);

            return $ad;
        });
    }

    public function pause(Ad $ad): Ad
    {
        return $this->move($ad, [AdStatus::Active, AdStatus::Exhausted], AdStatus::Paused, ['paused_at' => now()]);
    }

    public function resume(Ad $ad): Ad
    {
        return DB::transaction(function () use ($ad) {
            $ad = $this->move($ad, [AdStatus::Paused], AdStatus::Active, ['paused_at' => null]);
            if ($ad->reservedMinor() < $ad->price_per_view_minor) {
                $ad->forceFill(['status' => AdStatus::Exhausted])->save();
            }

            return $ad;
        });
    }

    /** For good: what's left of the budget goes back to the free balance (then it can go back to the wallet). */
    public function stop(Ad $ad): Ad
    {
        return $this->close($ad, AdStatus::Stopped);
    }

    // ── The admin ──────────────────────────────────────────────────────────────────────────────

    public function approve(Ad $ad, int $adminId): Ad
    {
        return DB::transaction(function () use ($ad, $adminId) {
            $ad = $this->move($ad, [AdStatus::PendingReview], AdStatus::Active, ['reviewed_by' => $adminId, 'reviewed_at' => now(), 'reject_reason' => null]);
            if ($ad->reservedMinor() < $ad->price_per_view_minor) {
                $ad->forceFill(['status' => AdStatus::Exhausted])->save();
            }
            $this->notify->advertiser($ad, 'approved');

            return $ad;
        });
    }

    public function reject(Ad $ad, int $adminId, string $reason): Ad
    {
        return DB::transaction(function () use ($ad, $adminId, $reason) {
            $ad = Ad::query()->lockForUpdate()->findOrFail($ad->id);
            if ($ad->status !== AdStatus::PendingReview) {
                throw new WatchEarnException('wrong_status', 409);
            }
            $ad->forceFill(['reviewed_by' => $adminId, 'reviewed_at' => now(), 'reject_reason' => $reason]);
            $ad = $this->close($ad, AdStatus::Rejected);
            $this->notify->advertiser($ad, 'rejected', ['reason' => $reason]);

            return $ad;
        });
    }

    /** By the admin or by reports (WE-035/042). It stops showing at once; its budget stays locked. */
    public function suspend(Ad $ad, ?string $reason = null): Ad
    {
        return DB::transaction(function () use ($ad, $reason) {
            $ad = $this->move($ad, [AdStatus::Active, AdStatus::Paused, AdStatus::Exhausted, AdStatus::PendingReview], AdStatus::Suspended, ['suspended_at' => now(), 'reject_reason' => $reason]);
            $this->notify->advertiser($ad, 'suspended');

            return $ad;
        });
    }

    /** Back from a suspension: the end date moves on by as long as it was off (REQ-018). */
    public function unsuspend(Ad $ad): Ad
    {
        return DB::transaction(function () use ($ad) {
            $ad = Ad::query()->lockForUpdate()->findOrFail($ad->id);
            if ($ad->status !== AdStatus::Suspended) {
                throw new WatchEarnException('wrong_status', 409);
            }
            $off = $ad->suspended_at ? (int) $ad->suspended_at->diffInSeconds(now()) : 0;
            $ad->forceFill([
                'status' => $ad->reservedMinor() >= $ad->price_per_view_minor ? AdStatus::Active : AdStatus::Exhausted,
                'ends_at' => $ad->ends_at->copy()->addSeconds($off),
                'suspended_at' => null,
                'reject_reason' => null,
            ])->save();
            $this->notify->advertiser($ad, 'approved');

            return $ad;
        });
    }

    /** The scheduler: ads whose end date passed close, and their budget is free again. */
    public function endExpired(): int
    {
        $n = 0;
        Ad::query()->whereIn('status', [AdStatus::Active->value, AdStatus::Paused->value, AdStatus::Exhausted->value])
            ->where('ends_at', '<=', now())->orderBy('id')
            ->each(function (Ad $ad) use (&$n) {
                DB::transaction(function () use ($ad) {
                    $ad = $this->close($ad, AdStatus::Ended);
                    $this->notify->advertiser($ad, 'ended');
                });
                $n++;
            });

        return $n;
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────

    private function close(Ad $ad, AdStatus $to): Ad
    {
        return DB::transaction(function () use ($ad, $to) {
            $locked = Ad::query()->lockForUpdate()->findOrFail($ad->id);
            $locked->fill(array_intersect_key($ad->getDirty(), array_flip(['reviewed_by', 'reviewed_at', 'reject_reason'])));
            if ($locked->status->closed()) {
                throw new WatchEarnException('closed', 409);
            }
            $locked->forceFill(['status' => $to, 'closed_at' => now()])->save();
            $this->adWallets->release($locked);
            $this->notify->stats($locked);

            return $locked;
        });
    }

    /** @param  list<AdStatus>  $from */
    private function move(Ad $ad, array $from, AdStatus $to, array $extra = []): Ad
    {
        return DB::transaction(function () use ($ad, $from, $to, $extra) {
            $ad = Ad::query()->lockForUpdate()->findOrFail($ad->id);
            if (! in_array($ad->status, $from, true)) {
                throw new WatchEarnException('wrong_status', 409);
            }
            $ad->forceFill(['status' => $to] + $extra)->save();
            $this->notify->stats($ad);

            return $ad;
        });
    }

    private function assertNotClosed(Ad $ad, bool $allowRejected = false): void
    {
        if ($ad->status->closed() && ! ($allowRejected && $ad->status === AdStatus::Rejected)) {
            throw new WatchEarnException('closed', 409);
        }
    }

    private function assertDates(CarbonInterface $startsAt, CarbonInterface $endsAt): void
    {
        if ($startsAt->lt(now()->startOfDay()) || $endsAt->lessThanOrEqualTo($startsAt) || $endsAt->isPast()) {
            throw new WatchEarnException('bad_dates');
        }
    }

    /**
     * The country's own active cities only (D9) — matched by id (D10).
     *
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function citiesIn(Country $country, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $class = 'Modules\\Discover\\Models\\DiscoverCity';
        $found = class_exists($class)
            ? $class::query()->whereIn('id', $ids)->where('country_id', $country->id)->where('status', true)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : [];
        if (count($found) !== count($ids)) {
            throw new WatchEarnException('bad_cities');
        }

        return $found;
    }

    /** @param  list<int>  $cities */
    private function syncCities(Ad $ad, array $cities): void
    {
        AdCity::query()->where('ad_id', $ad->id)->delete();
        foreach ($cities as $city) {
            AdCity::query()->insert(['ad_id' => $ad->id, 'city_id' => $city]);
        }
    }

    /**
     * Image or video, within the country's limits (D5). A video's length comes from ffprobe when the server has it,
     * else from what the app measured (never past the limit either way).
     *
     * @return array{0: string, 1: int} [type, seconds it shows]
     */
    private function inspect(UploadedFile $media, WeSetting $settings, ?int $clientSeconds): array
    {
        $mime = (string) $media->getMimeType();

        if (in_array($mime, self::IMAGE_MIMES, true)) {
            if ($media->getSize() > self::IMAGE_MAX_MB * 1024 * 1024) {
                throw new WatchEarnException('file_too_big', 422, ['mb' => self::IMAGE_MAX_MB]);
            }

            return ['image', max(3, $settings->image_seconds)];
        }

        if (! in_array($mime, self::VIDEO_MIMES, true)) {
            throw new WatchEarnException('bad_file');
        }
        if ($media->getSize() > $settings->video_max_mb * 1024 * 1024) {
            throw new WatchEarnException('file_too_big', 422, ['mb' => $settings->video_max_mb]);
        }

        $seconds = $this->probeSeconds($media->getRealPath()) ?? $clientSeconds;
        if ($seconds === null || $seconds <= 0) {
            throw new WatchEarnException('video_length_unknown');
        }
        if ($seconds > $settings->video_max_seconds) {
            throw new WatchEarnException('video_too_long', 422, ['seconds' => $settings->video_max_seconds]);
        }

        return ['video', (int) ceil($seconds)];
    }

    private function probeSeconds(string $path): ?int
    {
        try {
            $result = Process::timeout(15)->run([
                (string) config('ai.files.audio_ffprobe_path', 'ffprobe'), '-v', 'error', '-show_entries', 'format=duration', '-of', 'default=nw=1:nk=1', $path,
            ]);
            $value = trim($result->output());

            return $result->successful() && is_numeric($value) ? (int) ceil((float) $value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function money(int $minor, Country $country): string
    {
        $decimals = (int) ($country->currency?->decimal_places ?? 2);

        return number_format($minor / (10 ** $decimals), $decimals).' '.($country->currency?->code ?? '');
    }
}
