<?php

namespace App\Services\Notifications;

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
     * @return array<string, string>  locale => text, for every language the app supports
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

            // The HTTP call to OneSignal happens after the response is sent, so a slow push service
            // never slows down (or fails) a payment.
            defer(fn () => sendPushNotification(message: $contents, playerIds: $playerIds, title: $headings, data: $data));
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
