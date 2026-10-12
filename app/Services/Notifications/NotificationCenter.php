<?php

namespace App\Services\Notifications;

use App\Models\NotificationDevice;
use App\Support\LocaleResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Models\Admin;
use Throwable;

/**
 * The one place a business event turns into notifications, in every language.
 *
 * Three channels, same shape as the sibling projects (Jawad / LeeTaxi):
 *
 *  1. **In-app list** (`notifications` table) — stores the translation *key* + variables, never a
 *     finished sentence, so whoever opens the list sees it in *their* language at read time.
 *  2. **Real-time** (Pusher/Soketi) — composed at send time in the *recipient's* language
 *     (`locale` column), not the language of whoever triggered the event.
 *  3. **Push** (OneSignal, phone closed) — sent once with *every* supported language in the payload
 *     and OneSignal picks the one matching each device.
 *
 * It only sends after the surrounding DB transaction commits — a rolled-back payment must never
 * tell someone their wallet was charged — and a notification problem can never fail the action
 * that caused it (everything here is best-effort and logged).
 */
class NotificationCenter
{
    /** True while work handed to later() runs (already after the response). */
    private static bool $runningLater = false;

    /**
     * Runs $work after the response is sent, like defer(), for work that sends notifications. Laravel never runs a
     * callback that is defer()-ed from inside a deferred callback, so a push queued that way would be lost silently;
     * while $work runs, the pushes it sends go out right away instead.
     */
    public static function later(\Closure $work): void
    {
        defer(function () use ($work) {
            $previous = self::$runningLater;
            self::$runningLater = true;

            try {
                $work();
            } finally {
                self::$runningLater = $previous;
            }
        });
    }

    /**
     * @param  mixed  $receivers  a notifiable, or a list/collection of them
     * @param  string  $event  real-time event name, e.g. "wallet.topup.paid"
     * @param  string  $title  key in lang/{locale}/notifications.php
     * @param  string  $body  key in lang/{locale}/notifications.php
     * @param  array<string, scalar|null>  $variables  placeholders shared by title and body
     * @param  array<string, mixed>  $data  machine-readable payload (ids for deep links) — no secrets
     */
    public function send(mixed $receivers, string $event, string $title, string $body, array $variables = [], array $data = [], bool $push = true): void
    {
        $list = $this->normalize($receivers);

        if ($list === []) {
            return;
        }

        $data += ['event' => $event];

        DB::afterCommit(function () use ($list, $event, $title, $body, $variables, $data, $push) {
            try {
                sendNotification($event, $list, $data, '', $title, $body, $variables);
            } catch (Throwable $e) {
                Log::error('[NotificationCenter] in-app/real-time failed: '.$e->getMessage(), ['event' => $event]);
            }

            if ($push) {
                foreach ($list as $receiver) {
                    $this->push($receiver, $title, $body, $variables, $data);
                }
            }
        });
    }

    /**
     * A message written in the dashboard — one title and one text per language, not a translation key — through the
     * same three channels as every other notification: the in-app list and real-time (each recipient reads their own
     * language, falling back to another when theirs is missing) and OneSignal push (every language in one payload; the
     * device picks). Sent now — the caller hands it to later() when it is for many people.
     *
     * @param  mixed  $receivers  a notifiable, or a list/collection of them (of one kind: users, providers…)
     * @param  array<string, string>  $titles  locale => title
     * @param  array<string, string>  $bodies  locale => text
     * @param  array<string, mixed>  $data  machine-readable payload — no secrets
     * @return array{recipients: int, devices: int}
     */
    public function sendRaw(mixed $receivers, string $event, array $titles, array $bodies, array $data = [], bool $push = true): array
    {
        $list = $this->normalize($receivers);

        if ($list === []) {
            return ['recipients' => 0, 'devices' => 0];
        }

        $data += ['event' => $event];

        try {
            sendNotification($event, $list, $data, '', $titles, $bodies, [], 'Success', 'raw');
        } catch (Throwable $e) {
            Log::error('[NotificationCenter] raw in-app/real-time failed: '.$e->getMessage(), ['event' => $event]);
        }

        $devices = 0;

        if ($push) {
            foreach (collect($list)->groupBy(fn (Model $receiver) => $receiver->getMorphClass()) as $type => $group) {
                if (! method_exists($group->first(), 'notificationDevices')) {
                    continue;
                }

                $playerIds = NotificationDevice::query()
                    ->where('owner_type', $type)
                    ->whereIn('owner_id', $group->map->getKey()->all())
                    ->pluck('player_id')->filter()->unique()->values();

                $devices += $playerIds->count();

                // OneSignal takes up to 2000 devices a call. Sent right here, not defer()-ed: a callback deferred from
                // inside a deferred callback is never run (see later()).
                foreach ($playerIds->chunk(1000) as $chunk) {
                    try {
                        sendPushNotification(message: $bodies, playerIds: $chunk->values()->all(), title: $titles, data: $data);
                    } catch (Throwable $e) {
                        Log::error('[NotificationCenter] raw push failed: '.$e->getMessage());
                    }
                }
            }
        }

        return ['recipients' => count($list), 'devices' => $devices];
    }

    /**
     * Admins who may act on something (super-admins always).
     *
     * @return Collection<int, Admin>
     */
    public function adminsWith(string $permission): Collection
    {
        return Admin::query()->get()->filter(function (Admin $admin) use ($permission) {
            try {
                return $admin->hasRole('super-admin', 'admin_api') || $admin->hasPermissionTo($permission, 'admin_api');
            } catch (Throwable) {
                return false; // the permission doesn't exist (yet) — nobody holds it
            }
        })->values();
    }

    /**
     * @return array<string, string> locale => text, for every language the app supports
     */
    public function inEveryLanguage(string $key, array $variables): array
    {
        $texts = [];

        foreach (LocaleResolver::supported() as $locale) {
            $texts[$locale] = (string) __('notifications.'.$key, $variables, $locale);
        }

        return $texts;
    }

    private function push(Model $receiver, string $title, string $body, array $variables, array $data): void
    {
        if (! method_exists($receiver, 'notificationDevices')) {
            return;
        }

        try {
            $playerIds = $receiver->notificationDevices()->pluck('player_id')->all();

            if ($playerIds === []) {
                return;
            }

            $headings = $this->inEveryLanguage($title, $variables);
            $contents = $this->inEveryLanguage($body, $variables);

            $send = fn () => sendPushNotification(message: $contents, playerIds: $playerIds, title: $headings, data: $data);

            // The HTTP call to OneSignal happens after the response is sent, so a slow push service never slows down
            // (or fails) a payment. Already after the response (later()): now — deferring again would drop it.
            if (self::$runningLater) {
                $send();
            } else {
                defer($send);
            }
        } catch (Throwable $e) {
            Log::error('[NotificationCenter] push failed: '.$e->getMessage());
        }
    }

    /**
     * @return list<Model>
     */
    private function normalize(mixed $receivers): array
    {
        if ($receivers === null) {
            return [];
        }

        if ($receivers instanceof Model) {
            return [$receivers];
        }

        return collect($receivers)->filter(fn ($r) => $r instanceof Model)->values()->all();
    }
}
