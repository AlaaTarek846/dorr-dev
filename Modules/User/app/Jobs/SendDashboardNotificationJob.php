<?php

namespace Modules\User\Jobs;

use App\Services\Notifications\NotificationCenter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\User\Models\User;

/**
 * One round (up to 200 customers) of a notification written in the dashboard: the in-app list, the real-time message
 * and the OneSignal push, each customer reading it in their own language. The dashboard queues one job per round, so a
 * round that fails is retried on its own and never holds the others back (nor the admin who sent it).
 *
 * Runs on the default queue — a worker must be running (`php artisan queue:work`).
 */
class SendDashboardNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    /**
     * @param  list<int>  $userIds
     * @param  array<string, string>  $titles  locale => title
     * @param  array<string, string>  $bodies  locale => text
     */
    public function __construct(
        public array $userIds,
        public array $titles,
        public array $bodies,
        public bool $push = true,
    ) {}

    /** @return list<int> seconds to wait before each retry */
    public function backoff(): array
    {
        return [10, 60];
    }

    public function handle(NotificationCenter $center): void
    {
        $users = User::query()->whereIn('id', $this->userIds)->get();

        $center->sendRaw($users, 'dashboard.message', $this->titles, $this->bodies, ['type' => 'dashboard_message'], $this->push);
    }
}
