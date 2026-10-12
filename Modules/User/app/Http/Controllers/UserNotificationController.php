<?php

namespace Modules\User\Http\Controllers;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\NotificationDevice;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use App\Support\LocaleResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\ValidationException;
use Modules\User\Jobs\SendDashboardNotificationJob;
use Modules\User\Models\User;

/**
 * Dashboard → Customers → send a notification: to one customer, to a chosen group, or to all active customers at
 * once. One title and one text per language (the same translation system as every notification: each customer reads
 * their own language), delivered in the in-app list, in real time (Pusher) and — when asked — as a push (OneSignal).
 */
class UserNotificationController extends Controller implements HasMiddleware
{
    /** Customers handled per round while sending to many. */
    private const CHUNK = 200;

    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('users', [
            ['send-notification', ['store', 'audience']],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $locales = LocaleResolver::supported();

        $data = $request->validate([
            'audience' => ['required', 'in:user,users,all'],
            'user_ids' => ['required_if:audience,user,users', 'array', 'max:5000'],
            'user_ids.*' => ['integer', 'distinct'],
            'title' => ['required', 'array'],
            'title.*' => ['nullable', 'string', 'max:120'],
            'message' => ['required', 'array'],
            'message.*' => ['nullable', 'string', 'max:500'],
            'push' => ['sometimes', 'boolean'],
        ]);

        // The chosen customers must all exist — checked in one query (a per-id `exists` rule would run one query per
        // customer, up to 5000), and "one customer" means exactly one.
        if ($data['audience'] !== 'all') {
            $ids = $data['user_ids'] ?? [];

            if ($data['audience'] === 'user' && count($ids) !== 1) {
                throw ValidationException::withMessages(['user_ids' => __('validation.size.array', ['attribute' => 'user_ids', 'size' => 1])]);
            }

            if (User::query()->whereIn('id', $ids)->count() !== count($ids)) {
                throw ValidationException::withMessages(['user_ids' => __('validation.exists', ['attribute' => 'user_ids'])]);
            }
        }

        $titles = $this->perLocale($data['title'], $locales, 'title');
        $bodies = $this->perLocale($data['message'], $locales, 'message');
        $push = (bool) ($data['push'] ?? true);

        $query = User::query()->when(
            $data['audience'] === 'all',
            fn ($q) => $q->where('status', UserStatus::Active),
            fn ($q) => $q->whereIn('id', $data['user_ids'] ?? []),
        );

        $recipients = (clone $query)->count();

        // Queued, one job per round of customers: the admin gets the answer at once, a round that fails is retried on its
        // own, and the sending does not depend on this request staying alive (a queue worker must be running).
        (clone $query)->orderBy('id')->pluck('id')->chunk(self::CHUNK)->each(
            fn ($ids) => SendDashboardNotificationJob::dispatch($ids->values()->all(), $titles, $bodies, $push),
        );

        return ApiResponse::success([
            'recipients' => $recipients,
            'devices' => $push ? $this->deviceCount($query) : 0,
            'push' => $push,
        ], __('api.created'));
    }

    /** How many customers "everyone" reaches right now (every active one), for the modal's text. */
    public function audience(): JsonResponse
    {
        return ApiResponse::success(['active' => User::query()->where('status', UserStatus::Active)->count()], __('api.retrieved'));
    }

    /**
     * Every language the app supports gets a text: one the admin left empty reads as the first one they wrote, so a
     * customer never gets a blank notification (the in-app list does the same fallback when it is shown).
     *
     * @param  array<string, string|null>  $texts
     * @param  list<string>  $locales
     * @return array<string, string>
     */
    private function perLocale(array $texts, array $locales, string $field): array
    {
        $texts = array_filter(array_map(fn ($text) => trim((string) $text), $texts), fn ($text) => $text !== '');

        if ($texts === []) {
            throw ValidationException::withMessages([$field => __('validation.required', ['attribute' => $field])]);
        }

        $first = reset($texts);
        $out = [];

        foreach ($locales as $locale) {
            $out[$locale] = $texts[$locale] ?? $first;
        }

        return $out;
    }

    private function deviceCount($query): int
    {
        return NotificationDevice::query()
            ->where('owner_type', (new User)->getMorphClass())
            ->whereIn('owner_id', (clone $query)->select('users.id'))
            ->distinct('player_id')->count('player_id');
    }
}
