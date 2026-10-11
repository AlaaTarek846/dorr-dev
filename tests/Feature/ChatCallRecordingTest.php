<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatCallRecording;
use Modules\Chat\Models\ChatContact;
use Modules\Chat\Models\ChatMessage;
use Modules\Chat\Models\ChatSetting;
use Modules\Chat\Support\RecordingStorage;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Call recording (spec 92, safety rule 1): nothing is recorded until everyone in the call said
 * yes — one no, or silence until the deadline, and LiveKit is never asked; someone joining a call
 * that records agrees first; anyone stops it; only the people who agreed play the file, through a
 * signed link; it's deleted after the retention days.
 */
class ChatCallRecordingTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    private User $dave;

    protected function setUp(): void
    {
        parent::setUp();

        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        Language::create(['code' => 'en', 'direction' => 'ltr', 'is_default_website' => true, 'is_default_dashboard' => true, 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->alice = $make('Alice', '+966500000001');
        $this->bob = $make('Bob', '+966500000002');
        $this->carol = $make('Carol', '+966500000003');
        $this->dave = $make('Dave', '+966500000004');
        foreach ([$this->bob, $this->carol] as $other) {
            foreach ([[$this->alice, $other], [$other, $this->alice]] as [$owner, $contact]) {
                ChatContact::query()->create(['owner_type' => 'user', 'owner_id' => $owner->id, 'name' => $contact->name, 'phone' => $contact->phone, 'contact_type' => 'user', 'contact_id' => $contact->id, 'source' => 'manual']);
            }
        }
        config([
            'chat.livekit.url' => 'wss://live.example.test', 'chat.livekit.api_key' => 'key', 'chat.livekit.api_secret' => 'secret-that-is-long-enough',
            'chat.recordings.key' => 'AKIDRECORD', 'chat.recordings.secret' => 'recording-secret', 'chat.recordings.region' => 'auto',
            'chat.recordings.bucket' => 'dorr-recordings', 'chat.recordings.endpoint' => 'https://storage.example.test', 'chat.recordings.path_style' => true,
        ]);
        Http::fake([
            '*/twirp/livekit.Egress/StartRoomCompositeEgress' => Http::response(['egress_id' => 'EG_one', 'status' => 'EGRESS_STARTING']),
            '*/twirp/livekit.Egress/StopEgress' => Http::response(['egress_id' => 'EG_one', 'status' => 'EGRESS_ENDING']),
            '*/twirp/livekit.Egress/ListEgress' => fn () => Http::response(['items' => [[
                'egress_id' => 'EG_one',
                'status' => 'EGRESS_COMPLETE',
                // int64 as strings, times in nanoseconds — as LiveKit sends them.
                'file_results' => [['filename' => ChatCallRecording::query()->latest('id')->value('file_path'), 'started_at' => '1792519200000000000', 'ended_at' => '1792519392000000000', 'size' => '2400000']],
            ]]]),
            'storage.example.test/*' => Http::response('', 204),
            '*' => Http::response([], 200),
        ]);
        $this->travelTo(Carbon::parse('2026-10-20 18:00', 'UTC'));
    }

    public function test_nothing_is_recorded_unless_everyone_in_the_call_says_yes(): void
    {
        [$call] = $this->directCall();

        // Alice asks: her asking is her yes; Bob still has to answer.
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording", [], $this->headers())->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.waiting_for', ['user:'.$this->bob->id]);
        // Bob says no: nothing is recorded, LiveKit is never asked.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording/consent", ['accept' => false], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'declined')->assertJsonPath('data.declined', ['user:'.$this->bob->id]);

        // Asked again, and Bob doesn't answer in time: dropped.
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording", [], $this->headers())->assertCreated()->assertJsonPath('data.status', 'pending');
        $this->travel(31)->seconds();
        $this->artisan('chat:call-recordings')->assertSuccessful();
        $this->assertSame('expired', ChatCallRecording::query()->latest('id')->value('status'));
        // Too late to say yes now.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording/consent", ['accept' => true], $this->headers())->assertStatus(409)
            ->assertJsonPath('error_code', 'chat_recording_not_asking');

        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'livekit.Egress'));
        // Only people in the call can ask.
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording", [], $this->headers())->assertForbidden();
    }

    public function test_everyone_agrees_then_it_records_and_only_they_can_play_it(): void
    {
        $this->as($this->alice);
        $group = $this->postJson('/api/mobile/v1/chat/groups', ['name' => 'Studio', 'members' => [$this->bob->id, $this->carol->id]], $this->headers())->json('data.conversation.id');
        $call = $this->postJson("/api/mobile/v1/chat/conversations/{$group}/calls", ['type' => 'video'], $this->headers())->assertCreated()
            ->assertJsonPath('data.call.recording_available', true)->json('data.call.id');
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/accept", [], $this->headers())->assertOk();

        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording", [], $this->headers())->assertCreated()->assertJsonPath('data.status', 'pending');
        // The last yes starts it: the whole room, cameras in a grid, straight into our bucket.
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording/consent", ['accept' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.status', 'recording')->assertJsonPath('data.waiting_for', []);
        $recording = ChatCallRecording::query()->sole();
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/twirp/livekit.Egress/StartRoomCompositeEgress')
            && $r['room_name'] === $recording->call->room_name && $r['audio_only'] === false
            && $r['file_outputs'][0]['filepath'] === "call-recordings/{$call}/{$recording->uuid}.mp4"
            && $r['file_outputs'][0]['s3']['bucket'] === 'dorr-recordings' && $r['file_outputs'][0]['s3']['force_path_style'] === true
            && json_decode(base64_decode(strtr(explode('.', $r->header('Authorization')[0])[1], '-_', '+/')), true)['video']['roomRecord'] === true);
        $this->assertSame('EG_one', $recording->egress_id);

        // Carol rings in later: she's let in only with her own yes.
        $this->as($this->carol);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/accept", [], $this->headers())->assertStatus(409)
            ->assertJsonPath('error_code', 'chat_recording_consent_required')->assertJsonPath('data.recording.status', 'recording');
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/accept", ['recording_consent' => true], $this->headers())->assertOk()
            ->assertJsonPath('data.call.recording.agreed', ['user:'.$this->alice->id, 'user:'.$this->bob->id, 'user:'.$this->carol->id]);

        // Anyone in the call stops it; LiveKit saves the file.
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording/stop", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'processing');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/StopEgress') && $r['egress_id'] === 'EG_one');
        $this->artisan('chat:call-recordings')->assertSuccessful();
        $recording->refresh();
        $this->assertSame('ready', $recording->status);
        $this->assertSame(192, $recording->duration_seconds);
        $this->assertSame(2400000, $recording->size_bytes);
        $this->assertTrue($recording->expires_at->equalTo(now()->addDays(30)));

        // The people who agreed play it through a link signed for a few minutes.
        $this->as($this->bob);
        $url = $this->getJson("/api/mobile/v1/chat/calls/{$call}/recordings", $this->headers())->assertOk()
            ->assertJsonPath('data.0.status', 'ready')->assertJsonPath('data.0.type', 'video')->assertJsonPath('data.0.duration_seconds', 192)->json('data.0.url');
        $this->assertStringStartsWith("https://storage.example.test/dorr-recordings/call-recordings/{$call}/{$recording->uuid}.mp4?X-Amz-Algorithm=AWS4-HMAC-SHA256", $url);
        $this->assertStringContainsString('X-Amz-Expires=900', $url);
        $this->getJson('/api/mobile/v1/chat/calls', $this->headers())->assertJsonPath('data.0.recordings_count', 1);
        // Not someone who isn't in the chat.
        $this->as($this->dave);
        $this->getJson("/api/mobile/v1/chat/calls/{$call}/recordings", $this->headers())->assertForbidden();
    }

    public function test_the_call_ending_stops_the_recording_and_the_chat_line_says_it_was_recorded(): void
    {
        [$call] = $this->directCall('audio');
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording", [], $this->headers())->assertCreated();
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording/consent", ['accept' => true], $this->headers())->assertJsonPath('data.status', 'recording');
        // A voice call records the voices only.
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/StartRoomCompositeEgress') && $r['audio_only'] === true);

        $this->postJson("/api/mobile/v1/chat/calls/{$call}/leave", [], $this->headers())->assertOk()->assertJsonPath('data.call.status', 'ended');
        $this->assertSame('processing', ChatCallRecording::query()->sole()->status);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/StopEgress'));
        $this->assertTrue(ChatMessage::query()->where('type', 'call')->sole()->meta['recorded']);
    }

    public function test_a_recording_is_deleted_after_the_retention_days(): void
    {
        ChatSetting::current()->update(['call_recording_retention_days' => 7]);
        [$call] = $this->directCall();
        $this->as($this->alice);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording", [], $this->headers());
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording/consent", ['accept' => true], $this->headers());
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording/stop", [], $this->headers());
        $this->artisan('chat:call-recordings');
        $recording = ChatCallRecording::query()->sole();
        $this->assertSame('ready', $recording->status);
        $key = $recording->file_path;

        $this->travel(7)->days();
        $this->travel(1)->minutes();
        $this->artisan('chat:call-recordings')->assertSuccessful();
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_starts_with($r->url(), "https://storage.example.test/dorr-recordings/{$key}?") && str_contains($r->url(), 'X-Amz-Signature='));
        $this->assertSame('deleted', $recording->refresh()->status);
        $this->getJson("/api/mobile/v1/chat/calls/{$call}/recordings", $this->headers())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_no_recording_without_a_bucket_or_when_the_admin_turned_it_off(): void
    {
        config(['chat.recordings.bucket' => null]);
        [$call] = $this->directCall();
        $this->as($this->alice);
        $this->getJson("/api/mobile/v1/chat/calls/{$call}", $this->headers())->assertJsonPath('data.recording_available', false);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording", [], $this->headers())->assertStatus(422)->assertJsonPath('error_code', 'chat_recording_unavailable');

        config(['chat.recordings.bucket' => 'dorr-recordings']);
        ChatSetting::current()->update(['call_recording_enabled' => false]);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/recording", [], $this->headers())->assertStatus(422);
        $this->assertSame(0, ChatCallRecording::query()->count());
    }

    /** AWS's own worked example: https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-query-string-auth.html */
    public function test_play_links_are_signed_like_aws_signature_version_4(): void
    {
        config([
            'chat.recordings.key' => 'AKIAIOSFODNN7EXAMPLE', 'chat.recordings.secret' => 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY',
            'chat.recordings.region' => 'us-east-1', 'chat.recordings.bucket' => 'examplebucket',
            'chat.recordings.endpoint' => 'https://s3.amazonaws.com', 'chat.recordings.path_style' => false,
        ]);
        $this->travelTo(Carbon::parse('2013-05-24 00:00:00', 'UTC'));

        $this->assertSame(
            'https://examplebucket.s3.amazonaws.com/test.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=AKIAIOSFODNN7EXAMPLE%2F20130524%2Fus-east-1%2Fs3%2Faws4_request&X-Amz-Date=20130524T000000Z&X-Amz-Expires=86400&X-Amz-SignedHeaders=host&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404',
            app(RecordingStorage::class)->presign('GET', 'test.txt', 86400),
        );
    }

    /**
     * Alice calls Bob and he answers.
     *
     * @return array{0: string, 1: string} the call and the conversation
     */
    private function directCall(string $type = 'video'): array
    {
        $this->as($this->alice);
        $direct = $this->postJson('/api/mobile/v1/chat/conversations/direct', ['participant_id' => $this->bob->id], $this->headers())->json('data.id');
        $call = $this->postJson("/api/mobile/v1/chat/conversations/{$direct}/calls", ['type' => $type], $this->headers())->assertCreated()->json('data.call.id');
        $this->as($this->bob);
        $this->postJson("/api/mobile/v1/chat/calls/{$call}/accept", [], $this->headers())->assertOk()->assertJsonPath('data.call.status', 'ongoing');

        return [$call, $direct];
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
