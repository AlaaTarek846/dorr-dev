<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use App\Models\NotificationDevice;
use App\Services\Notifications\NotificationCenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\User\Jobs\SendDashboardNotificationJob;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Dashboard → Customers → send a notification: one customer, a chosen group, or every active customer — one text per
 * language, delivered in the in-app list (each customer reads their own language) and as an OneSignal push that carries
 * every language at once.
 */
class UserNotificationTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        Config::set('services.onesignal.app_id', 'app-id');
        Config::set('services.onesignal.rest_api_key', 'key');
        Http::fake(['api.onesignal.com/*' => Http::response(['id' => 'x'], 200)]);

        $flag = Flag::create(['code' => 'sa']);
        $currency = Currency::create(['code' => 'SAR', 'symbol' => 'ر.س']);
        Country::create([
            'code' => 'SA', 'code_alpha3' => 'SAU', 'dial_code' => '+966', 'phone_starts_with' => '5', 'phone_length' => 9,
            'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $currency->id, 'status' => true,
        ]);

        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        $make = function (string $phone, string $locale, UserStatus $status = UserStatus::Active) {
            $user = User::query()->create(['phone' => $phone, 'phone_verified_at' => now(), 'status' => $status]);
            $user->forceFill(['locale' => $locale])->save();

            return $user;
        };
        $this->alice = $make('+966501111111', 'en');
        $this->bob = $make('+966502222222', 'ar');
        $this->carol = $make('+966503333333', 'en', UserStatus::Inactive);

        NotificationDevice::query()->create(['owner_type' => $this->alice->getMorphClass(), 'owner_id' => $this->alice->id, 'player_id' => 'player-alice', 'platform' => 'android']);
        NotificationDevice::query()->create(['owner_type' => $this->bob->getMorphClass(), 'owner_id' => $this->bob->id, 'player_id' => 'player-bob', 'platform' => 'android']);
        NotificationDevice::query()->create(['owner_type' => $this->carol->getMorphClass(), 'owner_id' => $this->carol->id, 'player_id' => 'player-carol', 'platform' => 'android']);
    }

    /** @param list<string> $permissions */
    private function admin(array $permissions): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->app['auth']->forgetGuards();

        $admin = Admin::create(['name' => 'Agent '.uniqid(), 'email' => 'a'.uniqid().'@x.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');
    }

    /** @return array<string, mixed> */
    private function payload(array $extra = []): array
    {
        return array_merge([
            'title' => ['en' => 'Big news', 'ar' => 'خبر مهم'],
            'message' => ['en' => 'Something new is here.', 'ar' => 'في جديد عندنا.'],
        ], $extra);
    }

    public function test_it_needs_its_own_permission(): void
    {
        $this->admin(['users.view']);
        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'all']))->assertForbidden();
    }

    public function test_one_customer_gets_it_in_their_own_language_and_as_a_push(): void
    {
        $this->admin(['users.send-notification']);

        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'user', 'user_ids' => [$this->bob->id]]))
            ->assertOk()->assertJsonPath('data.recipients', 1)->assertJsonPath('data.devices', 1);

        // Stored as one text per language: the in-app list shows each customer their own.
        $stored = $this->bob->notifications()->firstOrFail();
        $this->assertSame('raw', $stored->data['type']);
        $this->assertSame('خبر مهم', $stored->data['title']['ar']);
        $this->assertSame('Big news', $stored->data['title']['en']);
        $this->assertSame('dashboard.message', $stored->data['event']);
        $this->assertSame(0, $this->alice->notifications()->count());

        // The push carries every language to that customer's device only.
        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'onesignal')
                && $request['include_player_ids'] === ['player-bob']
                && $request['headings']['ar'] === 'خبر مهم' && $request['headings']['en'] === 'Big news'
                && $request['contents']['ar'] === 'في جديد عندنا.' && $request['contents']['en'] === 'Something new is here.';
        });
    }

    public function test_a_chosen_group_gets_it(): void
    {
        $this->admin(['users.send-notification']);

        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'users', 'user_ids' => [$this->alice->id, $this->bob->id]]))
            ->assertOk()->assertJsonPath('data.recipients', 2);

        $this->assertSame(1, $this->alice->notifications()->count());
        $this->assertSame(1, $this->bob->notifications()->count());
        $this->assertSame(0, $this->carol->notifications()->count());
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => collect($request['include_player_ids'])->sort()->values()->all() === ['player-alice', 'player-bob']);
    }

    public function test_everyone_means_every_active_customer(): void
    {
        $this->admin(['users.send-notification']);

        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'all']))
            ->assertOk()->assertJsonPath('data.recipients', 2);

        $this->assertSame(1, $this->alice->notifications()->count());
        $this->assertSame(1, $this->bob->notifications()->count());
        $this->assertSame(0, $this->carol->notifications()->count(), 'an inactive customer is left out');
    }

    public function test_push_can_be_left_out_and_a_missing_language_falls_back_to_the_one_written(): void
    {
        $this->admin(['users.send-notification']);

        $this->postJson('/api/admin/v1/users/notify', [
            'audience' => 'user', 'user_ids' => [$this->alice->id], 'push' => false,
            'title' => ['en' => 'Only English', 'ar' => ''],
            'message' => ['en' => 'Text', 'ar' => null],
        ])->assertOk()->assertJsonPath('data.devices', 0);

        $stored = $this->alice->notifications()->firstOrFail();
        $this->assertSame('Only English', $stored->data['title']['ar'], 'the empty language reads as the written one');
        Http::assertNothingSent();
    }

    public function test_sending_is_queued_a_round_of_two_hundred_at_a_time_and_nothing_goes_out_until_the_worker_runs(): void
    {
        Queue::fake();
        $this->admin(['users.send-notification']);

        // The admin gets the answer at once; nothing was sent yet.
        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'all']))->assertOk()->assertJsonPath('data.recipients', 2);
        $this->assertSame(0, $this->bob->notifications()->count());
        Http::assertNothingSent();

        Queue::assertPushed(SendDashboardNotificationJob::class, 1);
        Queue::assertPushed(SendDashboardNotificationJob::class, fn ($job) => $job->userIds === [$this->alice->id, $this->bob->id]
            && $job->titles['ar'] === 'خبر مهم' && $job->push === true);

        // The worker runs it: in-app rows and one push carrying every language.
        $job = new SendDashboardNotificationJob([$this->alice->id, $this->bob->id], ['en' => 'Big news', 'ar' => 'خبر مهم'], ['en' => 'Text', 'ar' => 'نص'], true);
        $job->handle(app(NotificationCenter::class));

        $this->assertSame(1, $this->alice->notifications()->count());
        $this->assertSame(1, $this->bob->notifications()->count());
        Http::assertSent(fn (Request $request) => collect($request['include_player_ids'])->sort()->values()->all() === ['player-alice', 'player-bob']);
    }

    public function test_many_customers_become_several_jobs_and_a_chosen_group_only_its_own(): void
    {
        Queue::fake();
        $this->admin(['users.send-notification']);

        foreach (range(1, 205) as $i) {
            User::query()->create(['phone' => '+9665'.str_pad((string) $i, 8, '0', STR_PAD_LEFT), 'phone_verified_at' => now(), 'status' => UserStatus::Active]);
        }

        // 207 active customers = two rounds (200 + 7).
        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'all']))->assertOk()->assertJsonPath('data.recipients', 207);
        Queue::assertPushed(SendDashboardNotificationJob::class, 2);

        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'user', 'user_ids' => [$this->bob->id]]))->assertOk();
        Queue::assertPushed(SendDashboardNotificationJob::class, fn ($job) => $job->userIds === [$this->bob->id]);
    }

    public function test_the_modal_asks_how_many_customers_everyone_reaches(): void
    {
        $this->admin(['users.send-notification']);

        $this->getJson('/api/admin/v1/users/notify/audience')->assertOk()->assertJsonPath('data.active', 2);

        $this->admin(['users.view']);
        $this->getJson('/api/admin/v1/users/notify/audience')->assertForbidden();
    }

    public function test_a_push_sent_from_work_done_after_the_response_is_not_lost(): void
    {
        $this->withDefer();

        // e.g. the FAQ answer of an automatic support reply: worked out after the response, then announced by push.
        NotificationCenter::later(fn () => app(NotificationCenter::class)->send(
            $this->bob, 'support.ticket.auto', 'support_ticket_new_title', 'support_ticket_new_body', ['id' => 1, 'name' => 'x'], ['type' => 'support'],
        ));
        app(DeferredCallbackCollection::class)->invoke();

        Http::assertSent(fn (Request $request) => $request['include_player_ids'] === ['player-bob']);
    }

    public function test_validation(): void
    {
        $this->admin(['users.send-notification']);

        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'nobody']))->assertUnprocessable();
        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'users']))->assertUnprocessable();
        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'user', 'user_ids' => [999999]]))->assertUnprocessable();
        $this->postJson('/api/admin/v1/users/notify', ['audience' => 'all', 'title' => ['en' => ''], 'message' => ['en' => 'x']])->assertUnprocessable();
        $this->postJson('/api/admin/v1/users/notify', $this->payload(['audience' => 'user', 'user_ids' => [$this->alice->id, $this->bob->id]]))->assertUnprocessable();
    }
}
