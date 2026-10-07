<?php

namespace Modules\Chat\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Modules\Chat\Console\ExpireUnansweredCalls;
use Modules\Chat\Console\PurgeChatMessages;
use Modules\Chat\Console\SendMessageReminders;
use Modules\Chat\Console\SendMomentReminders;
use Modules\Chat\Console\SendCalendarReminders;
use Modules\Chat\Console\SendQuietDigests;
use Modules\Chat\Console\SendTaskReminders;
use Modules\Chat\Console\SendScheduledMessages;
use Modules\Chat\Services\ChatThemeService;
use Modules\Chat\Support\ParticipantDirectory;
use Nwidart\Modules\Support\ModuleServiceProvider;

class ChatServiceProvider extends ModuleServiceProvider
{
    protected string $name = 'Chat';

    protected string $nameLower = 'chat';

    /**
     * @var string[]
     */
    protected array $commands = [
        ExpireUnansweredCalls::class,
        PurgeChatMessages::class,
        SendScheduledMessages::class,
        SendMessageReminders::class,
        SendQuietDigests::class,
        SendMomentReminders::class,
        SendTaskReminders::class,
        SendCalendarReminders::class,
    ];

    /**
     * @var string[]
     */
    protected array $providers = [
        EventServiceProvider::class,
        RouteServiceProvider::class,
    ];

    public function register(): void
    {
        parent::register();

        // One per request: primed with everyone a response mentions, then read while building it.
        $this->app->scoped(ParticipantDirectory::class);
        $this->app->scoped(ChatThemeService::class);
    }

    public function boot(): void
    {
        parent::boot();

        // What the chat sells on the wallet's payment screen (docs/remaining_chat.md ج.0).
        $purposes = $this->app->make(\Modules\Wallet\Support\Payments\CheckoutPurposes::class);
        $purposes->register(\Modules\Chat\Checkout\PortalListingPurpose::KEY, \Modules\Chat\Checkout\PortalListingPurpose::class);
        $purposes->register(\Modules\Chat\Checkout\ChannelVerificationPurpose::KEY, \Modules\Chat\Checkout\ChannelVerificationPurpose::class);

        // Scoped instances are only reset by Octane / queue workers — also drop it after each
        // HTTP request so a long-lived process (or a test making many requests) never reads a
        // previous request's names.
        Event::listen(RequestHandled::class, function () {
            $this->app->forgetInstance(ParticipantDirectory::class);
            $this->app->forgetInstance(ChatThemeService::class);
        });
    }

    protected function configureSchedules(Schedule $schedule): void
    {
        $schedule->command(ExpireUnansweredCalls::class)->everyMinute()->withoutOverlapping();
        $schedule->command(PurgeChatMessages::class)->hourly()->withoutOverlapping();
        $schedule->command(SendScheduledMessages::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendMessageReminders::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendQuietDigests::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendMomentReminders::class)->everyTenMinutes()->withoutOverlapping();
        $schedule->command(SendTaskReminders::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendCalendarReminders::class)->everyMinute()->withoutOverlapping();
    }
}
