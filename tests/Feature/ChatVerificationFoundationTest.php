<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Chat\Database\Seeders\ChatVerificationSeeder;
use Modules\Chat\Models\ChatFeatureRule;
use Modules\Chat\Models\Verification\ChatVerificationDocumentType;
use Modules\Chat\Models\Verification\ChatVerificationNotification;
use Modules\Chat\Models\Verification\ChatVerificationSetting;
use Modules\Chat\Services\Verification\VerificationNotifier;
use Modules\User\Models\User;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Account verification, batch 0 (docs/verification_tasks.md AV-001 – AV-006): the settings with the owner's
 * defaults, the documents per track, the permission check that logs a refused attempt (AT-09), the audit
 * trail with before / after / reason, and the notifier that tells both sides and logs what it sent.
 */
class ChatVerificationFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Country $egypt;

    protected function setUp(): void
    {
        parent::setUp();

        $flag = Flag::create(['code' => 'eg']);
        $this->egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->user = User::create(['name' => 'Mona', 'phone' => '+201000000001', 'country_id' => $this->egypt->id, 'status' => 'active', 'phone_verified_at' => now()]);
        Http::fake(['*' => Http::response([])]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([
            'chat-verification-settings.view', 'chat-verification-settings.update', 'chat-verification-audit.view',
            'chat-verification-requests.review', 'chat-verification-requests.approve',
        ] as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
    }

    public function test_the_settings_start_with_the_owners_defaults_and_every_change_is_audited(): void
    {
        $admin = $this->admin(['chat-verification-settings.view', 'chat-verification-settings.update', 'chat-verification-audit.view']);

        $this->getJson('/api/admin/v1/chat-verification/settings')->assertOk()
            ->assertJsonPath('data.paid_enabled', false)->assertJsonPath('data.celebrity_enabled', false)
            ->assertJsonPath('data.review_deadline_days', 7)->assertJsonPath('data.completion_deadline_days', 14)
            ->assertJsonPath('data.deadline_warning_hours', 24)->assertJsonPath('data.escalation_after_hours', 24)
            ->assertJsonPath('data.paid_activation', 'after_approval_and_payment')->assertJsonPath('data.celebrity_activation', 'after_proof_approved')
            ->assertJsonPath('data.renewal_reminder_days', [7, 1])->assertJsonPath('data.subscription_grace_days', 3)
            ->assertJsonPath('data.exemption_default_type', 'full')->assertJsonPath('data.exemption_default_months', 6)
            ->assertJsonPath('data.exemption_warning_days', 14)->assertJsonPath('data.exemption_grace_days', 7)
            ->assertJsonPath('data.ad_default_duration_days', 30)->assertJsonPath('data.proof_deadline_days', 3)
            ->assertJsonPath('data.refund_window_hours', 48)->assertJsonPath('data.refund_on_rejection', true)
            ->assertJsonPath('data.appeal_window_days', 7)->assertJsonPath('data.appeal_response_days', 7)
            ->assertJsonPath('data.referral_requires_phone_verification', true);

        $this->putJson('/api/admin/v1/chat-verification/settings', ['review_deadline_days' => 5, 'renewal_reminder_days' => [1, 10], 'paid_enabled' => true])->assertOk()
            ->assertJsonPath('data.review_deadline_days', 5)->assertJsonPath('data.renewal_reminder_days', [10, 1])->assertJsonPath('data.paid_enabled', true);
        $this->assertSame(5, ChatVerificationSetting::current()->review_deadline_days);

        // Only what changed is kept, with its value before and after, and who changed it.
        $log = AuditLog::query()->where('action', 'chat_verification.settings_updated')->sole();
        $this->assertSame($admin->id, $log->actor_id);
        $this->assertEquals(['review_deadline_days' => 7, 'paid_enabled' => false, 'renewal_reminder_days' => [7, 1]], $log->before);
        $this->assertEquals(['review_deadline_days' => 5, 'paid_enabled' => true, 'renewal_reminder_days' => [10, 1]], $log->after);

        // Out of bounds: refused, nothing changes.
        $this->putJson('/api/admin/v1/chat-verification/settings', ['review_deadline_days' => 0, 'paid_activation' => 'whenever'])->assertUnprocessable()
            ->assertJsonValidationErrors(['review_deadline_days', 'paid_activation']);
        $this->assertSame(1, AuditLog::query()->where('action', 'chat_verification.settings_updated')->count());

        $this->getJson('/api/admin/v1/chat-verification/audit')->assertOk()
            ->assertJsonPath('data.0.action', 'settings_updated')->assertJsonPath('data.0.actor.name', 'Reviewer');
    }

    public function test_staff_without_the_permission_are_refused_and_the_attempt_is_logged(): void
    {
        $viewer = $this->admin(['chat-verification-settings.view']);

        $this->getJson('/api/admin/v1/chat-verification/settings')->assertOk();
        $this->putJson('/api/admin/v1/chat-verification/settings', ['paid_enabled' => true])->assertForbidden();
        $this->getJson('/api/admin/v1/chat-verification/audit')->assertForbidden();

        $this->assertFalse(ChatVerificationSetting::current()->paid_enabled);
        $denied = AuditLog::query()->where('action', 'chat_verification.denied')->orderBy('id')->get();
        $this->assertCount(2, $denied);
        $this->assertSame($viewer->id, $denied[0]->actor_id);
        $this->assertSame('chat-verification-settings.update', $denied[0]->after['permission']);
        $this->assertSame('PUT', $denied[0]->after['method']);
        $this->assertSame('chat-verification-audit.view', $denied[1]->after['permission']);
    }

    public function test_documents_per_track_and_the_tracks_the_app_sees(): void
    {
        $this->seed(ChatVerificationSeeder::class);
        $this->seed(ChatVerificationSeeder::class); // fills only what is empty
        $this->assertSame(3, ChatVerificationDocumentType::query()->count());

        $this->admin(['chat-verification-settings.view', 'chat-verification-settings.update']);
        $created = $this->postJson('/api/admin/v1/chat-verification/document-types', [
            'code' => 'press_card', 'tracks' => ['celebrity'], 'is_required' => false, 'needs_back_side' => false, 'status' => true,
            'name' => ['ar' => 'كارنيه نقابة', 'en' => 'Press card'],
        ])->assertCreated()->assertJsonPath('data.tracks', ['celebrity'])->json('data.id');
        $this->postJson('/api/admin/v1/chat-verification/document-types', ['code' => 'press_card', 'tracks' => ['vip'], 'name' => ['ar' => 'x', 'en' => 'x']])
            ->assertUnprocessable()->assertJsonValidationErrors(['code', 'tracks.0']);
        $this->patchJson("/api/admin/v1/chat-verification/document-types/{$created}/status", ['status' => false])->assertOk()->assertJsonPath('data.status', false);
        $this->assertSame(2, AuditLog::query()->whereIn('action', ['chat_verification.document_type_created', 'chat_verification.document_type_status'])->count());

        // The app: both tracks closed until the admin opens them.
        Sanctum::actingAs($this->user, [], 'user_api');
        $tracks = $this->getJson('/api/mobile/v1/chat/verification/options', ['X-Country' => 'EG'])->assertOk()->json('data.tracks');
        $this->assertSame(['paid', 'celebrity'], array_column($tracks, 'track'));
        $this->assertSame([false, false], array_column($tracks, 'available'));
        $this->assertSame(['identity_document', 'selfie_with_id'], array_column($tracks[0]['documents'], 'code'));
        // The switched-off press card is asked of nobody.
        $this->assertSame(['identity_document', 'selfie_with_id', 'account_ownership'], array_column($tracks[1]['documents'], 'code'));
        $this->assertTrue($tracks[0]['documents'][0]['needs_back_side']);

        // Opened by the admin — and still closed in a country with a rule against it.
        ChatVerificationSetting::current()->update(['paid_enabled' => true]);
        $this->getJson('/api/mobile/v1/chat/verification/options', ['X-Country' => 'EG'])->assertJsonPath('data.tracks.0.available', true);
        ChatFeatureRule::query()->create(['feature' => 'verification_paid', 'country_id' => $this->egypt->id, 'enabled' => false]);
        $this->getJson('/api/mobile/v1/chat/verification/options', ['X-Country' => 'EG'])->assertJsonPath('data.tracks.0.available', false);
    }

    public function test_an_event_reaches_the_account_and_only_the_staff_allowed_and_is_logged(): void
    {
        $reviewer = $this->admin(['chat-verification-requests.review']);
        $approver = $this->admin(['chat-verification-requests.approve'], 'approver@example.com');
        $notifier = app(VerificationNotifier::class);

        $notifier->notify('submitted', $this->user);

        $this->assertDatabaseHas('notifications', ['notifiable_type' => $this->user->getMorphClass(), 'notifiable_id' => $this->user->id]);
        $this->assertDatabaseHas('notifications', ['notifiable_type' => $reviewer->getMorphClass(), 'notifiable_id' => $reviewer->id]);
        $this->assertDatabaseMissing('notifications', ['notifiable_type' => $approver->getMorphClass(), 'notifiable_id' => $approver->id]);
        $this->assertSame(
            [['user', $this->user->id, true], ['admin', $reviewer->id, false]],
            ChatVerificationNotification::query()->where('event', 'submitted')->orderBy('id')->get()->map(fn ($n) => [$n->audience, $n->recipient_id, $n->push])->all(),
        );

        // An action rolled back tells nobody and logs nothing.
        try {
            DB::transaction(function () use ($notifier) {
                $notifier->notify('approved', $this->user);
                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
        }
        $this->assertSame(0, ChatVerificationNotification::query()->where('event', 'approved')->count());

        $this->expectException(\InvalidArgumentException::class);
        $notifier->notify('no_such_event', $this->user);
    }

    public function test_every_event_has_its_texts_in_both_languages(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $texts = require lang_path("{$locale}/notifications.php");
            foreach (VerificationNotifier::EVENTS as $event => $rule) {
                if ($rule['user']) {
                    $this->assertArrayHasKey("chat_verification_{$event}_title", $texts, "{$locale}: {$event}");
                    $this->assertArrayHasKey("chat_verification_{$event}_body", $texts, "{$locale}: {$event}");
                }
                if ($rule['admin'] !== null) {
                    $this->assertArrayHasKey("chat_verification_admin_{$event}_title", $texts, "{$locale}: admin {$event}");
                    $this->assertArrayHasKey("chat_verification_admin_{$event}_body", $texts, "{$locale}: admin {$event}");
                }
            }
        }
    }

    /** @param  list<string>  $permissions */
    private function admin(array $permissions, string $email = 'reviewer@example.com'): Admin
    {
        $admin = Admin::create(['name' => 'Reviewer', 'email' => $email, 'password' => 'secret123', 'status' => 'active']);
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }
}
