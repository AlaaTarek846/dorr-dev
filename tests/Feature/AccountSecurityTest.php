<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Enums\VerificationType;
use App\Models\AccountSession;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\NotificationDevice;
use App\Models\SecurityEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\User\Models\User;
use Modules\Wallet\Services\PinService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;
use Throwable;

/**
 * Account security (spec items 27–29): intrusion alerts, the account's security log, and the devices it is signed in
 * on — each with its own token, any of them signed out from the app or the dashboard.
 */
class AccountSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

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

        $this->user = User::query()->create(['phone' => '+966501234567', 'phone_verified_at' => now(), 'status' => UserStatus::Active]);
    }

    /** Signs in through the app's OTP flow from the given device; returns the token. */
    private function signIn(string $deviceId, string $name = 'Pixel 8'): string
    {
        $this->user->verificationCodes()->create(['type' => VerificationType::Phone->value, 'code' => '1234', 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/mobile/v1/auth/verify', ['dial_code' => '+966', 'phone' => '501234567', 'code' => '1234'], $this->device($deviceId, $name))
            ->assertOk()->json('data.token');
    }

    /** @return array<string, string> */
    private function device(string $deviceId, string $name = 'Pixel 8'): array
    {
        return ['X-Device-Id' => $deviceId, 'X-Device-Name' => $name, 'X-Platform' => 'android', 'X-App-Version' => '1.4.0'];
    }

    private function as(string $token, string $method, string $uri, array $body = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, $uri, $body, ['Authorization' => 'Bearer '.$token]);
    }

    private function alerts(string $kind): int
    {
        return $this->user->notifications()->get()->where('data.event', 'security.alert')->where('data.data.alert', $kind)->count();
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

    // ------------------------------------------------------------------ devices

    public function test_each_device_keeps_its_own_sign_in(): void
    {
        $phone = $this->signIn('device-phone', 'Pixel 8');
        $tablet = $this->signIn('device-tablet', 'Galaxy Tab');

        // Signing in on the tablet no longer signs the phone out.
        $this->as($phone, 'GET', '/api/mobile/v1/auth/me')->assertOk();
        $this->as($tablet, 'GET', '/api/mobile/v1/auth/me')->assertOk();

        $devices = $this->as($tablet, 'GET', '/api/mobile/v1/security/devices')->assertOk()->json('data');
        $this->assertCount(2, $devices);
        $this->assertSame('Galaxy Tab', $devices[0]['device_name']);
        $this->assertTrue($devices[0]['current'], 'this device comes first, marked as current');
        $this->assertFalse($devices[1]['current']);
        $this->assertSame('android', $devices[1]['platform']);
        $this->assertSame('1.4.0', $devices[1]['app_version']);
        $this->assertStringContainsString('•••', (string) $devices[1]['ip'], 'the owner sees a partly hidden IP');
    }

    public function test_the_same_device_signing_in_again_replaces_its_old_session(): void
    {
        $old = $this->signIn('device-phone');
        $new = $this->signIn('device-phone');

        $this->as($old, 'GET', '/api/mobile/v1/auth/me')->assertUnauthorized();
        $this->as($new, 'GET', '/api/mobile/v1/auth/me')->assertOk();
        $this->assertSame(1, AccountSession::query()->of($this->user)->active()->count());
        $this->assertSame(AccountSession::REASON_REPLACED, AccountSession::query()->of($this->user)->whereNotNull('revoked_at')->value('revoked_reason'));
    }

    public function test_past_the_device_limit_the_oldest_is_signed_out(): void
    {
        Config::set('auth_flow.max_sessions', 3);

        $first = $this->signIn('d1');
        $this->travel(1)->minutes();
        $this->signIn('d2');
        $this->travel(1)->minutes();
        $this->signIn('d3');
        $this->travel(1)->minutes();
        $this->signIn('d4');

        $this->assertSame(3, AccountSession::query()->of($this->user)->active()->count());
        $this->as($first, 'GET', '/api/mobile/v1/auth/me')->assertUnauthorized();
        $this->assertSame(1, SecurityEvent::query()->of($this->user)->where('type', SecurityEvent::SESSION_LIMIT)->count());
    }

    public function test_signing_a_device_out_ends_its_token_and_its_pushes(): void
    {
        $phone = $this->signIn('device-phone');
        $tablet = $this->signIn('device-tablet', 'Galaxy Tab');

        // Each device registered its push id together with its device id.
        NotificationDevice::query()->create(['owner_type' => $this->user->getMorphClass(), 'owner_id' => $this->user->id, 'player_id' => 'player-tablet', 'device_id' => 'device-tablet', 'platform' => 'android']);
        NotificationDevice::query()->create(['owner_type' => $this->user->getMorphClass(), 'owner_id' => $this->user->id, 'player_id' => 'player-phone', 'device_id' => 'device-phone', 'platform' => 'android']);

        $tabletSession = AccountSession::query()->of($this->user)->active()->where('device_id', 'device-tablet')->firstOrFail();

        $response = $this->as($phone, 'DELETE', "/api/mobile/v1/security/devices/{$tabletSession->id}")->assertOk();
        $this->assertFalse($response->json('data.signed_out_current'));
        $this->assertCount(1, $response->json('data.devices'));

        $this->as($tablet, 'GET', '/api/mobile/v1/auth/me')->assertUnauthorized();
        $this->as($phone, 'GET', '/api/mobile/v1/auth/me')->assertOk();
        $this->assertSame(['player-phone'], $this->user->notificationDevices()->pluck('player_id')->all());

        $event = SecurityEvent::query()->of($this->user)->where('type', SecurityEvent::DEVICE_SIGNED_OUT)->firstOrFail();
        $this->assertSame('Galaxy Tab', $event->meta['device']);
    }

    public function test_a_device_of_another_account_cannot_be_signed_out(): void
    {
        $token = $this->signIn('device-phone');
        $other = User::query()->create(['phone' => '+966509999999', 'phone_verified_at' => now(), 'status' => UserStatus::Active]);
        $otherToken = $other->createToken('mobile-app')->plainTextToken;
        $this->as($otherToken, 'GET', '/api/mobile/v1/security/devices')->assertOk();
        $otherSession = AccountSession::query()->of($other)->firstOrFail();

        $this->as($token, 'DELETE', "/api/mobile/v1/security/devices/{$otherSession->id}")->assertNotFound();
        $this->as($otherToken, 'GET', '/api/mobile/v1/auth/me')->assertOk();
    }

    public function test_sign_out_every_other_device(): void
    {
        $a = $this->signIn('d1');
        $b = $this->signIn('d2');
        $c = $this->signIn('d3');

        $this->as($c, 'POST', '/api/mobile/v1/security/devices/logout-others')
            ->assertOk()->assertJsonPath('data.count', 2)->assertJsonCount(1, 'data.devices');

        $this->as($a, 'GET', '/api/mobile/v1/auth/me')->assertUnauthorized();
        $this->as($b, 'GET', '/api/mobile/v1/auth/me')->assertUnauthorized();
        $this->as($c, 'GET', '/api/mobile/v1/auth/me')->assertOk();
        $this->assertSame(1, SecurityEvent::query()->of($this->user)->where('type', SecurityEvent::OTHER_DEVICES_SIGNED_OUT)->count());
    }

    public function test_tokens_from_before_this_feature_show_up_as_devices(): void
    {
        $legacy = $this->user->createToken('mobile-app')->plainTextToken;
        $this->user->createToken('mobile-app');

        $devices = $this->as($legacy, 'GET', '/api/mobile/v1/security/devices')->assertOk()->json('data');

        $this->assertCount(2, $devices);
        $this->assertTrue($devices[0]['current']);
    }

    public function test_a_device_whose_token_was_deleted_elsewhere_is_no_longer_listed(): void
    {
        $this->signIn('d1');
        $kept = $this->signIn('d2');

        // E.g. the account was deleted and restored, or support blocked it: tokens deleted directly.
        AccountSession::query()->of($this->user)->where('device_id', 'd1')->firstOrFail()->token()->delete();

        $this->as($kept, 'GET', '/api/mobile/v1/security/devices')->assertOk()->assertJsonCount(1, 'data');
        $this->assertNotNull(AccountSession::query()->of($this->user)->where('device_id', 'd1')->value('revoked_at'));
    }

    public function test_logout_ends_only_this_device(): void
    {
        $a = $this->signIn('d1');
        $b = $this->signIn('d2');

        $this->as($a, 'POST', '/api/mobile/v1/auth/logout')->assertOk();

        $this->as($a, 'GET', '/api/mobile/v1/auth/me')->assertUnauthorized();
        $this->as($b, 'GET', '/api/mobile/v1/auth/me')->assertOk();
        $this->assertSame(1, SecurityEvent::query()->of($this->user)->where('type', SecurityEvent::LOGOUT)->count());
    }

    // ------------------------------------------------------------------ intrusion alerts

    public function test_a_new_device_is_announced_but_the_first_sign_in_and_a_known_device_are_not(): void
    {
        $this->signIn('d1');
        $this->assertSame(0, $this->alerts('new_device'), 'the very first sign-in is not "new"');

        $this->signIn('d2', 'Unknown Phone');
        $this->assertSame(1, $this->alerts('new_device'));
        $this->assertSame(1, SecurityEvent::query()->of($this->user)->where('type', SecurityEvent::LOGIN_NEW_DEVICE)->count());

        $this->signIn('d1');
        $this->assertSame(1, $this->alerts('new_device'), 'a device used before is not announced');
    }

    public function test_repeated_wrong_codes_warn_the_owner_once(): void
    {
        $this->user->verificationCodes()->create(['type' => VerificationType::Phone->value, 'code' => '1234', 'expires_at' => now()->addMinutes(10), 'attempts' => 0]);

        foreach (range(1, 4) as $_) {
            $this->postJson('/api/mobile/v1/auth/verify', ['dial_code' => '+966', 'phone' => '501234567', 'code' => '0000'])->assertUnprocessable();
        }

        $this->assertGreaterThanOrEqual(3, SecurityEvent::query()->of($this->user)->whereIn('type', [SecurityEvent::OTP_FAILED, SecurityEvent::OTP_LOCKED])->count());
        $this->assertSame(1, $this->alerts('failed_attempts'), 'one alert per window, not one per attempt');
    }

    public function test_many_codes_requested_warn_the_owner(): void
    {
        Config::set('auth_flow.security_code_requests', 3);

        foreach (range(1, 3) as $_) {
            SecurityEvent::query()->create(['owner_type' => $this->user->getMorphClass(), 'owner_id' => $this->user->id, 'type' => SecurityEvent::OTP_REQUESTED, 'created_at' => now()]);
        }
        $this->assertSame(0, $this->alerts('code_requests'));

        $this->postJson('/api/mobile/v1/auth/otp', ['dial_code' => '+966', 'phone' => '501234567'])->assertOk();

        $this->assertSame(1, $this->alerts('code_requests'));
        $this->assertSame('login', SecurityEvent::query()->of($this->user)->where('type', SecurityEvent::OTP_REQUESTED)->latest('id')->first()->meta['flow']);
    }

    public function test_wrong_wallet_pins_are_logged(): void
    {
        $pins = app(PinService::class);
        $pins->set($this->user, '135790');

        foreach (range(1, 2) as $_) {
            try {
                $pins->verify($this->user, '000000');
            } catch (Throwable) {
            }
        }

        $types = SecurityEvent::query()->of($this->user)->pluck('type')->all();
        $this->assertContains(SecurityEvent::PIN_FAILED, $types);
        $this->assertContains(SecurityEvent::PIN_LOCKED, $types);
    }

    // ------------------------------------------------------------------ security log

    public function test_the_owner_reads_their_security_log_newest_first_and_paged(): void
    {
        $token = $this->signIn('d1');
        foreach (range(1, 25) as $i) {
            SecurityEvent::query()->create(['owner_type' => $this->user->getMorphClass(), 'owner_id' => $this->user->id, 'type' => SecurityEvent::PIN_FAILED, 'meta' => ['attempt' => $i], 'created_at' => now()->addSeconds($i)]);
        }
        $someoneElse = User::query()->create(['phone' => '+966509999999', 'phone_verified_at' => now(), 'status' => UserStatus::Active]);
        SecurityEvent::query()->create(['owner_type' => $someoneElse->getMorphClass(), 'owner_id' => $someoneElse->id, 'type' => SecurityEvent::LOGIN, 'created_at' => now()]);

        $page = $this->as($token, 'GET', '/api/mobile/v1/security/events')->assertOk();
        $this->assertCount(20, $page->json('data'));
        $this->assertSame(26, $page->json('pagination.total'), 'their 25 + their own sign-in, nobody else\'s');
        $this->assertSame(25, $page->json('data.0.meta.attempt'));
        $this->assertTrue($page->json('data.0.warning'));
        $this->assertSame('Attempt 25', $page->json('data.0.description'));

        $warnings = $this->as($token, 'GET', '/api/mobile/v1/security/events?warnings=1&per_page=50')->assertOk();
        $this->assertSame(25, $warnings->json('pagination.total'));
    }

    // ------------------------------------------------------------------ dashboard

    public function test_the_dashboard_needs_its_own_permissions(): void
    {
        $this->signIn('d1');

        $this->admin(['users.view']);
        $this->getJson('/api/admin/v1/security-events')->assertForbidden();
        $this->getJson("/api/admin/v1/users/{$this->user->id}/sessions")->assertForbidden();
    }

    public function test_the_dashboard_reads_the_log_with_filters(): void
    {
        $this->signIn('d1');
        $this->signIn('d2', 'Stranger Phone');

        $this->admin(['security-events.view']);
        $all = $this->getJson("/api/admin/v1/security-events?user_id={$this->user->id}")->assertOk();
        $this->assertSame(2, $all->json('pagination.total'));
        $this->assertSame($this->user->id, $all->json('data.0.user.id'));

        $this->getJson('/api/admin/v1/security-events?warnings=1')->assertOk()->assertJsonPath('pagination.total', 1)
            ->assertJsonPath('data.0.type', SecurityEvent::LOGIN_NEW_DEVICE);
        $this->getJson('/api/admin/v1/security-events?search=Stranger')->assertOk()->assertJsonPath('pagination.total', 1);

        $id = $all->json('data.0.id');
        $this->getJson("/api/admin/v1/security-events/{$id}")->assertOk()->assertJsonPath('data.session.device_name', 'Stranger Phone');
    }

    public function test_support_signs_a_customers_devices_out(): void
    {
        $a = $this->signIn('d1');
        $b = $this->signIn('d2');
        $sessionA = AccountSession::query()->of($this->user)->where('device_id', 'd1')->firstOrFail();

        $this->admin(['users.manage-sessions']);
        $this->getJson("/api/admin/v1/users/{$this->user->id}/sessions")->assertOk()->assertJsonCount(2, 'data.active');

        $this->deleteJson("/api/admin/v1/users/{$this->user->id}/sessions/{$sessionA->id}")
            ->assertOk()->assertJsonCount(1, 'data.active')->assertJsonPath('data.recent.0.revoked_reason', AccountSession::REASON_ADMIN);
        $this->assertSame(1, SecurityEvent::query()->of($this->user)->where('type', SecurityEvent::ADMIN_SIGNED_OUT)->count());

        $this->postJson("/api/admin/v1/users/{$this->user->id}/sessions/logout-all")->assertOk()->assertJsonCount(0, 'data.active');

        $this->as($a, 'GET', '/api/mobile/v1/auth/me')->assertUnauthorized();
        $this->as($b, 'GET', '/api/mobile/v1/auth/me')->assertUnauthorized();
    }
}
