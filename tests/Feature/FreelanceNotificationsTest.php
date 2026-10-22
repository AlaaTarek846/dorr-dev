<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-007: the freelancer is notified like a rider or a captain — the same list, unread count and push
 * devices, their own live channel and OneSignal app, and one catalog of freelance events.
 */
class FreelanceNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private Country $saudi;

    private Freelancer $me;

    private User $client;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $this->saudi = Country::create([
            'code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true,
            'flag_id' => Flag::create(['code' => 'sa'])->id,
            'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id,
        ]);
        foreach (['freelance', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();

        $this->me = Freelancer::query()->create(['name' => 'Sara Hassan', 'phone' => '+966500000077', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified', 'locale' => 'ar']);
        $this->client = User::query()->create(['name' => 'Mona Ali', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now(), 'locale' => 'en']);
    }

    private function asFreelancer(?Freelancer $freelancer = null): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($freelancer ?? $this->me, [], 'freelance_api');
    }

    public function test_the_freelancer_has_the_same_notification_list_and_devices(): void
    {
        $this->getJson('/api/freelance/v1/notifications')->assertUnauthorized();
        $this->asFreelancer();

        $this->postJson('/api/freelance/v1/notifications/devices', ['player_id' => 'fl-device-1', 'platform' => 'android'])->assertSuccessful();
        $this->assertSame(1, $this->me->notificationDevices()->count());
        $this->assertSame('freelancer', $this->me->notificationDevices()->first()->owner_type);

        app(FreelanceNotifier::class)->notify($this->me, 'offer.accepted', ['project' => 'Logo design'], ['project_id' => 7]);
        $this->getJson('/api/freelance/v1/notifications/unread-count')->assertOk()->assertJsonPath('data.count', 1);

        $list = $this->getJson('/api/freelance/v1/notifications')->assertOk()->json('data');
        $this->assertSame('freelance.offer.accepted', $list[0]['event'] ?? ($list[0]['data']['event'] ?? null));
        $id = $list[0]['id'];

        $this->postJson("/api/freelance/v1/notifications/{$id}/read")->assertOk();
        $this->getJson('/api/freelance/v1/notifications/unread-count')->assertJsonPath('data.count', 0);

        $this->deleteJson('/api/freelance/v1/notifications/devices', ['player_id' => 'fl-device-1'])->assertSuccessful();
        $this->assertSame(0, $this->me->notificationDevices()->count());
    }

    public function test_nobody_sees_another_freelancers_notifications(): void
    {
        app(FreelanceNotifier::class)->notify($this->me, 'offer.accepted', ['project' => 'Logo']);
        $other = Freelancer::query()->create(['name' => 'Lina', 'phone' => '+966500000078', 'country_id' => $this->saudi->id, 'phone_verified_at' => now()]);

        $this->asFreelancer($other);
        $this->getJson('/api/freelance/v1/notifications/unread-count')->assertJsonPath('data.count', 0);
        $id = $this->me->notifications()->value('id');
        $this->postJson("/api/freelance/v1/notifications/{$id}/read")->assertNotFound();
    }

    public function test_the_realtime_config_gives_the_freelancers_own_channel_and_app(): void
    {
        config([
            'broadcasting.default' => 'pusher',
            'broadcasting.connections.pusher.key' => 'abc123', 'broadcasting.connections.pusher.options.cluster' => 'eu',
            'services.onesignal.app_id' => 'shared-app', 'services.onesignal.rest_api_key' => 'shared-rest',
        ]);
        $this->getJson('/api/freelance/v1/realtime-config')->assertUnauthorized();

        $this->asFreelancer();
        $this->getJson('/api/freelance/v1/realtime-config')->assertOk()
            ->assertJsonPath('data.enabled', true)->assertJsonPath('data.key', 'abc123')
            ->assertJsonPath('data.onesignal_app_id', 'shared-app')->assertJsonMissingPath('data.secret')
            ->assertJsonPath('data.channel', 'private-Modules.Freelance.Models.Freelancer.'.$this->me->id);

        config(['services.onesignal.freelance_app_id' => 'freelance-app', 'services.onesignal.freelance_rest_api_key' => 'freelance-rest']);
        $this->getJson('/api/freelance/v1/realtime-config')->assertJsonPath('data.onesignal_app_id', 'freelance-app');
    }

    public function test_pushes_reach_each_account_through_its_own_onesignal_app_in_its_own_catalog_text(): void
    {
        config([
            'services.onesignal.app_id' => 'shared-app', 'services.onesignal.rest_api_key' => 'shared-rest',
            'services.onesignal.freelance_app_id' => 'freelance-app', 'services.onesignal.freelance_rest_api_key' => 'freelance-rest',
        ]);
        $this->withoutDefer();
        $this->me->notificationDevices()->create(['player_id' => 'fl-device-1']);
        $this->client->notificationDevices()->create(['player_id' => 'client-device-1']);

        $sent = [];
        Http::fake(function ($request) use (&$sent) {
            if (str_contains($request->url(), 'onesignal.com')) {
                $sent[] = ['auth' => $request->header('Authorization')[0] ?? null, 'body' => $request->data()];
            }

            return Http::response(['id' => 'x'], 200);
        });

        $notifier = app(FreelanceNotifier::class);
        $notifier->notify($this->me, 'payment.released', ['project' => 'Logo', 'amount' => '500 SAR'], ['project_id' => 7]);
        $notifier->notify($this->client, 'offer.received', ['project' => 'Logo', 'name' => 'Sara']);

        $byDevice = collect($sent)->keyBy(fn ($s) => $s['body']['include_player_ids'][0]);
        $this->assertSame('freelance-app', $byDevice['fl-device-1']['body']['app_id']);
        $this->assertSame('Basic freelance-rest', $byDevice['fl-device-1']['auth']);
        $this->assertSame('تم تحويل الدفعة', $byDevice['fl-device-1']['body']['headings']['ar']);
        $this->assertSame('Payment released', $byDevice['fl-device-1']['body']['headings']['en']);
        $this->assertStringContainsString('500 SAR', $byDevice['fl-device-1']['body']['contents']['en']);
        $this->assertSame('shared-app', $byDevice['client-device-1']['body']['app_id'], 'clients keep the shared app');
        $this->assertSame('New offer', $byDevice['client-device-1']['body']['headings']['en']);
    }

    public function test_a_chat_message_from_the_client_now_notifies_the_freelancer(): void
    {
        $room = app(FreelanceChatService::class)->open($this->client, $this->me, 'project', 9);

        $this->asFreelancer($this->me);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client, [], 'user_api');
        $this->postJson("/api/mobile/v1/freelance/chat/conversations/{$room->uuid}/messages", ['body' => 'Can you start Monday?'])->assertCreated();

        $this->assertSame(1, $this->me->unreadNotifications()->count());
        $this->assertSame('freelance.message', $this->me->notifications()->first()->data['event']);
    }

    public function test_the_catalog_has_a_text_in_both_languages_and_checks_its_input(): void
    {
        foreach (FreelanceNotifier::keys() as $key) {
            $lang = 'freelance_'.str_replace('.', '_', $key);
            foreach (['en', 'ar'] as $locale) {
                foreach (['title', 'body'] as $part) {
                    $this->assertNotSame("notifications.{$lang}_{$part}", __("notifications.{$lang}_{$part}", [], $locale), "{$key} has no {$locale} {$part}");
                }
            }
        }

        $notifier = app(FreelanceNotifier::class);
        $this->expectException(\InvalidArgumentException::class);
        $notifier->notify($this->me, 'payment.released', ['project' => 'Logo']); // the amount is missing
    }

    public function test_an_unknown_event_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(FreelanceNotifier::class)->notify($this->me, 'offer.teleported');
    }

    public function test_signing_out_forgets_the_phones_push_id_and_a_block_forgets_them_all(): void
    {
        $this->me->notificationDevices()->create(['player_id' => 'fl-device-1']);
        $this->me->notificationDevices()->create(['player_id' => 'fl-device-2']);

        $token = $this->me->createToken('freelance:android')->plainTextToken;
        $this->withToken($token)->postJson('/api/freelance/v1/auth/logout', ['player_id' => 'fl-device-1'])->assertOk();
        $this->assertSame(['fl-device-2'], $this->me->notificationDevices()->pluck('player_id')->all());

        app(\Modules\Freelance\Services\Account\FreelancerAuthService::class)->endAllSessions($this->me);
        $this->assertSame(0, $this->me->notificationDevices()->count());
    }
}
