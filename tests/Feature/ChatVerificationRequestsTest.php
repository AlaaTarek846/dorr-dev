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
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Chat\Database\Seeders\ChatVerificationSeeder;
use Modules\Chat\Models\Verification\ChatVerificationDocumentType;
use Modules\Chat\Models\Verification\ChatVerificationNotification;
use Modules\Chat\Models\Verification\ChatVerificationRequest;
use Modules\Chat\Models\Verification\ChatVerificationSetting;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Account verification, batch 1 (docs/verification_tasks.md AV-010 – AV-019): the account sends a request with its
 * documents, one open request at a time; the reviewer checks and asks for more; the approver decides; deadlines warn,
 * move and put a request on hold; every step reaches both sides and leaves a trail.
 */
class ChatVerificationRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Admin $reviewer;

    private Admin $approver;

    private int $idType;

    private int $selfieType;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::fake(['*' => Http::response([])]);

        $flag = Flag::create(['code' => 'eg']);
        $egypt = Country::create(['code' => 'EG', 'dial_code' => '+20', 'phone_length' => 10, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => Currency::create(['code' => 'EGP', 'symbol' => 'EGP', 'decimal_places' => 2])->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $this->user = User::create(['name' => 'Mona', 'phone' => '+201000000001', 'country_id' => $egypt->id, 'status' => 'active', 'phone_verified_at' => now(), 'timezone' => 'Africa/Cairo']);

        $this->seed(ChatVerificationSeeder::class);
        ChatVerificationSetting::current()->update(['paid_enabled' => true]);
        $this->idType = ChatVerificationDocumentType::query()->where('code', 'identity_document')->value('id');
        $this->selfieType = ChatVerificationDocumentType::query()->where('code', 'selfie_with_id')->value('id');

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (['view', 'review', 'approve'] as $action) {
            Permission::findOrCreate("chat-verification-requests.{$action}", 'admin_api');
        }
        $this->reviewer = Admin::create(['name' => 'Rania', 'email' => 'r@example.com', 'password' => 'secret123', 'status' => 'active']);
        $this->reviewer->givePermissionTo(['chat-verification-requests.view', 'chat-verification-requests.review']);
        $this->approver = Admin::create(['name' => 'Adel', 'email' => 'a@example.com', 'password' => 'secret123', 'status' => 'active']);
        $this->approver->givePermissionTo(['chat-verification-requests.view', 'chat-verification-requests.approve']);
    }

    public function test_the_account_sends_one_request_with_its_documents_and_follows_it(): void
    {
        $this->asUser();

        // A required document is missing: nothing is created.
        $this->post('/api/mobile/v1/chat/verification/request', ['track' => 'paid', 'full_name' => 'Mona Ali', 'documents' => [$this->idType => ['front' => $this->image()]]], $this->headers())
            ->assertUnprocessable()->assertJsonPath('error_code', 'chat_verification_documents_missing');
        $this->assertSame(0, ChatVerificationRequest::query()->count());

        $created = $this->submit()->assertCreated()
            ->assertJsonPath('data.status', 'new')->assertJsonPath('data.next_step', 'wait_review')->assertJsonPath('data.deadline_owner', 'admin')
            ->assertJsonPath('data.can_cancel', true)->assertJsonCount(2, 'data.documents');
        $uuid = $created->json('data.uuid');
        $this->assertTrue(now()->addDays(7)->diffInMinutes($created->json('data.deadline_at')) < 2);

        // One open request at a time (REQ-01-03).
        $this->submit()->assertStatus(409)->assertJsonPath('error_code', 'chat_verification_request_open');

        $this->getJson('/api/mobile/v1/chat/verification/request', $this->headers())->assertOk()->assertJsonPath('data.uuid', $uuid)->assertJsonPath('data.history.0.status', 'new');
        $path = $created->json('data.documents.0.files.front.path');
        $this->get("/api/mobile/v1/{$path}", $this->headers())->assertOk();

        // Both sides heard of it: the account, and the reviewer (not the approver).
        $this->assertSame([['user', $this->user->id], ['admin', $this->reviewer->id]], ChatVerificationNotification::query()->where('event', 'submitted')->orderBy('id')->get()->map(fn ($n) => [$n->audience, $n->recipient_id])->all());

        // A closed track can't be asked for.
        $this->postJson("/api/mobile/v1/chat/verification/request/{$uuid}/cancel", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.next_step', 'closed');
        ChatVerificationSetting::current()->update(['paid_enabled' => false]);
        $this->submit()->assertForbidden()->assertJsonPath('error_code', 'chat_verification_track_unavailable');

        // Closed, so a new one is allowed again.
        ChatVerificationSetting::current()->update(['paid_enabled' => true]);
        $this->submit()->assertCreated();
    }

    public function test_review_completion_identity_and_approval_keep_review_and_approval_apart(): void
    {
        $uuid = $this->asUser()->submit()->json('data.uuid');
        $base = "/api/admin/v1/chat-verification/requests/{$uuid}";

        Sanctum::actingAs($this->reviewer, [], 'admin_api');
        $show = $this->getJson($base)->assertOk()->assertJsonPath('data.actions', ['start_review', 'review_documents', 'request_completion', 'extend_deadline', 'pause_deadline']);
        $this->postJson("{$base}/start-review")->assertOk()->assertJsonPath('data.status', 'under_review');

        // The reviewer turns the selfie down and asks for it again.
        $selfie = collect($show->json('data.documents'))->firstWhere('type.code', 'selfie_with_id')['id'];
        $this->postJson("{$base}/documents/{$selfie}/review", ['accepted' => false])->assertUnprocessable()->assertJsonPath('error_code', 'chat_verification_reason_required');
        $this->postJson("{$base}/documents/{$selfie}/review", ['accepted' => false, 'note' => 'Blurry'])->assertOk();
        $this->postJson("{$base}/identity-check")->assertUnprocessable()->assertJsonPath('error_code', 'chat_verification_documents_not_accepted');
        $this->postJson("{$base}/request-completion", ['reason' => 'A clearer selfie, please', 'document_type_ids' => [$this->selfieType]])->assertOk()
            ->assertJsonPath('data.status', 'needs_completion')->assertJsonPath('data.deadline_owner', 'user');

        // The reviewer may not approve (REQ-09-03, AT-09): refused and logged.
        $this->postJson("{$base}/approve")->assertForbidden();
        $this->assertTrue(AuditLog::query()->where('action', 'chat_verification.denied')->where('actor_id', $this->reviewer->id)->exists());

        // The account sees why, and sends the selfie again (the one asked for is required).
        $this->asUser();
        $this->getJson('/api/mobile/v1/chat/verification/request', $this->headers())
            ->assertJsonPath('data.next_step', 'complete')->assertJsonPath('data.completion_reason', 'A clearer selfie, please')->assertJsonPath('data.completion_document_type_ids', [$this->selfieType]);
        $this->post("/api/mobile/v1/chat/verification/request/{$uuid}/complete", ['note' => 'Here'], $this->headers())->assertUnprocessable()->assertJsonPath('error_code', 'chat_verification_documents_missing');
        $this->post("/api/mobile/v1/chat/verification/request/{$uuid}/complete", ['documents' => [$this->selfieType => ['front' => $this->image()]]], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'under_review')->assertJsonPath('data.deadline_owner', 'admin')->assertJsonPath('data.completion_reason', null);

        // Both documents accepted → identity checked → only now the approver can approve.
        Sanctum::actingAs($this->reviewer, [], 'admin_api');
        foreach ($this->getJson($base)->json('data.documents') as $doc) {
            $this->assertSame('pending', $doc['type']['code'] === 'selfie_with_id' ? $doc['status'] : 'pending');
            $this->postJson("{$base}/documents/{$doc['id']}/review", ['accepted' => true])->assertOk();
        }
        Sanctum::actingAs($this->approver, [], 'admin_api');
        $this->postJson("{$base}/approve")->assertUnprocessable()->assertJsonPath('error_code', 'chat_verification_identity_not_checked');
        Sanctum::actingAs($this->reviewer, [], 'admin_api');
        $this->postJson("{$base}/identity-check")->assertOk()->assertJsonPath('data.identity_checked', true);
        Sanctum::actingAs($this->approver, [], 'admin_api');
        $this->postJson("{$base}/approve", ['reason' => 'All good'])->assertOk()->assertJsonPath('data.status', 'preliminary_approved')->assertJsonPath('data.deadline_at', null);

        $this->asUser();
        $this->getJson('/api/mobile/v1/chat/verification/request', $this->headers())->assertJsonPath('data.next_step', 'pay')->assertJsonPath('data.decision_reason', 'All good');

        // The whole trail, and the audit log with who decided.
        $this->assertSame(['new', 'under_review', 'needs_completion', 'under_review', 'preliminary_approved'], ChatVerificationRequest::query()->sole()->transitions->map(fn ($t) => $t->to_status->value)->all());
        $approved = AuditLog::query()->where('action', 'chat_verification.request_approved')->sole();
        $this->assertSame([$this->approver->id, 'All good', ['status' => 'under_review']], [$approved->actor_id, $approved->reason, $approved->before]);
        foreach (['completion_requested', 'documents_uploaded', 'identity_checked', 'preliminary_approved'] as $event) {
            $this->assertTrue(ChatVerificationNotification::query()->where('event', $event)->where('audience', 'user')->exists(), $event);
        }
    }

    public function test_a_rejection_closes_the_request_with_its_reason(): void
    {
        $uuid = $this->asUser()->submit()->json('data.uuid');
        Sanctum::actingAs($this->approver, [], 'admin_api');
        $this->postJson("/api/admin/v1/chat-verification/requests/{$uuid}/reject")->assertUnprocessable();
        $this->postJson("/api/admin/v1/chat-verification/requests/{$uuid}/reject", ['reason' => 'Documents do not match'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson("/api/admin/v1/chat-verification/requests/{$uuid}/approve")->assertStatus(409)->assertJsonPath('error_code', 'chat_verification_status_invalid');

        $this->asUser();
        $this->getJson('/api/mobile/v1/chat/verification/request', $this->headers())->assertJsonPath('data.next_step', 'rejected')->assertJsonPath('data.decision_reason', 'Documents do not match')->assertJsonPath('data.can_cancel', false);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->user->id]);
    }

    public function test_deadlines_warn_move_pause_and_hold_the_request(): void
    {
        $uuid = $this->asUser()->submit()->json('data.uuid');
        $base = "/api/admin/v1/chat-verification/requests/{$uuid}";
        Sanctum::actingAs($this->reviewer, [], 'admin_api');

        $this->postJson("{$base}/deadline", ['until' => now()->subHour()->toIso8601String(), 'reason' => 'x'])->assertUnprocessable()->assertJsonPath('error_code', 'chat_verification_deadline_invalid');
        $this->postJson("{$base}/deadline", ['until' => now()->addHours(10)->toIso8601String(), 'reason' => 'Waiting on a call'])->assertOk();
        $this->assertSame('Waiting on a call', AuditLog::query()->where('action', 'chat_verification.request_deadline_extended')->value('reason'));

        // Within the warning window: warned once.
        $this->artisan('chat:verification-deadlines')->assertSuccessful();
        $this->artisan('chat:verification-deadlines')->assertSuccessful();
        $this->assertSame(2, ChatVerificationNotification::query()->where('event', 'deadline_near')->count()); // the account + the reviewer, once

        // Paused: the clock stops, the sweep leaves it alone; resumed with what was left.
        $this->postJson("{$base}/deadline/pause", ['reason' => 'Legal check'])->assertOk()->assertJsonPath('data.deadline_paused', true)->assertJsonPath('data.deadline_at', null);
        $this->travel(2)->days();
        $this->artisan('chat:verification-deadlines');
        $this->assertSame('new', ChatVerificationRequest::query()->sole()->status->value);
        $this->postJson("{$base}/deadline/resume")->assertOk()->assertJsonPath('data.deadline_paused', false);

        // Past the deadline: on hold, both sides told; the staff bring it back with a new deadline.
        $this->travel(11)->hours();
        $this->artisan('chat:verification-deadlines');
        $held = ChatVerificationRequest::query()->sole();
        $this->assertSame(['suspended', 'new'], [$held->status->value, $held->suspended_from->value]);
        $this->assertSame(3, ChatVerificationNotification::query()->where('event', 'deadline_passed')->count()); // the account, the reviewer and the approver (escalation)
        $this->getJson($base)->assertJsonPath('data.actions', ['resume', 'request_completion']);
        $this->postJson("{$base}/resume", ['until' => now()->addDays(3)->toIso8601String(), 'reason' => 'Extra time'])->assertOk()->assertJsonPath('data.status', 'new');

        // On hold again, then closed as expired by an approver: the account can apply again.
        $this->travel(4)->days();
        $this->artisan('chat:verification-deadlines');
        Sanctum::actingAs($this->approver, [], 'admin_api');
        $this->getJson($base)->assertJsonPath('data.actions', ['expire', 'cancel']);
        $this->postJson("{$base}/expire", ['reason' => 'No answer'])->assertOk()->assertJsonPath('data.status', 'expired');
        $this->assertNull(ChatVerificationRequest::query()->sole()->open_key);
    }

    public function test_the_staff_list_filters_and_counts(): void
    {
        $this->asUser()->submit();
        Sanctum::actingAs($this->reviewer, [], 'admin_api');
        $this->getJson('/api/admin/v1/chat-verification/requests?status=open&track=paid&search=Mona')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.user.name', 'Mona')->assertJsonPath('pagination.counts.new', 1);
        $this->getJson('/api/admin/v1/chat-verification/requests?status=rejected')->assertOk()->assertJsonCount(0, 'data');

        // Someone else's request isn't the account's to open.
        $other = User::create(['name' => 'Omar', 'phone' => '+201000000002', 'country_id' => $this->user->country_id, 'status' => 'active', 'phone_verified_at' => now()]);
        Sanctum::actingAs($other, [], 'user_api');
        $uuid = ChatVerificationRequest::query()->value('uuid');
        $this->postJson("/api/mobile/v1/chat/verification/request/{$uuid}/cancel", [], $this->headers())->assertNotFound();
    }

    private function asUser(): self
    {
        Sanctum::actingAs($this->user, [], 'user_api');

        return $this;
    }

    private function submit(): TestResponse
    {
        return $this->post('/api/mobile/v1/chat/verification/request', [
            'track' => 'paid',
            'full_name' => 'Mona Ali',
            'contact_email' => 'mona@example.com',
            'documents' => [
                $this->idType => ['front' => $this->image(), 'back' => $this->image()],
                $this->selfieType => ['front' => $this->image()],
            ],
        ], $this->headers() + ['Accept' => 'application/json']);
    }

    private function image(): UploadedFile
    {
        return UploadedFile::fake()->image('doc.jpg', 400, 300);
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'EG', 'Accept' => 'application/json'];
    }
}
