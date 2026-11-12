<?php

namespace Modules\Chat\Providers;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Modules\Chat\Checkout\ChannelVerificationPurpose;
use Modules\Chat\Checkout\GroupHiddenMembersPurpose;
use Modules\Chat\Checkout\PortalListingPurpose;
use Modules\Chat\Console\ExpireUnansweredCalls;
use Modules\Chat\Console\PurgeChatMessages;
use Modules\Chat\Console\PurgeDeletedGroups;
use Modules\Chat\Console\RemindGroupSessions;
use Modules\Chat\Console\RemindGroupTasks;
use Modules\Chat\Console\SpawnRecurringTasks;
use Modules\Chat\Console\RealtimeCheck;
use Modules\Chat\Console\SendAttentionDigests;
use Modules\Chat\Console\SendCalendarReminders;
use Modules\Chat\Console\SendCommitmentReminders;
use Modules\Chat\Console\SendMessageReminders;
use Modules\Chat\Console\SendMomentReminders;
use Modules\Chat\Console\SendQuietDigests;
use Modules\Chat\Console\SendScheduledMessages;
use Modules\Chat\Console\SendTaskReminders;
use Modules\Chat\Console\SweepVerificationDeadlines;
use Modules\Chat\Console\SweepVoiceRooms;
use Modules\Chat\Console\SyncCallRecordings;
use Modules\Chat\Services\ChatThemeService;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Wallet\Support\Payments\CheckoutPurposes;
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
        PurgeDeletedGroups::class,
        SpawnRecurringTasks::class,
        RemindGroupTasks::class,
        RemindGroupSessions::class,
        RealtimeCheck::class,
        SendScheduledMessages::class,
        SendMessageReminders::class,
        SendQuietDigests::class,
        SendMomentReminders::class,
        SendTaskReminders::class,
        SendCommitmentReminders::class,
        SendAttentionDigests::class,
        SweepVoiceRooms::class,
        SyncCallRecordings::class,
        SendCalendarReminders::class,
        SweepVerificationDeadlines::class,
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
        $purposes = $this->app->make(CheckoutPurposes::class);
        $purposes->register(PortalListingPurpose::KEY, PortalListingPurpose::class);
        $purposes->register(ChannelVerificationPurpose::KEY, ChannelVerificationPurpose::class);
        $purposes->register(GroupHiddenMembersPurpose::KEY, GroupHiddenMembersPurpose::class);

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
        // Account verification: warn before a deadline, hold a request once it passed.
        $schedule->command(SweepVerificationDeadlines::class)->everyFiveMinutes()->withoutOverlapping();
        $schedule->command(PurgeChatMessages::class)->hourly()->withoutOverlapping();
        $schedule->command(PurgeDeletedGroups::class)->daily()->withoutOverlapping();
        $schedule->command(SpawnRecurringTasks::class)->everyFifteenMinutes()->withoutOverlapping();
        $schedule->command(RemindGroupTasks::class)->everyFiveMinutes()->withoutOverlapping();
        $schedule->command(RemindGroupSessions::class)->everyFiveMinutes()->withoutOverlapping();
        $schedule->command(SendScheduledMessages::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendMessageReminders::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendQuietDigests::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendMomentReminders::class)->everyTenMinutes()->withoutOverlapping();
        $schedule->command(SendTaskReminders::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendCommitmentReminders::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendAttentionDigests::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SweepVoiceRooms::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SyncCallRecordings::class)->everyMinute()->withoutOverlapping();
        $schedule->command(SendCalendarReminders::class)->everyMinute()->withoutOverlapping();
    }
}
