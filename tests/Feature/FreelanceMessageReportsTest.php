<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Modules\Freelance\Models\FreelanceConversation;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\FreelanceMessage;
use Modules\Freelance\Models\FreelanceMessageReport;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Chat\FreelanceChatService;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * FL-709: a person of a room reports a message the other one sent; staff review it. Only the other person's message, once; the text is
 * kept when the sender takes it back; the reported person is not told; deciding changes no account.
 */
class FreelanceMessageReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $client;

    private Freelancer $freelancer;

    private FreelanceConversation $room;

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'chat'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        $this->client = User::query()->create(['name' => 'Mona Ali', 'phone' => '+966500000001', 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->freelancer = Freelancer::query()->create(['name' => 'Sara Hassan', 'phone' => '+966500000077', 'country_id' => $saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->room = app(FreelanceChatService::class)->open($this->client, $this->freelancer, 'project', 42);
    }

    private function say(string $side, string $body): FreelanceMessage
    {
        return FreelanceMessage::query()->create([
            'conversation_id' => $this->room->id, 'sender_type' => $side, 'sender_id' => $side === 'client' ? $this->client->id : $this->freelancer->id,
            'kind' => 'text', 'body' => $body, 'created_at' => now(),
        ]);
    }

    private function asClient(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->client, [], 'user_api');
    }

    private function asFreelancer(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->freelancer, [], 'freelance_api');
    }

    private function asStaff(string ...$permissions): \Modules\Admin\Models\Admin
    {
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = \Modules\Admin\Models\Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $p) {
            \Spatie\Permission\Models\Permission::findOrCreate($p, 'admin_api');
            $admin->givePermissionTo($p);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    public function test_the_other_persons_message_can_be_reported_once_from_either_side(): void
    {
        $theirs = $this->say('freelancer', 'Pay me outside or I will tell everyone');
        $mine = $this->say('client', 'Hello');

        $this->asClient();
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$theirs->id}/report", ['reason' => 'blackmail', 'note' => 'Threatening me'])->assertCreated();
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$theirs->id}/report", ['reason' => 'abuse'])->assertStatus(409);
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$mine->id}/report", ['reason' => 'abuse'])->assertStatus(422);
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$theirs->id}/report", ['reason' => 'nonsense'])->assertStatus(422);

        $this->asFreelancer();
        $this->postJson("/api/freelance/v1/chat/messages/{$mine->id}/report", ['reason' => 'spam'])->assertCreated();
        $this->assertSame(2, FreelanceMessageReport::query()->count());
        $this->assertSame(0, $this->freelancer->notifications()->count(), 'the reported person is not told');
    }

    public function test_a_stranger_or_a_system_line_cannot_be_reported_and_the_text_is_kept(): void
    {
        $theirs = $this->say('freelancer', 'You are useless');
        $system = FreelanceMessage::query()->create(['conversation_id' => $this->room->id, 'sender_type' => 'system', 'sender_id' => null, 'kind' => 'system', 'body' => 'Interview set', 'created_at' => now()]);
        $outsider = User::query()->create(['name' => 'Other', 'phone' => '+966500000002', 'country_id' => $this->client->country_id, 'status' => 'active', 'phone_verified_at' => now()]);

        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($outsider, [], 'user_api');
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$theirs->id}/report", ['reason' => 'abuse'])->assertNotFound();

        $this->asClient();
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$system->id}/report", ['reason' => 'abuse'])->assertStatus(422);
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$theirs->id}/report", ['reason' => 'abuse'])->assertCreated();

        // The sender takes it back: staff still see what it said.
        $theirs->forceFill(['body' => null, 'deleted_at' => now()])->save();
        $this->asStaff('freelance-message-reports.view');
        $this->getJson('/api/admin/v1/freelance-message-reports')->assertOk()
            ->assertJsonPath('data.0.message.body', 'You are useless')->assertJsonPath('data.0.reporter.name', 'Mona Ali')->assertJsonPath('data.0.reported.name', 'Sara Hassan');
    }

    public function test_staff_dismiss_or_uphold_once_and_may_hide_the_message_without_touching_any_account(): void
    {
        $bad = $this->say('freelancer', 'Insulting words');
        $fine = $this->say('freelancer', 'Sorry for the delay');
        $this->asClient();
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$bad->id}/report", ['reason' => 'abuse'])->assertCreated();
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$fine->id}/report", ['reason' => 'other'])->assertCreated();

        $this->asStaff('freelance-message-reports.view');
        $list = $this->getJson('/api/admin/v1/freelance-message-reports?status=open')->assertOk()->assertJsonPath('pagination.counts.open', 2)->json('data');
        $this->assertCount(2, $list);
        // A viewer cannot decide.
        $uuidBad = FreelanceMessageReport::query()->where('message_id', $bad->id)->value('uuid');
        $uuidFine = FreelanceMessageReport::query()->where('message_id', $fine->id)->value('uuid');
        $this->postJson("/api/admin/v1/freelance-message-reports/{$uuidBad}/decide", ['decision' => 'uphold'])->assertForbidden();

        $this->asStaff('freelance-message-reports.view', 'freelance-message-reports.update');
        $this->postJson("/api/admin/v1/freelance-message-reports/{$uuidBad}/decide", ['decision' => 'uphold', 'hide_message' => true, 'note' => 'Clear insult'])
            ->assertOk()->assertJsonPath('data.status', 'upheld')->assertJsonPath('data.message_hidden', true);
        $this->assertNotNull($bad->refresh()->deleted_at);
        $this->assertNull($bad->body);
        $this->postJson("/api/admin/v1/freelance-message-reports/{$uuidBad}/decide", ['decision' => 'dismiss'])->assertStatus(409);

        $this->postJson("/api/admin/v1/freelance-message-reports/{$uuidFine}/decide", ['decision' => 'dismiss'])->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->assertNull($fine->refresh()->deleted_at);
        $this->assertSame('verified', $this->freelancer->refresh()->status->value ?? $this->freelancer->status, 'no account is touched');
    }

    public function test_the_file_of_a_reported_message_stays_for_staff_even_after_the_message_is_hidden(): void
    {
        $msg = $this->say('freelancer', 'See the attachment');
        $msg->forceFill(['kind' => 'file'])->save();
        $msg->setSingleMedia('attachment', \Illuminate\Http\UploadedFile::fake()->create('threat.pdf', 12, 'application/pdf'));

        $this->asClient();
        $this->postJson("/api/mobile/v1/freelance/chat/messages/{$msg->id}/report", ['reason' => 'harassment'])->assertCreated();
        $uuid = FreelanceMessageReport::query()->value('uuid');

        $this->asStaff('freelance-message-reports.view', 'freelance-message-reports.update');
        $this->getJson('/api/admin/v1/freelance-message-reports')->assertOk()->assertJsonPath('data.0.file.name', 'threat.pdf')->assertJsonPath('data.0.file.path', "freelance-message-reports/{$uuid}/file");
        $this->postJson("/api/admin/v1/freelance-message-reports/{$uuid}/decide", ['decision' => 'uphold', 'hide_message' => true])->assertOk();
        $this->assertNull($msg->refresh()->getSingleMedia('attachment'), 'the room no longer has it');
        $this->get("/api/admin/v1/freelance-message-reports/{$uuid}/file")->assertOk();
    }
}
