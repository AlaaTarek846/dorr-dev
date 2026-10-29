<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Chat\Database\Seeders\ChatVerificationSeeder;
use Modules\Chat\Models\Verification\ChatVerificationBadge;
use Modules\Chat\Models\Verification\ChatVerificationDocumentType;
use Modules\Chat\Models\Verification\ChatVerificationNotification;
use Modules\Chat\Models\Verification\ChatVerificationSetting;
use Modules\Chat\Support\ParticipantDirectory;
use Modules\Chat\Support\VerifiedAccounts;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Account verification, batch 2 (docs/verification_tasks.md AV-020 – AV-026): the badge turns on at the
 * track's moment (or the request's), early only after the identity check (AT-11), goes with a request that
 * closes unfinished (AT-04), is held, brought back, withdrawn and expires — each with its reason, trail and
 * notifications — and the ✔ follows next to the name at once.
 */
class ChatVerificationBadgesTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $friend;

    private Admin $staff;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake(['*' => Http::response([])]);

        $flag = Flag::create(['code' => 'eg']);
        $egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->user = User::create(['name' => 'Mona', 'phone' => '+201000000001', 'country_id' => $egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->friend = User::create(['name' => 'Omar', 'phone' => '+201000000002', 'country_id' => $egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);

        $this->seed(ChatVerificationSeeder::class);
        ChatVerificationSetting::current()->update(['paid_enabled' => true, 'paid_activation' => 'after_approval']);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $all = ['chat-verification-requests.view', 'chat-verification-requests.review', 'chat-verification-requests.approve', 'chat-verification-requests.early-activate', 'chat-verification-badges.view', 'chat-verification-badges.update'];
        foreach ($all as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $this->staff = Admin::create(['name' => 'Staff', 'email' => 's@example.com', 'password' => 'secret123', 'status' => 'active']);
        $this->staff->givePermissionTo($all);
    }

    public function test_the_badge_turns_on_at_the_tracks_moment_and_shows_next_to_the_name(): void
    {
        $base = $this->submitAndCheckIdentity();
        $this->assertFalse(VerifiedAccounts::hasUser($this->user->id)); // after approval, not yet

        $this->postJson("{$base}/approve")->assertOk()->assertJsonPath('data.badge.status', 'active')->assertJsonPath('data.badge.is_verified', true)->assertJsonPath('data.badge.type', 'standard');
        $this->assertTrue(VerifiedAccounts::hasUser($this->user->id));
        $this->assertTrue(ChatVerificationNotification::query()->where('event', 'badge_activated')->where('audience', 'user')->exists());

        // Next to the name: in the chat (as someone else sees them), on the account, in the account's own badge.
        $this->assertTrue(app(ParticipantDirectory::class)->profile($this->friend, 'user', $this->user->id)['is_verified']);
        $this->assertFalse(app(ParticipantDirectory::class)->profile($this->user, 'user', $this->friend->id)['is_verified']);
        Sanctum::actingAs($this->user, [], 'user_api');
        $this->getJson('/api/mobile/v1/chat/verification/badge', ['X-Country' => 'EG'])->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.is_verified', true);

        // The dashboard's list of accounts filters on it.
        Sanctum::actingAs($this->staff, [], 'admin_api');
        Permission::findOrCreate('users.view', 'admin_api');
        $this->staff->givePermissionTo('users.view');
        $ids = fn (string $q) => collect($this->getJson("/api/admin/v1/users?{$q}")->assertOk()->json('data'))->pluck('id')->all();
        $this->assertSame([$this->user->id], $ids('verified=1'));
        $this->assertSame([$this->friend->id], $ids('verified=0'));
    }

    public function test_early_activation_needs_the_identity_check_and_goes_when_the_request_is_rejected(): void
    {
        $uuid = $this->submit();
        $base = "/api/admin/v1/chat-verification/requests/{$uuid}";
        Sanctum::actingAs($this->staff, [], 'admin_api');
        $this->postJson("{$base}/start-review")->assertOk();

        // AT-11: no early badge before the first identity check.
        $this->postJson("{$base}/early-activate", ['status' => 'temporary', 'reason' => 'Public figure'])->assertUnprocessable()->assertJsonPath('error_code', 'chat_verification_identity_not_checked');
        $this->assertFalse(VerifiedAccounts::hasUser($this->user->id));

        $this->acceptAllDocuments($base);
        $this->postJson("{$base}/identity-check")->assertOk()->assertJsonPath('data.actions', fn ($a) => in_array('early_activate', $a, true));
        $this->postJson("{$base}/early-activate", ['status' => 'temporary', 'reason' => 'Public figure'])->assertOk()
            ->assertJsonPath('data.badge.status', 'temporary')->assertJsonPath('data.status', 'under_review');
        $this->assertTrue(VerifiedAccounts::hasUser($this->user->id));

        // AT-04: the request is rejected — the temporary badge it gave goes with it.
        $this->postJson("{$base}/reject", ['reason' => 'Accounts not owned'])->assertOk();
        $this->assertSame('withdrawn', ChatVerificationBadge::query()->sole()->status->value);
        $this->assertFalse(VerifiedAccounts::hasUser($this->user->id));
        $this->assertTrue(ChatVerificationNotification::query()->where('event', 'badge_withdrawn')->exists());
    }

    public function test_hold_back_change_withdraw_with_reasons_and_only_with_the_permission(): void
    {
        $this->postJson("{$this->submitAndCheckIdentity()}/approve")->assertOk();
        $badges = "/api/admin/v1/chat-verification/badges/{$this->user->id}";

        $this->getJson('/api/admin/v1/chat-verification/badges?status=active')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('pagination.counts.active', 1);
        $this->getJson($badges)->assertOk()->assertJsonPath('data.actions', ['change', 'suspend', 'withdraw']);

        $this->postJson("{$badges}/suspend")->assertUnprocessable();
        $this->postJson("{$badges}/suspend", ['reason' => 'Under investigation'])->assertOk()->assertJsonPath('data.badge.status', 'suspended')->assertJsonPath('data.badge.reason', 'Under investigation');
        $this->assertFalse(VerifiedAccounts::hasUser($this->user->id));
        $this->postJson("{$badges}/reactivate", ['reason' => 'Cleared'])->assertOk()->assertJsonPath('data.badge.status', 'active');
        $this->assertTrue(VerifiedAccounts::hasUser($this->user->id));

        $until = now()->addMonth()->startOfMinute();
        $this->postJson("{$badges}/change", ['type' => 'celebrity', 'expires_at' => $until->toIso8601String(), 'reason' => 'Celebrity deal'])->assertOk()
            ->assertJsonPath('data.badge.type', 'celebrity');
        $changed = AuditLog::query()->where('action', 'chat_verification.badge_changed')->sole();
        $this->assertSame(['standard', 'celebrity', 'Celebrity deal'], [$changed->before['type'], $changed->after['type'], $changed->reason]);

        // Someone who may only look can't change it — refused and logged.
        $viewer = Admin::create(['name' => 'V', 'email' => 'v@example.com', 'password' => 'secret123', 'status' => 'active']);
        $viewer->givePermissionTo('chat-verification-badges.view');
        Sanctum::actingAs($viewer, [], 'admin_api');
        $this->getJson($badges)->assertOk()->assertJsonPath('data.actions', []);
        $this->postJson("{$badges}/withdraw", ['reason' => 'x'])->assertForbidden();
        $this->assertTrue(AuditLog::query()->where('action', 'chat_verification.denied')->where('actor_id', $viewer->id)->exists());

        Sanctum::actingAs($this->staff, [], 'admin_api');
        $this->postJson("{$badges}/withdraw", ['reason' => 'Impersonation proven'])->assertOk()->assertJsonPath('data.badge.status', 'withdrawn')->assertJsonPath('data.actions', []);
        $this->assertSame(['active', 'suspended', 'active', 'active', 'withdrawn'], collect($this->getJson($badges)->json('data.history'))->pluck('to')->all());
    }

    public function test_a_badge_expires_at_its_end_and_a_request_can_set_its_own_moment(): void
    {
        $base = $this->submitAndCheckIdentity(); // track: after approval
        $this->postJson("{$base}/activation-timing", ['timing' => 'after_identity_check'])->assertUnprocessable();
        $this->postJson("{$base}/activation-timing", ['timing' => 'after_payment', 'reason' => 'Pays first'])->assertOk()
            ->assertJsonPath('data.activation_timing', 'after_payment')->assertJsonPath('data.activation_timing_custom', true);
        $this->postJson("{$base}/approve")->assertOk()->assertJsonPath('data.badge', null); // waits for the payment now

        $this->postJson("{$base}/activation-timing", ['timing' => 'after_approval', 'reason' => 'x'])->assertOk(); // still open (approved in principle)
        ChatVerificationBadge::query()->create(['user_id' => $this->user->id, 'status' => 'active', 'type' => 'standard', 'active_from' => now(), 'expires_at' => now()->addHour()]);
        $this->assertTrue(VerifiedAccounts::hasUser($this->user->id));

        $this->travel(2)->hours();
        $this->assertFalse(VerifiedAccounts::hasUser($this->user->id)); // past its end it no longer shows, even before the sweep
        $this->artisan('chat:verification-deadlines')->assertSuccessful();
        $this->assertSame('expired', ChatVerificationBadge::query()->sole()->status->value);
        $this->assertTrue(ChatVerificationNotification::query()->where('event', 'badge_expired')->where('audience', 'user')->exists());
    }

    // ---------------------------------------------------------------- helpers

    private function submit(): string
    {
        Sanctum::actingAs($this->user, [], 'user_api');
        $docs = [];
        foreach (ChatVerificationDocumentType::query()->get() as $type) {
            if (in_array('paid', $type->tracks, true)) {
                $docs[$type->id] = ['front' => UploadedFile::fake()->image('f.jpg'), 'back' => UploadedFile::fake()->image('b.jpg')];
            }
        }

        return $this->post('/api/mobile/v1/chat/verification/request', ['track' => 'paid', 'full_name' => 'Mona Ali', 'documents' => $docs], ['X-Country' => 'EG', 'Accept' => 'application/json'])
            ->assertCreated()->json('data.uuid');
    }

    private function acceptAllDocuments(string $base): void
    {
        foreach ($this->getJson($base)->json('data.documents') as $doc) {
            $this->postJson("{$base}/documents/{$doc['id']}/review", ['accepted' => true])->assertOk();
        }
    }

    /** A request under review with its identity checked, the staff signed in; returns its admin path. */
    private function submitAndCheckIdentity(): string
    {
        $base = '/api/admin/v1/chat-verification/requests/'.$this->submit();
        Sanctum::actingAs($this->staff, [], 'admin_api');
        $this->postJson("{$base}/start-review")->assertOk();
        $this->acceptAllDocuments($base);
        $this->postJson("{$base}/identity-check")->assertOk();

        return $base;
    }
}
