<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Admin\Models\Admin;
use Modules\Freelance\Models\FreelanceConsultation;
use Modules\Freelance\Models\FreelanceConsultationRecording;
use Modules\Freelance\Models\FreelanceFeatureRule;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Features\FreelanceFeatures;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\User\Models\User;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * FL-L16: the consultation's video session. The room opens a few minutes before the start and closes at the end; both sides come and go
 * (attendance kept); the session is recorded by LiveKit into the bucket, moved to our disk, downloaded by both through signed links,
 * watched by the staff in a complaint (every use written down) and deleted a week after the end — or a week after the staff's decision.
 */
class FreelanceConsultationSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const API = '/api/freelance/v1/consultations';

    private const CLIENT = '/api/mobile/v1/freelance/consultations';

    private const ADMIN = '/api/admin/v1/freelance-consultations';

    private Country $saudi;

    private Freelancer $sara;

    private User $mona;

    /** @var list<string> the LiveKit / bucket calls made, `METHOD path` */
    private array $calls = [];

    /** What LiveKit answers about a recording. */
    private string $egressStatus = 'EGRESS_ACTIVE';

    protected function setUp(): void
    {
        parent::setUp();

        config(['broadcasting.default' => 'null']);
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-02 08:00:00', 'UTC'));
        config([
            'chat.livekit' => ['url' => 'wss://lk.test', 'api_key' => 'key', 'api_secret' => 'secret-secret-secret-secret', 'token_ttl_seconds' => 21600],
            'chat.recordings.key' => 'bk', 'chat.recordings.secret' => 'bs', 'chat.recordings.bucket' => 'rec', 'chat.recordings.region' => 'auto',
            'chat.recordings.endpoint' => 'https://bucket.test', 'chat.recordings.path_style' => true,
        ]);
        $this->saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'status' => true, 'flag_id' => Flag::create(['code' => 'sa'])->id, 'currency_id' => Currency::create(['code' => 'SAR', 'symbol' => 'SAR'])->id]);
        foreach (['freelance', 'consultations', 'wallet_payment'] as $feature) {
            FreelanceFeatureRule::query()->create(['feature' => $feature, 'country_id' => $this->saudi->id, 'enabled' => true]);
        }
        FreelanceFeatures::forget();
        FreelancePolicies::forget();
        $this->sara = Freelancer::query()->create(['name' => 'Sara', 'phone' => '+966500000091', 'country_id' => $this->saudi->id, 'phone_verified_at' => now(), 'status' => 'verified']);
        $this->mona = User::query()->create(['name' => 'Mona', 'phone' => '+966500000001', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->fakeLiveKit();
    }

    // -------------------------------------------------------------- helpers

    private function fakeLiveKit(): void
    {
        Http::fake(function (HttpRequest $request) {
            $egressStatus = $this->egressStatus;
            $path = parse_url($request->url(), PHP_URL_PATH);
            $this->calls[] = $request->method().' '.$path;
            if (str_ends_with($path, 'StartRoomCompositeEgress')) {
                return Http::response(['egress_id' => 'EG_'.count($this->calls), 'status' => 'EGRESS_STARTING']);
            }
            if (str_ends_with($path, 'ListEgress')) {
                $key = 'freelance-consultations/x/part.mp4';

                return Http::response(['items' => [['egress_id' => $request['egress_id'], 'status' => $egressStatus,
                    'file_results' => [['filename' => $key, 'size' => '7', 'started_at' => '1000000000', 'ended_at' => '61000000000']]]]]);
            }
            if (str_contains($request->url(), 'bucket.test')) {
                return $request->method() === 'GET' ? Http::response('MP4DATA', 200) : Http::response('', 204);
            }

            return Http::response([]);
        });
    }

    private function asSara(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->sara->refresh(), [], 'freelance_api');
    }

    private function asMona(): void
    {
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($this->mona->refresh(), [], 'user_api');
    }

    private function asAdmin(array $permissions = ['freelance-consultations.view', 'freelance-consultations.update']): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $admin = Admin::create(['name' => 'Staff', 'email' => uniqid('a').'@example.com', 'password' => 'secret123', 'status' => 'active']);
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin_api');
            $admin->givePermissionTo($permission);
        }
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($admin, [], 'admin_api');

        return $admin;
    }

    /** A booked 30-minute consultation at 09:00 (both agreed to the recording). */
    private function booked(string $at = '2026-11-02 09:00:00'): FreelanceConsultation
    {
        $start = Carbon::parse($at, 'UTC');

        return FreelanceConsultation::query()->create([
            'freelancer_id' => $this->sara->id, 'client_id' => $this->mona->id, 'country_id' => $this->saudi->id, 'minutes' => 30,
            'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes(30), 'topic' => 'My brand colours and the logo.', 'title' => 'Brand advice',
            'price_minor' => 20000, 'client_fee_minor' => 0, 'commission_minor' => 2000, 'net_minor' => 18000, 'currency_code' => 'SAR',
            'policy' => ['free_cancel_hours' => 24, 'complaint_hours' => 24, 'response_hours' => 24], 'status' => FreelanceConsultation::BOOKED,
            'accepted_at' => now(), 'recording_consent_at' => now(), 'freelancer_consent_at' => now(),
        ]);
    }

    private function sync(): void
    {
        $this->artisan('freelance:sync-consultation-sessions')->assertSuccessful();
    }

    // -------------------------------------------------------------- tests

    public function test_the_room_opens_five_minutes_before_and_both_sides_get_a_ticket_till_the_end(): void
    {
        $c = $this->booked();

        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_session_not_yet')
            ->assertJsonPath('data.opens_at', '2026-11-02T08:55:00+00:00');
        $this->assertFalse($this->getJson(self::CLIENT."/{$c->uuid}")->assertOk()->json('data.session.can.join'));

        $this->travelTo(Carbon::parse('2026-11-02 08:56:00', 'UTC'));
        $ticket = $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertOk();
        $this->assertSame('consult-'.$c->uuid, $ticket->json('data.room'));
        $this->assertSame('client-'.$this->mona->id, $ticket->json('data.identity'));
        $this->assertTrue($ticket->json('data.recording'));
        // The token lets her in until the end, not for the usual hours.
        $claims = json_decode(base64_decode(strtr(explode('.', $ticket->json('data.token'))[1], '-_', '+/')), true);
        $this->assertSame('consult-'.$c->uuid, $claims['video']['room']);
        // (signed with the real clock: from 08:56 to the 09:30 end and a minute more)
        $this->assertLessThanOrEqual(35 * 60 + 10, $claims['exp'] - $claims['nbf']);
        $this->assertContains('POST /twirp/livekit.RoomService/CreateRoom', $this->calls);

        // Someone else's consultation is not hers.
        $other = User::query()->create(['name' => 'Ola', 'phone' => '+966500000002', 'country_id' => $this->saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($other, [], 'user_api');
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertNotFound();

        $this->travelTo(Carbon::parse('2026-11-02 09:30:00', 'UTC'));
        $this->asSara();
        $this->postJson(self::API."/{$c->uuid}/session/join")->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_session_over');
    }

    public function test_attendance_recording_parts_and_the_other_side_is_told(): void
    {
        $c = $this->booked();
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'UTC'));

        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertOk();
        $state = $this->postJson(self::CLIENT."/{$c->uuid}/session/connected")->assertOk();
        $this->assertTrue($state->json('data.in_room.client'));
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.consultation.session_joined')->count());
        $this->assertSame(1, FreelanceConsultationRecording::query()->where('status', 'recording')->count());

        $this->asSara();
        $this->postJson(self::API."/{$c->uuid}/session/join")->assertOk()->assertJsonPath('data.other.in_room', true);
        $this->postJson(self::API."/{$c->uuid}/session/connected")->assertOk();
        // Still one part: the recording was already running.
        $this->assertSame(1, FreelanceConsultationRecording::query()->count());

        // Both leave (by mistake): the part ends; coming back starts part 2.
        $this->travel(5)->minutes();
        $this->postJson(self::API."/{$c->uuid}/session/leave")->assertOk();
        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/leave")->assertOk();
        $this->assertSame('processing', FreelanceConsultationRecording::query()->where('part', 1)->value('status'));
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertOk();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/connected")->assertOk();
        $this->assertSame('recording', FreelanceConsultationRecording::query()->where('part', 2)->value('status'));
        // She came back: no second "joined" notice for the freelancer.
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.consultation.session_joined')->count());

        $session = $this->getJson(self::CLIENT."/{$c->uuid}/session")->assertOk();
        $this->assertCount(3, $session->json('data.attendance'));
        $this->assertCount(2, $session->json('data.recordings'));
    }

    public function test_the_room_closes_at_the_end_and_the_recording_moves_home_then_goes_a_week_later(): void
    {
        $c = $this->booked();
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'UTC'));
        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertOk();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/connected")->assertOk();

        // Five minutes before the end both are warned once.
        $this->travelTo(Carbon::parse('2026-11-02 09:26:00', 'UTC'));
        $this->sync();
        $this->sync();
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.consultation.session_ending')->count());
        $this->assertSame(1, $this->sara->notifications()->where('data->event', 'freelance.consultation.session_ending')->count());

        // The end: the room goes, the stay ends at the end, the recording stops; LiveKit then saves it and it comes to our disk.
        $this->travelTo(Carbon::parse('2026-11-02 09:31:00', 'UTC'));
        $this->egressStatus = 'EGRESS_COMPLETE';
        $this->sync();
        $c->refresh();
        $this->assertSame('time', $c->session_closed_by);
        $this->assertContains('POST /twirp/livekit.RoomService/DeleteRoom', $this->calls);
        $this->assertSame('2026-11-09 09:30:00', $c->recordings_delete_at->format('Y-m-d H:i:s'));
        $r = FreelanceConsultationRecording::query()->firstOrFail();
        $this->assertSame('stored', $r->status);
        $this->assertNull($r->bucket_key);
        Storage::disk('local')->assertExists($r->file_path);
        $this->assertTrue(collect($this->calls)->contains(fn ($call) => str_starts_with($call, 'DELETE /rec/')));
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.consultation.recording_ready')->count());

        // Both download through a signed link; each use is written down. Unsigned it does not open.
        $url = $this->getJson(self::CLIENT."/{$c->uuid}/session")->json('data.recordings.0.download_url');
        $this->app['auth']->forgetGuards();
        $this->get($url)->assertOk()->assertHeader('content-type', 'video/mp4');
        $this->get(strtok($url, '?').'?viewer=client&viewer_id=1&action=download')->assertForbidden();
        $this->assertDatabaseHas('freelance_consultation_recording_views', ['viewer_type' => 'client', 'viewer_id' => $this->mona->id, 'action' => 'download']);
        $this->asSara();
        $this->assertNotNull($this->getJson(self::API."/{$c->uuid}/session")->json('data.recordings.0.watch_url'));

        // A week on, it is gone from our disk.
        $this->travelTo(Carbon::parse('2026-11-09 09:31:00', 'UTC'));
        $this->sync();
        $this->assertSame('deleted', $r->refresh()->status);
        Storage::disk('local')->assertMissing('freelance/consultation-recordings/'.$c->uuid.'/'.$r->uuid.'.mp4');
        $this->assertNotNull($c->refresh()->recordings_deleted_at);
    }

    public function test_a_complaint_keeps_the_recording_until_a_week_after_the_staff_decide(): void
    {
        $c = $this->booked();
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'UTC'));
        $this->asSara();
        $this->postJson(self::API."/{$c->uuid}/session/join")->assertOk();
        $this->postJson(self::API."/{$c->uuid}/session/connected")->assertOk();
        $this->travelTo(Carbon::parse('2026-11-02 09:31:00', 'UTC'));
        $this->egressStatus = 'EGRESS_COMPLETE';
        $this->sync();

        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/complain", ['text' => 'The advice was not about my brand at all.'])->assertOk();
        $this->assertNull($c->refresh()->recordings_delete_at);
        $this->assertTrue($this->getJson(self::CLIENT."/{$c->uuid}/session")->json('data.recordings_held'));

        // Long past a week: still kept while the complaint is open.
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00', 'UTC'));
        $this->sync();
        $this->assertSame('stored', FreelanceConsultationRecording::query()->value('status'));

        // The staff watch it (written down), decide, and it goes a week after.
        $admin = $this->asAdmin();
        $session = $this->getJson(self::ADMIN."/{$c->uuid}/session")->assertOk();
        $this->app['auth']->forgetGuards();
        $this->get($session->json('data.recordings.0.watch_url'))->assertOk();
        $this->asAdmin();
        $this->assertSame('staff', $this->getJson(self::ADMIN."/{$c->uuid}/session")->json('data.views.0.viewer'));
        $this->assertDatabaseHas('freelance_consultation_recording_views', ['viewer_type' => 'staff', 'viewer_id' => $admin->id, 'action' => 'watch']);
        $this->postJson(self::ADMIN."/{$c->uuid}/resolve", ['decision' => 'refund', 'note' => 'Not what was booked.'])->assertOk();
        $this->assertSame('2026-11-27', $c->refresh()->recordings_delete_at->toDateString());
        $this->travelTo(Carbon::parse('2026-11-27 10:01:00', 'UTC'));
        $this->sync();
        $this->assertSame('deleted', FreelanceConsultationRecording::query()->value('status'));
    }

    public function test_the_staff_close_and_reopen_while_time_is_left(): void
    {
        $c = $this->booked();
        $this->travelTo(Carbon::parse('2026-11-02 09:05:00', 'UTC'));
        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertOk();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/connected")->assertOk();

        $this->asAdmin(['freelance-consultations.view']);
        $this->postJson(self::ADMIN."/{$c->uuid}/session/close")->assertForbidden();
        $this->asAdmin();
        $this->postJson(self::ADMIN."/{$c->uuid}/session/close")->assertOk()->assertJsonPath('data.closed.by', 'staff');
        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertStatus(409)->assertJsonPath('error_code', 'freelance_consultation_session_closed');
        $this->assertSame(1, $this->mona->notifications()->where('data->event', 'freelance.consultation.session_closed')->count());

        $this->asAdmin();
        $this->postJson(self::ADMIN."/{$c->uuid}/session/reopen")->assertOk()->assertJsonPath('data.closed', null);
        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertOk();

        $this->travelTo(Carbon::parse('2026-11-02 09:31:00', 'UTC'));
        $this->asAdmin();
        $this->postJson(self::ADMIN."/{$c->uuid}/session/close")->assertOk();
        $this->postJson(self::ADMIN."/{$c->uuid}/session/reopen")->assertStatus(409);
    }

    public function test_the_client_says_the_freelancer_did_not_come(): void
    {
        $c = $this->booked();
        $this->asMona();
        $this->travelTo(Carbon::parse('2026-11-02 09:05:00', 'UTC'));
        $this->postJson(self::CLIENT."/{$c->uuid}/no-show")->assertStatus(409);

        $this->travelTo(Carbon::parse('2026-11-02 09:11:00', 'UTC'));
        $this->assertTrue($this->getJson(self::CLIENT."/{$c->uuid}")->json('data.session.can.no_show'));
        $this->postJson(self::CLIENT."/{$c->uuid}/no-show")->assertOk()->assertJsonPath('data.status', 'disputed');
        $this->assertStringContainsString('10', (string) $c->refresh()->complaint);
    }

    public function test_without_the_bucket_the_session_runs_unrecorded_and_booking_needs_the_yes(): void
    {
        config(['chat.recordings.key' => null]);
        $c = $this->booked();
        $this->travelTo(Carbon::parse('2026-11-02 09:00:00', 'UTC'));
        $this->asMona();
        $this->postJson(self::CLIENT."/{$c->uuid}/session/join")->assertOk()->assertJsonPath('data.recording', false);
        $this->postJson(self::CLIENT."/{$c->uuid}/session/connected")->assertOk()->assertJsonPath('data.recording_available', false);
        $this->assertSame(0, FreelanceConsultationRecording::query()->count());

        // Booking without agreeing to the recording is refused before anything else.
        $this->postJson(self::CLIENT, ['offer' => (string) \Illuminate\Support\Str::uuid(), 'minutes' => 30, 'starts_at' => '2026-11-09T09:00:00Z', 'topic' => 'I need help with my brand colours.'])
            ->assertStatus(422)->assertJsonPath('error_code', 'freelance_consultation_recording_consent_required');
    }
}
