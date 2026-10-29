<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Educational groups (docs/groups_tasks.md GR-011..017): terms and the next one, lessons by scope,
 * homework and private grades, tests marked automatically and by hand without leaking answers,
 * sessions and attendance.
 */
class ChatGroupEducationTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $amal;

    private User $badr;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
        $sar = Currency::create(['code' => 'SAR', 'symbol' => 'SAR', 'decimal_places' => 2]);
        $flag = Flag::create(['code' => 'sa']);
        $saudi = Country::create(['code' => 'SA', 'dial_code' => '+966', 'phone_length' => 9, 'is_default' => true, 'flag_id' => $flag->id, 'currency_id' => $sar->id, 'status' => true]);
        foreach (['en' => 'ltr', 'ar' => 'rtl'] as $code => $direction) {
            Language::create(['code' => $code, 'direction' => $direction, 'is_default_website' => $code === 'en', 'is_default_dashboard' => $code === 'en', 'stores_translation' => true, 'status' => true, 'flag_id' => $flag->id]);
        }

        $make = fn (string $name, string $phone) => User::create(['name' => $name, 'phone' => $phone, 'country_id' => $saudi->id, 'status' => 'active', 'phone_verified_at' => now()]);
        $this->teacher = $make('Teacher', '+966500000001');
        $this->amal = $make('Amal', '+966500000002');
        $this->badr = $make('Badr', '+966500000003');
    }

    public function test_a_new_term_closes_the_old_one_and_moves_only_the_chosen_students(): void
    {
        $class = $this->classGroup();
        [$amal, $badr] = [$this->participant($class, 'Amal'), $this->participant($class, 'Badr')];

        $this->as($this->teacher);
        $term1 = $this->postJson("/api/mobile/v1/chat/groups/{$class}/spaces", ['type' => 'term', 'name' => 'Term 1', 'members' => [$amal, $badr]], $this->headers())->json('data');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/spaces/{$term1['id']}/members", $this->headers())->assertOk()->assertJsonCount(3, 'data.participant_ids'); // the teacher (owner) too

        $term2 = $this->postJson("/api/mobile/v1/chat/groups/{$class}/spaces/{$term1['id']}/next-term", ['name' => 'Term 2', 'members' => [$amal]], $this->headers())
            ->assertCreated()->assertJsonPath('data.members_count', 2)->json('data');

        $this->as($this->badr);
        $spaces = collect($this->getJson("/api/mobile/v1/chat/groups/{$class}/spaces", $this->headers())->json('data'));
        $this->assertTrue($spaces->firstWhere('id', $term1['id'])['is_closed']);
        $this->assertNull($spaces->firstWhere('id', $term2['id']));
    }

    public function test_lessons_are_seen_only_in_their_scope(): void
    {
        $class = $this->classGroup();
        $amal = $this->participant($class, 'Amal');

        $this->as($this->teacher);
        $sub = $this->postJson("/api/mobile/v1/chat/groups/{$class}/spaces", ['type' => 'subgroup', 'name' => '3A', 'members' => [$amal]], $this->headers())->json('data.id');
        $this->post("/api/mobile/v1/chat/groups/{$class}/lessons", ['title' => 'Fractions', 'body' => 'Halves and quarters', 'files' => [UploadedFile::fake()->create('sheet.pdf', 20, 'application/pdf')]], $this->headers() + ['Accept' => 'application/json'])->assertCreated();
        $private = $this->post("/api/mobile/v1/chat/groups/{$class}/lessons", ['title' => 'Extra 3A', 'space_id' => $sub, 'files' => [UploadedFile::fake()->create('extra.pdf', 20, 'application/pdf')]], $this->headers() + ['Accept' => 'application/json'])->assertCreated()->json('data');

        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons", $this->headers())->assertJsonCount(2, 'data.lessons');
        $amalUrl = $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons/{$private['id']}", $this->headers())->json('data.files.0.url');
        $this->get($amalUrl)->assertOk();

        // Badr (not in 3A): neither the lesson nor a search hit.
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons", $this->headers())->assertJsonCount(1, 'data.lessons');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons?q=Extra", $this->headers())->assertJsonCount(0, 'data.lessons');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons/{$private['id']}", $this->headers())->assertNotFound();
        $this->post("/api/mobile/v1/chat/groups/{$class}/lessons", ['title' => 'Mine'], $this->headers() + ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_homework_is_handed_in_again_graded_per_student_and_kept_private(): void
    {
        $class = $this->classGroup();
        $amal = $this->participant($class, 'Amal');

        $this->as($this->teacher);
        $hw = $this->postJson("/api/mobile/v1/chat/groups/{$class}/assignments", ['title' => 'Essay', 'due_at' => now()->addDay()->toIso8601String(), 'max_score' => 10], $this->headers())->assertCreated()->json('data.id');

        $this->as($this->amal);
        $this->post("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/submit", ['body' => 'Draft'], $this->headers() + ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.version', 1);
        $this->post("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/submit", ['body' => 'Final', 'files' => [UploadedFile::fake()->create('essay.pdf', 20, 'application/pdf')]], $this->headers() + ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('data.version', 2)->assertJsonPath('data.body', 'Final');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/submissions", $this->headers())->assertForbidden();

        $this->as($this->teacher);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/submissions", $this->headers())->assertOk()
            ->assertJsonCount(1, 'data.submissions')->assertJsonPath('data.missing.0.profile.account_name', 'Badr');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/grades", ['participant_id' => $amal, 'score' => 12], $this->headers())->assertStatus(422);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/grades", ['participant_id' => $amal, 'score' => 9, 'feedback' => 'Well done'], $this->headers())->assertOk();
        $fileUrl = $this->getJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/submissions", $this->headers())->json('data.submissions.0.files.0.url');
        $this->get($fileUrl)->assertOk();

        // Amal sees her own grade; Badr sees neither her hand-in nor her grade.
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}", $this->headers())->assertJsonPath('data.my_grade.score', 9);
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}", $this->headers())
            ->assertJsonPath('data.my_submission', null)->assertJsonPath('data.my_grade', null)->assertJsonPath('data.submissions_count', null);
    }

    public function test_a_test_is_marked_automatically_and_by_hand_without_showing_the_answers_early(): void
    {
        $class = $this->classGroup();

        $this->as($this->teacher);
        $bank = $this->postJson("/api/mobile/v1/chat/groups/{$class}/question-bank", ['subject' => 'Maths', 'type' => 'true_false', 'text' => '2 is even', 'correct' => true, 'points' => 1], $this->headers())->assertCreated()->json('data.id');
        $quiz = $this->postJson("/api/mobile/v1/chat/groups/{$class}/quizzes", [
            'title' => 'Quiz 1', 'duration_minutes' => 20,
            'questions' => [
                ['type' => 'choice', 'text' => '1 + 1 = ?', 'options' => ['1', '2', '3'], 'correct' => [1], 'points' => 2],
                ['type' => 'essay', 'text' => 'Explain zero', 'points' => 3],
            ],
            'bank_ids' => [$bank],
        ], $this->headers())->assertCreated()->assertJsonPath('data.questions_count', 3)->json('data.id');

        // Not published: students don't see it.
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/quizzes", $this->headers())->assertJsonCount(0, 'data.quizzes');
        $this->as($this->teacher);
        $this->patchJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}", ['is_published' => true], $this->headers())->assertOk();

        $this->as($this->amal);
        $started = $this->postJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/start", [], $this->headers())->assertOk()->json('data');
        $this->assertNull($started['questions'][0]['correct']);
        $ids = array_column($started['questions'], 'id');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/submit", ['answers' => [$ids[0] => [1], $ids[1] => 'Nothing', $ids[2] => false]], $this->headers())
            ->assertOk()->assertJsonPath('data.my_attempt.waiting_for_marking', true)->assertJsonPath('data.my_attempt.total', null);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/submit", ['answers' => []], $this->headers())->assertStatus(409);

        // Marked by hand: then Amal sees her total — Badr never does.
        $this->as($this->teacher);
        $attempt = $this->getJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/attempts", $this->headers())->json('data.0');
        $this->assertEquals(2.0, $attempt['total']);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/attempts/{$attempt['id']}/mark", ['marks' => [$ids[1] => 2]], $this->headers())
            ->assertOk()->assertJsonPath('data.needs_marking', false);
        $this->as($this->amal);
        $this->assertEquals(4.0, $this->getJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}", $this->headers())->json('data.my_attempt.total'));
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}", $this->headers())->assertJsonPath('data.my_attempt', null)->assertJsonPath('data.questions', null);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/attempts", $this->headers())->assertForbidden();
    }

    public function test_attendance_is_taken_by_the_teacher_and_each_student_sees_their_own(): void
    {
        $class = $this->classGroup();
        [$amal, $badr] = [$this->participant($class, 'Amal'), $this->participant($class, 'Badr')];

        $this->as($this->teacher);
        $session = $this->postJson("/api/mobile/v1/chat/groups/{$class}/sessions", ['title' => 'Lesson 1', 'starts_at' => now()->addMinutes(20)->toIso8601String()], $this->headers())->assertCreated()->json('data.id');
        $this->artisan('chat:remind-group-sessions')->assertSuccessful();
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/sessions/{$session}/attendance", ['entries' => [
            ['participant_id' => $amal, 'status' => 'present'], ['participant_id' => $badr, 'status' => 'absent'],
        ]], $this->headers())->assertOk()->assertJsonCount(2, 'data.register');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/sessions/{$session}/attendance", ['entries' => [['participant_id' => $badr, 'status' => 'excused', 'note' => 'Ill']]], $this->headers())->assertOk();
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/audit-log", $this->headers())->assertJsonPath('data.items.0.action', 'attendance_set')->assertJsonPath('data.items.0.before.status', 'absent');

        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/attendance", $this->headers())->assertOk()->assertJsonPath('data.counts.excused', 1)->assertJsonPath('data.sessions.0.status', 'excused');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/attendance?participant_id={$amal}", $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/sessions/{$session}", $this->headers())->assertJsonPath('data.my_status', 'excused')->assertJsonMissingPath('data.register');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/sessions/{$session}/attendance", ['entries' => [['participant_id' => $badr, 'status' => 'present']]], $this->headers())->assertForbidden();
    }

    private function classGroup(): string
    {
        $this->as($this->teacher);

        return $this->postJson('/api/mobile/v1/chat/groups', [
            'name' => 'Maths 3', 'kind' => 'education', 'members' => [$this->amal->id, $this->badr->id],
        ], $this->headers())->assertCreated()->json('data.conversation.id');
    }

    private function participant(string $group, string $name): int
    {
        $this->as($this->teacher);

        return collect($this->getJson("/api/mobile/v1/chat/groups/{$group}/members", $this->headers())->json('data'))
            ->firstWhere('profile.account_name', $name)['participant_id'];
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user, [], 'user_api');
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
