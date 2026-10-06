<?php

namespace Tests\Feature;

use App\Mail\VerificationCodeMail;
use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Provider\Models\Provider;
use Modules\User\Models\User;
use Modules\Wallet\Enums\PinRecoveryStatus;
use Modules\Wallet\Models\PinRecoveryRequest;
use Modules\Wallet\Models\WalletPin;
use Modules\Wallet\Models\WalletRecoveryMethod;
use Modules\Wallet\Services\PinService;
use Modules\Wallet\Services\WalletRecoveryService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Choosing how to get the wallet PIN back (before the PIN exists), and using it when it's forgotten:
 * password, birth date, e-mail code, or an ID / passport photo reviewed in the dashboard.
 */
class WalletPinRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/mobile/v1/wallet/pin';

    private User $alice;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        config(['auth_flow.email_otp_fixed' => null, 'auth_flow.otp_resend_cooldown_seconds' => 0]);

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR']);
        $flag = Flag::create(['code' => 'sa']);
        Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);

        $this->alice = User::create(['name' => 'Alice', 'phone' => '+966500000001', 'status' => 'active', 'phone_verified_at' => now()]);
        Sanctum::actingAs($this->alice, [], 'user_api');
    }

    private function headers(array $extra = []): array
    {
        return $extra + ['X-Country' => 'SA'];
    }

    private function photo(string $name = 'id.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 300, 200);
    }

    /** Chooses a recovery method and creates the PIN (1234) — the state of any normal wallet user. */
    private function walletWith(array $method, ?User $user = null): User
    {
        $user ??= $this->alice;
        Sanctum::actingAs($user, [], 'user_api');

        $this->post(self::BASE.'/recovery', $method, $this->headers(['Accept' => 'application/json']))->assertOk();

        if (($method['method'] ?? null) === 'email') {
            $this->confirmEmailWithMailedCode();
        }

        $this->postJson(self::BASE, ['pin' => '1234', 'pin_confirmation' => '1234'], $this->headers())->assertCreated();

        return $user;
    }

    private function mailedCode(): string
    {
        $code = null;
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return (string) $code;
    }

    private function confirmEmailWithMailedCode(): void
    {
        $this->postJson(self::BASE.'/recovery/confirm-email', ['code' => $this->mailedCode()], $this->headers())->assertOk();
    }

    private function pinIs(string $pin, ?\Illuminate\Database\Eloquent\Model $user = null): bool
    {
        try {
            app(PinService::class)->verify($user ?? $this->alice, $pin);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function admin(array $permissions): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Reviewer', 'email' => 'reviewer@example.com', 'password' => 'secret123', 'status' => 'active']);

        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'admin_api');
        }
        $admin->givePermissionTo($permissions);
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    // ------------------------------------------------------------------ choosing a method comes first

    public function test_a_pin_cannot_be_created_before_a_recovery_method_is_chosen(): void
    {
        $this->postJson(self::BASE, ['pin' => '1234', 'pin_confirmation' => '1234'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'pin_recovery_required');

        $this->assertFalse(app(PinService::class)->has($this->alice));
    }

    public function test_each_of_the_five_methods_unlocks_pin_creation(): void
    {
        $cases = [
            ['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123'],
            ['method' => 'birth_date', 'birth_date' => '1990-05-17'],
            ['method' => 'id_photo', 'document' => $this->photo()],
            ['method' => 'passport_photo', 'document' => $this->photo('passport.png')],
        ];

        foreach ($cases as $i => $method) {
            $user = User::create(['name' => "User $i", 'phone' => '+96650000010'.$i, 'status' => 'active', 'phone_verified_at' => now()]);
            $this->walletWith($method, $user);
            $this->assertTrue($this->pinIs('1234', $user), $method['method'].' should allow the PIN');
        }
    }

    public function test_the_summary_tells_the_app_which_method_is_on_file(): void
    {
        $this->getJson(self::BASE, $this->headers())->assertOk()->assertJsonPath('data.has_pin', false)->assertJsonPath('data.recovery', null);

        $this->walletWith(['method' => 'birth_date', 'birth_date' => '1990-05-17']);

        $this->getJson(self::BASE, $this->headers())->assertOk()
            ->assertJsonPath('data.has_pin', true)
            ->assertJsonPath('data.recovery.method', 'birth_date')
            ->assertJsonPath('data.recovery.ready', true)
            ->assertJsonMissingPath('data.recovery.secret_hash');
    }

    // ------------------------------------------------------------------ input rules

    public function test_a_password_must_be_confirmed_and_long_enough(): void
    {
        $this->postJson(self::BASE.'/recovery', ['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'other'], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->postJson(self::BASE.'/recovery', ['method' => 'password', 'password' => '123', 'password_confirmation' => '123'], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_a_birth_date_must_be_a_real_past_date(): void
    {
        $this->postJson(self::BASE.'/recovery', ['method' => 'birth_date', 'birth_date' => now()->addDay()->format('Y-m-d')], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('birth_date');
        $this->postJson(self::BASE.'/recovery', ['method' => 'birth_date', 'birth_date' => '17/05/1990'], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('birth_date');
    }

    public function test_a_document_must_be_an_image(): void
    {
        $this->post(self::BASE.'/recovery', ['method' => 'id_photo', 'document' => UploadedFile::fake()->create('id.pdf', 50, 'application/pdf')], $this->headers(['Accept' => 'application/json']))
            ->assertStatus(422)->assertJsonValidationErrors('document');
        $this->post(self::BASE.'/recovery', ['method' => 'id_photo'], $this->headers(['Accept' => 'application/json']))
            ->assertStatus(422)->assertJsonValidationErrors('document');
    }

    public function test_secrets_are_stored_hashed_and_documents_privately(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $record = WalletRecoveryMethod::query()->firstOrFail();

        $this->assertStringNotContainsString('secret123', (string) $record->secret_hash);
        $this->assertTrue(str_starts_with((string) $record->secret_hash, '$argon2id$'));

        $other = User::create(['name' => 'Doc', 'phone' => '+966500000055', 'status' => 'active', 'phone_verified_at' => now()]);
        $this->walletWith(['method' => 'id_photo', 'document' => $this->photo()], $other);
        $media = WalletRecoveryMethod::query()->where('owner_id', $other->id)->firstOrFail()->getFirstMedia('document');

        $this->assertSame('local', $media->disk, 'personal documents are never on the public disk');
    }

    // ------------------------------------------------------------------ e-mail

    /**
     * The code goes out right away — never into the queue, where it waited for a worker that isn't
     * running (so "send code" did nothing at all), and a mail failure is reported, not lost in a job.
     */
    public function test_the_email_code_is_sent_right_away_without_a_queue_worker(): void
    {
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Queue::fake();

        $this->postJson(self::BASE.'/recovery', ['method' => 'email', 'email' => 'alice@example.com'], $this->headers())->assertOk();
        Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo('alice@example.com'));
        Mail::assertNothingQueued();
        \Illuminate\Support\Facades\Queue::assertNothingPushed();

        // Forgot the PIN later: a fresh code, straight away too.
        $this->confirmEmailWithMailedCode();
        $this->postJson(self::BASE, ['pin' => '1234', 'pin_confirmation' => '1234'], $this->headers())->assertCreated();
        $this->travel(2)->minutes();
        $this->postJson(self::BASE.'/recovery/email-code', [], $this->headers())->assertOk();
        Mail::assertSent(VerificationCodeMail::class, 2);
        $this->postJson(self::BASE.'/recover', ['code' => $this->latestCode(), 'pin' => '5678', 'pin_confirmation' => '5678'], $this->headers())->assertOk();
    }

    private function latestCode(): string
    {
        $codes = [];
        Mail::assertSent(VerificationCodeMail::class, function (VerificationCodeMail $mail) use (&$codes) {
            $codes[] = $mail->code;

            return true;
        });

        return (string) end($codes);
    }

    public function test_an_email_method_needs_the_four_digit_code_before_a_pin_can_be_created(): void
    {
        $this->postJson(self::BASE.'/recovery', ['method' => 'email', 'email' => 'alice@example.com'], $this->headers())->assertOk()
            ->assertJsonPath('data.recovery.ready', false)
            ->assertJsonPath('data.recovery.email', 'al***@example.com');

        $code = $this->mailedCode();
        $this->assertMatchesRegularExpression('/^\d{4}$/', $code, 'e-mail codes are 4 digits');

        $this->postJson(self::BASE, ['pin' => '1234', 'pin_confirmation' => '1234'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'pin_recovery_required');

        $wrong = $code === '0000' ? '1111' : '0000';
        $this->postJson(self::BASE.'/recovery/confirm-email', ['code' => $wrong], $this->headers())->assertStatus(422);

        $this->postJson(self::BASE.'/recovery/confirm-email', ['code' => $code], $this->headers())->assertOk()->assertJsonPath('data.recovery.ready', true);
        $this->postJson(self::BASE, ['pin' => '1234', 'pin_confirmation' => '1234'], $this->headers())->assertCreated();
    }

    public function test_the_login_otp_is_four_digits_too(): void
    {
        $this->assertSame(4, (int) config('auth_flow.otp_length'));
        $this->assertSame('1234', (string) config('auth_flow.phone_otp_fixed'));
    }

    // ------------------------------------------------------------------ forgot: things the server can check

    public function test_forgetting_the_pin_with_the_password_sets_a_new_one(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);

        $this->postJson(self::BASE.'/recover', ['password' => 'wrong-one', 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'pin_recovery_invalid');
        $this->assertTrue($this->pinIs('1234'), 'a wrong password changes nothing');

        $this->postJson(self::BASE.'/recover', ['password' => 'secret123', 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers())->assertOk();

        $this->assertTrue($this->pinIs('5555'));
        $this->assertFalse($this->pinIs('1234'));
        $this->assertContains('wallet_pin_recovered_title', $this->alice->notifications()->pluck('data')->pluck('title')->all());
    }

    public function test_forgetting_the_pin_with_the_birth_date(): void
    {
        $this->walletWith(['method' => 'birth_date', 'birth_date' => '1990-05-17']);

        $this->postJson(self::BASE.'/recover', ['birth_date' => '1990-05-18', 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers())->assertStatus(422);
        $this->postJson(self::BASE.'/recover', ['birth_date' => '1990-05-17', 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers())->assertOk();

        $this->assertTrue($this->pinIs('5555'));
    }

    public function test_the_new_pin_has_to_be_confirmed(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);

        $this->postJson(self::BASE.'/recover', ['password' => 'secret123', 'pin' => '5555', 'pin_confirmation' => '6666'], $this->headers())
            ->assertStatus(422)->assertJsonValidationErrors('pin');
        $this->assertTrue($this->pinIs('1234'));
    }

    public function test_guessing_the_recovery_secret_locks_after_five_tries(): void
    {
        $this->walletWith(['method' => 'birth_date', 'birth_date' => '1990-05-17']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson(self::BASE.'/recover', ['birth_date' => '1980-01-0'.($i + 1), 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers());
        }

        // Even the right answer is refused while locked — a birth date has too few possibilities to allow guessing.
        $this->postJson(self::BASE.'/recover', ['birth_date' => '1990-05-17', 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers())
            ->assertStatus(423)->assertJsonPath('error_code', 'pin_recovery_locked');
        $this->assertTrue($this->pinIs('1234'));
    }

    public function test_forgetting_the_pin_with_the_email_code(): void
    {
        $this->walletWith(['method' => 'email', 'email' => 'alice@example.com']);
        Mail::fake(); // forget the confirmation mail: a fresh code is requested for recovery

        $this->postJson(self::BASE.'/recovery/email-code', [], $this->headers())->assertOk();
        $code = $this->mailedCode();
        $wrong = $code === '0000' ? '1111' : '0000';

        $this->postJson(self::BASE.'/recover', ['code' => $wrong, 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers())->assertStatus(422);
        $this->postJson(self::BASE.'/recover', ['code' => $code, 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers())->assertOk();

        $this->assertTrue($this->pinIs('5555'));
    }

    public function test_recovering_needs_the_method_you_actually_chose(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);

        // A photo instead of the password you chose: not accepted, nothing is filed.
        $this->post(self::BASE.'/recover', ['document' => $this->photo(), 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers(['Accept' => 'application/json']))->assertStatus(422);
        $this->assertSame(0, PinRecoveryRequest::query()->count());
        $this->assertTrue($this->pinIs('1234'));
    }

    public function test_an_account_that_never_chose_a_method_cannot_recover(): void
    {
        app(PinService::class)->set($this->alice, '1234'); // an older account: PIN but no recovery method

        $this->postJson(self::BASE.'/recover', ['password' => 'x', 'pin' => '5555', 'pin_confirmation' => '5555'], $this->headers())
            ->assertStatus(422)->assertJsonPath('error_code', 'pin_recovery_not_configured');
    }

    public function test_changing_the_method_later_needs_the_current_pin(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);

        $change = ['method' => 'birth_date', 'birth_date' => '1990-05-17'];

        $this->postJson(self::BASE.'/recovery', $change, $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_required');
        $this->postJson(self::BASE.'/recovery', $change, $this->headers(['X-Wallet-Pin' => '9999']))->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');
        $this->postJson(self::BASE.'/recovery', $change, $this->headers(['X-Wallet-Pin' => '1234']))->assertOk()->assertJsonPath('data.recovery.method', 'birth_date');
    }

    // ------------------------------------------------------------------ forgot: a document, reviewed by a person

    private function fileDocumentRequest(): PinRecoveryRequest
    {
        $this->walletWith(['method' => 'id_photo', 'document' => $this->photo('original.jpg')]);

        $this->post(self::BASE.'/recover', ['document' => $this->photo('new.jpg')], $this->headers(['Accept' => 'application/json']))
            ->assertCreated()->assertJsonPath('data.request.status', 'pending');

        return PinRecoveryRequest::query()->firstOrFail();
    }

    public function test_a_document_request_keeps_both_photos_and_changes_nothing_yet(): void
    {
        $request = $this->fileDocumentRequest();

        $this->assertSame(PinRecoveryStatus::Pending, $request->status);
        $this->assertNotNull($request->getFirstMedia('original_document'), 'the photo from setup, copied');
        $this->assertNotNull($request->getFirstMedia('new_document'), 'the photo just uploaded');
        $this->assertTrue($this->pinIs('1234'), 'the PIN is untouched until a person approves');
    }

    public function test_only_one_document_request_can_be_pending(): void
    {
        $this->fileDocumentRequest();

        $this->post(self::BASE.'/recover', ['document' => $this->photo()], $this->headers(['Accept' => 'application/json']))
            ->assertStatus(409)->assertJsonPath('error_code', 'pin_recovery_pending_exists');
    }

    public function test_the_original_photo_survives_a_later_change_of_method(): void
    {
        $request = $this->fileDocumentRequest();

        $this->postJson(self::BASE.'/recovery', ['method' => 'birth_date', 'birth_date' => '1990-05-17'], $this->headers(['X-Wallet-Pin' => '1234']))->assertOk();

        $this->assertNotNull($request->fresh()->getFirstMedia('original_document'), 'the evidence must not vanish');
    }

    public function test_the_reviewer_sees_both_photos_and_approving_resets_the_pin_to_zeros(): void
    {
        $request = $this->fileDocumentRequest();
        $this->admin(['pin-recovery-requests.view', 'pin-recovery-requests.approve']);

        $this->getJson('/api/admin/v1/pin-recovery-requests?status=pending')->assertOk()
            ->assertJsonPath('data.0.owner.name', 'Alice')
            ->assertJsonPath('data.0.method', 'id_photo')
            ->assertJsonPath('data.0.original_image', "/api/admin/v1/pin-recovery-requests/{$request->id}/image/original")
            ->assertJsonPath('data.0.new_image', "/api/admin/v1/pin-recovery-requests/{$request->id}/image/new");

        $this->get("/api/admin/v1/pin-recovery-requests/{$request->id}/image/original")->assertOk();
        $this->get("/api/admin/v1/pin-recovery-requests/{$request->id}/image/new")->assertOk();
        $this->get("/api/admin/v1/pin-recovery-requests/{$request->id}/image/other")->assertNotFound();

        $this->postJson("/api/admin/v1/pin-recovery-requests/{$request->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertTrue($this->pinIs(WalletRecoveryService::RESET_PIN), 'the PIN is now 0000');
        $this->assertFalse($this->pinIs('1234'));

        // …and the owner is told, in their own language, what it is now.
        $note = $this->alice->notifications()->get()->firstWhere(fn ($n) => $n->data['title'] === 'wallet_recovery_approved_title');
        $this->assertNotNull($note);
        $this->assertSame('0000', $note->data['variables']['pin']);
        $this->assertStringContainsString('0000', __('notifications.wallet_recovery_approved_body', ['pin' => '0000'], 'ar'));
        $this->assertStringContainsString('تمت الموافقة', __('notifications.wallet_recovery_approved_body', ['pin' => '0000'], 'ar'));

        $this->postJson("/api/admin/v1/pin-recovery-requests/{$request->id}/approve")->assertStatus(409);
    }

    public function test_rejecting_needs_a_reason_and_leaves_the_pin_alone(): void
    {
        $request = $this->fileDocumentRequest();
        $this->admin(['pin-recovery-requests.view', 'pin-recovery-requests.reject']);

        $this->postJson("/api/admin/v1/pin-recovery-requests/{$request->id}/reject", [])->assertStatus(422)->assertJsonValidationErrors('rejection_reason');
        $this->postJson("/api/admin/v1/pin-recovery-requests/{$request->id}/reject", ['rejection_reason' => 'The photo is blurry'])->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertTrue($this->pinIs('1234'));
        $note = $this->alice->notifications()->get()->firstWhere(fn ($n) => $n->data['title'] === 'wallet_recovery_rejected_title');
        $this->assertSame('The photo is blurry', $note->data['variables']['reason']);

        // After a rejection the owner can ask again.
        Sanctum::actingAs($this->alice, [], 'user_api');
        $this->post(self::BASE.'/recover', ['document' => $this->photo()], $this->headers(['Accept' => 'application/json']))->assertCreated();
    }

    public function test_the_reviewers_are_notified_and_permissions_are_enforced(): void
    {
        $viewer = $this->admin(['pin-recovery-requests.view']);
        Sanctum::actingAs($this->alice, [], 'user_api');
        $approverPermission = Permission::findOrCreate('pin-recovery-requests.approve', 'admin_api');
        $approver = Admin::create(['name' => 'Approver', 'email' => 'approver@example.com', 'password' => 'secret123', 'status' => 'active']);
        $approver->givePermissionTo($approverPermission);

        $request = $this->fileDocumentRequest();

        $this->assertSame(1, $approver->notifications()->count(), 'whoever can approve is pinged');
        $this->assertSame(0, $viewer->notifications()->count(), 'a viewer cannot act on it');
        $this->assertContains('wallet_recovery_requested_title', $this->alice->notifications()->pluck('data')->pluck('title')->all());

        Sanctum::actingAs($viewer, [], 'admin_api');
        $this->postJson("/api/admin/v1/pin-recovery-requests/{$request->id}/approve")->assertForbidden();
    }

    public function test_providers_recover_the_same_way(): void
    {
        $provider = Provider::create(['name' => 'Prov', 'email' => 'p@example.com', 'status' => 'active']);
        Sanctum::actingAs($provider, [], 'provider_api');
        $base = '/api/provider/v1/wallet/pin';

        $this->postJson($base, ['pin' => '1234', 'pin_confirmation' => '1234'], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'pin_recovery_required');
        $this->postJson($base.'/recovery', ['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123'], $this->headers())->assertOk();
        $this->postJson($base, ['pin' => '1234', 'pin_confirmation' => '1234'], $this->headers())->assertCreated();
        $this->postJson($base.'/recover', ['password' => 'secret123', 'pin' => '4444', 'pin_confirmation' => '4444'], $this->headers())->assertOk();

        $this->assertTrue($this->pinIs('4444', $provider));
    }

    // ------------------------------------------------------------------ a reset PIN has to be replaced

    public function test_after_an_approved_reset_the_pin_opens_the_wallet_but_moves_no_money_until_changed(): void
    {
        $request = $this->fileDocumentRequest();
        $this->admin(['pin-recovery-requests.view', 'pin-recovery-requests.approve']);
        $this->postJson("/api/admin/v1/pin-recovery-requests/{$request->id}/approve")->assertOk();

        Sanctum::actingAs($this->alice, [], 'user_api');
        $zeros = $this->headers(['X-Wallet-Pin' => '0000']);

        $this->getJson(self::BASE, $this->headers())->assertOk()->assertJsonPath('data.must_change', true);
        // the unlock check still accepts it and says what to do next
        $this->postJson(self::BASE.'/verify', [], $zeros)->assertOk()->assertJsonPath('data.must_change', true);
        // …but nothing that moves money does
        $this->postJson('/api/mobile/v1/wallet/transfers', [], $zeros + ['Idempotency-Key' => 'reset-pin-key-1'])
            ->assertStatus(403)->assertJsonPath('error_code', 'wallet_pin_change_required');
        $this->postJson('/api/mobile/v1/wallet/topups', [], $zeros + ['Idempotency-Key' => 'reset-pin-key-2'])
            ->assertStatus(403)->assertJsonPath('error_code', 'wallet_pin_change_required');

        // 0000 cannot be "changed" to 0000
        $this->putJson(self::BASE, ['current_pin' => '0000', 'pin' => '0000', 'pin_confirmation' => '0000'], $this->headers())->assertStatus(422);
        $this->putJson(self::BASE, ['current_pin' => '0000', 'pin' => '2468', 'pin_confirmation' => '2468'], $this->headers())->assertOk();

        $this->getJson(self::BASE, $this->headers())->assertJsonPath('data.must_change', false);
        $this->postJson(self::BASE.'/verify', [], $this->headers(['X-Wallet-Pin' => '2468']))->assertOk()->assertJsonPath('data.must_change', false);
        $this->postJson('/api/mobile/v1/wallet/topups', [], $this->headers(['X-Wallet-Pin' => '2468', 'Idempotency-Key' => 'reset-pin-key-3']))
            ->assertStatus(422); // past the PIN gate: now it is the (empty) body that is refused
    }

    public function test_recovering_with_a_password_is_not_a_forced_change(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);

        $this->postJson(self::BASE.'/recover', ['password' => 'secret123', 'pin' => '4444', 'pin_confirmation' => '4444'], $this->headers())->assertOk()
            ->assertJsonPath('data.must_change', false);
    }

    // ------------------------------------------------------------------ switching method later

    public function test_switching_to_email_keeps_the_old_method_until_the_code_is_entered(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $pin = $this->headers(['X-Wallet-Pin' => '1234']);

        $this->postJson(self::BASE.'/recovery', ['method' => 'email', 'email' => 'New@Example.com'], $pin)->assertOk()
            ->assertJsonPath('data.recovery.method', 'password')
            ->assertJsonPath('data.recovery.ready', true)
            ->assertJsonPath('data.recovery.pending_email', fn ($v) => $v !== null && str_contains($v, '@'));
        Mail::assertSent(VerificationCodeMail::class, fn ($mail) => $mail->hasTo('new@example.com'));

        // abandoned halfway: the password still recovers the PIN
        $this->postJson(self::BASE.'/recover', ['password' => 'secret123', 'pin' => '4444', 'pin_confirmation' => '4444'], $this->headers())->assertOk();
        $this->assertTrue($this->pinIs('4444'));

        // a wrong code changes nothing; the right one switches over
        $this->postJson(self::BASE.'/recovery/confirm-email', ['code' => '9999'], $this->headers())->assertStatus(422);
        $this->assertSame('password', WalletRecoveryMethod::query()->firstOrFail()->method->value);

        $this->postJson(self::BASE.'/recovery/email-code', ['pending' => true], $this->headers())->assertOk();
        $this->confirmEmailWithMailedCode();

        $record = WalletRecoveryMethod::query()->firstOrFail();
        $this->assertSame('email', $record->method->value);
        $this->assertSame('new@example.com', $record->email);
        $this->assertNull($record->pending_email);
        $this->assertNull($record->secret_hash, 'the old password is gone');
    }

    public function test_choosing_another_method_drops_a_half_finished_email_switch(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $pin = $this->headers(['X-Wallet-Pin' => '1234']);

        $this->postJson(self::BASE.'/recovery', ['method' => 'email', 'email' => 'new@example.com'], $pin)->assertOk();
        $this->postJson(self::BASE.'/recovery', ['method' => 'birth_date', 'birth_date' => '1990-05-17'], $pin)->assertOk()
            ->assertJsonPath('data.recovery.method', 'birth_date')
            ->assertJsonPath('data.recovery.pending_email', null);
    }

    // ------------------------------------------------------------------ two wrong attempts, then a permanent freeze

    private function freeze(User $user): void
    {
        WalletPin::query()->where('owner_type', 'user')->where('owner_id', $user->id)->update(['frozen_at' => now()]);
    }

    public function test_two_wrong_attempts_lock_for_fifteen_minutes_then_a_third_freezes_permanently(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $wrongPin = ['X-Wallet-Pin' => '0000'];

        // #1: a plain wrong PIN, nothing locked yet.
        $this->postJson('/api/mobile/v1/wallet/topups', [], $this->headers($wrongPin + ['Idempotency-Key' => 'k1']))
            ->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');

        // #2: this one starts the 15-minute lock — and says so, right on this response.
        $this->postJson('/api/mobile/v1/wallet/topups', [], $this->headers($wrongPin + ['Idempotency-Key' => 'k2']))
            ->assertStatus(423)->assertJsonPath('error_code', 'wallet_pin_locked');

        // Still within the lock: even the *right* PIN is refused the same way.
        $this->postJson(self::BASE.'/verify', [], $this->headers(['X-Wallet-Pin' => '1234']))
            ->assertStatus(423)->assertJsonPath('error_code', 'wallet_pin_locked');

        // Reopening the wallet during the lock: the status already carries its end, so the app
        // shows the countdown instead of the keypad.
        $this->assertNotNull($this->getJson(self::BASE, $this->headers())->assertOk()->json('data.locked_until'));

        // Fast-forward past the 15 minutes (nothing auto-unlocks; this only ends the wait).
        WalletPin::query()->update(['locked_until' => now()->subMinute()]);
        $this->getJson(self::BASE, $this->headers())->assertOk()->assertJsonPath('data.locked_until', null);

        // #3: the grace attempt after the lock is wrong too → permanent freeze, not another temporary lock.
        $this->postJson(self::BASE.'/verify', [], $this->headers($wrongPin))
            ->assertStatus(423)->assertJsonPath('error_code', 'wallet_pin_frozen');

        $this->getJson(self::BASE, $this->headers())->assertOk()->assertJsonPath('data.is_frozen', true);

        // Frozen beats everything — even the correct PIN no longer opens the wallet...
        $this->postJson(self::BASE.'/verify', [], $this->headers(['X-Wallet-Pin' => '1234']))
            ->assertStatus(423)->assertJsonPath('error_code', 'wallet_pin_frozen');
        // ...and no money moves either.
        $this->postJson('/api/mobile/v1/wallet/topups', [], $this->headers(['X-Wallet-Pin' => '1234', 'Idempotency-Key' => 'k3']))
            ->assertStatus(423)->assertJsonPath('error_code', 'wallet_pin_frozen');
        // Self-service recovery (even with the right password) is refused too — only the selfie+ID path works.
        $this->postJson(self::BASE.'/recover', ['password' => 'secret123', 'pin' => '4444', 'pin_confirmation' => '4444'], $this->headers())
            ->assertStatus(423)->assertJsonPath('error_code', 'wallet_pin_frozen');
    }

    public function test_a_correct_pin_between_two_wrong_ones_resets_the_counter(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);

        $this->postJson(self::BASE.'/verify', [], $this->headers(['X-Wallet-Pin' => '0000']))->assertStatus(422);
        $this->postJson(self::BASE.'/verify', [], $this->headers(['X-Wallet-Pin' => '1234']))->assertOk();
        // Back to a clean slate: this wrong attempt is "#1" again, not "#2" — no lock yet.
        $this->postJson(self::BASE.'/verify', [], $this->headers(['X-Wallet-Pin' => '0000']))->assertStatus(422)->assertJsonPath('error_code', 'wallet_pin_invalid');
    }

    public function test_unfreezing_needs_a_selfie_and_an_id_photo_and_only_works_while_frozen(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);

        // Not frozen — nothing to review.
        $this->post(self::BASE.'/unfreeze', ['id_document' => $this->photo('id.jpg'), 'selfie' => $this->photo('selfie.jpg')], $this->headers(['Accept' => 'application/json']))
            ->assertStatus(422)->assertJsonPath('error_code', 'pin_recovery_not_frozen');

        $this->freeze($this->alice);

        $this->post(self::BASE.'/unfreeze', [], $this->headers(['Accept' => 'application/json']))
            ->assertStatus(422)->assertJsonValidationErrors(['id_document', 'selfie']);

        $this->post(self::BASE.'/unfreeze', ['id_document' => $this->photo('id.jpg'), 'selfie' => $this->photo('selfie.jpg')], $this->headers(['Accept' => 'application/json']))
            ->assertCreated()
            ->assertJsonPath('data.request.reason', 'security_freeze')
            ->assertJsonPath('data.request.status', 'pending');

        // Only one open request at a time.
        $this->post(self::BASE.'/unfreeze', ['id_document' => $this->photo('id2.jpg'), 'selfie' => $this->photo('selfie2.jpg')], $this->headers(['Accept' => 'application/json']))
            ->assertStatus(409)->assertJsonPath('error_code', 'pin_recovery_pending_exists');
    }

    public function test_approving_a_security_freeze_request_resets_the_pin_and_lifts_the_freeze(): void
    {
        // The configured method is a document one here on purpose: proves the freeze path uses its own
        // fresh photos (id_document/selfie), not whatever was uploaded at setup.
        $this->walletWith(['method' => 'id_photo', 'document' => $this->photo('original.jpg')]);
        $this->freeze($this->alice);

        $this->post(self::BASE.'/unfreeze', ['id_document' => $this->photo('id-now.jpg'), 'selfie' => $this->photo('selfie-now.jpg')], $this->headers(['Accept' => 'application/json']))
            ->assertCreated();
        $request = PinRecoveryRequest::query()->where('reason', 'security_freeze')->firstOrFail();

        $this->admin(['pin-recovery-requests.view', 'pin-recovery-requests.approve']);

        $this->getJson('/api/admin/v1/pin-recovery-requests?status=pending')->assertOk()
            ->assertJsonPath('data.0.reason', 'security_freeze')
            ->assertJsonPath('data.0.original_image', "/api/admin/v1/pin-recovery-requests/{$request->id}/image/original")
            ->assertJsonPath('data.0.new_image', "/api/admin/v1/pin-recovery-requests/{$request->id}/image/new");

        $this->get("/api/admin/v1/pin-recovery-requests/{$request->id}/image/original")->assertOk();
        $this->get("/api/admin/v1/pin-recovery-requests/{$request->id}/image/new")->assertOk();

        $this->postJson("/api/admin/v1/pin-recovery-requests/{$request->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertTrue($this->pinIs(WalletRecoveryService::RESET_PIN), 'the PIN is now 0000');
        $this->assertFalse(app(PinService::class)->isFrozen($this->alice), 'approving lifts the freeze');
        $this->assertTrue(app(PinService::class)->mustChange($this->alice), 'a reset PIN still has to be replaced');

        $note = $this->alice->notifications()->get()->firstWhere(fn ($n) => $n->data['title'] === 'wallet_security_approved_title');
        $this->assertNotNull($note, 'the freeze approval uses its own wording, not the document-recovery one');
        $this->assertSame('0000', $note->data['variables']['pin']);
    }

    public function test_rejecting_a_security_freeze_request_leaves_the_wallet_frozen(): void
    {
        $this->walletWith(['method' => 'password', 'password' => 'secret123', 'password_confirmation' => 'secret123']);
        $this->freeze($this->alice);
        $this->post(self::BASE.'/unfreeze', ['id_document' => $this->photo('id.jpg'), 'selfie' => $this->photo('selfie.jpg')], $this->headers(['Accept' => 'application/json']))->assertCreated();
        $request = PinRecoveryRequest::query()->where('reason', 'security_freeze')->firstOrFail();

        $this->admin(['pin-recovery-requests.view', 'pin-recovery-requests.reject']);
        $this->postJson("/api/admin/v1/pin-recovery-requests/{$request->id}/reject", ['rejection_reason' => 'الصورتان غير واضحتين'])->assertOk();

        $this->assertTrue(app(PinService::class)->isFrozen($this->alice), 'a rejection changes nothing about the freeze');

        // A fresh selfie + ID can be submitted again.
        $this->post(self::BASE.'/unfreeze', ['id_document' => $this->photo('id2.jpg'), 'selfie' => $this->photo('selfie2.jpg')], $this->headers(['Accept' => 'application/json']))
            ->assertCreated();
    }
}
