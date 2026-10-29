<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatGroupParentLink;
use Modules\Chat\Models\ChatSetting;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Parents in educational groups (docs/groups_tasks.md GR-018, 019, 020, 027 — decision D8): an
 * ordinary account, never a member of the class chat, linked to one student by the student's code
 * and the teacher's approval; sees that child only; may message the teachers when they allow it;
 * books a time in a parents' meeting.
 */
class ChatGroupParentsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private User $amal;

    private User $badr;

    private User $dad;

    private User $stranger;

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
        $this->dad = $make('Dad', '+966500000004');
        $this->stranger = $make('Stranger', '+966500000005');
    }

    public function test_the_student_s_code_the_parent_enters_and_the_teacher_approves(): void
    {
        $class = $this->classGroup();

        // Only a student makes a code; the teacher can't.
        $this->as($this->teacher);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/parents/code", [], $this->headers())->assertForbidden();
        $this->as($this->amal);
        $code = $this->postJson("/api/mobile/v1/chat/groups/{$class}/parents/code", [], $this->headers())->assertCreated()->json('data.code');

        // Amal can't be her own parent; a wrong code is refused.
        $this->postJson('/api/mobile/v1/chat/parent/link', ['code' => $code], $this->headers())->assertStatus(422);
        $this->as($this->dad);
        $this->postJson('/api/mobile/v1/chat/parent/link', ['code' => 'NOPE1234'], $this->headers())->assertNotFound();
        $link = $this->postJson('/api/mobile/v1/chat/parent/link', ['code' => strtolower($code)], $this->headers())->assertCreated()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.student.account_name', 'Amal')->json('data.id');
        // Used once.
        $this->as($this->stranger);
        $this->postJson('/api/mobile/v1/chat/parent/link', ['code' => $code], $this->headers())->assertNotFound();

        // Waiting: nothing to see yet.
        $this->as($this->dad);
        $this->getJson("/api/mobile/v1/chat/parent/children/{$link}", $this->headers())->assertNotFound();

        // The teacher's queue; a student can't approve.
        $this->as($this->badr);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/parents/{$link}/decide", ['approve' => true], $this->headers())->assertForbidden();
        $this->as($this->teacher);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/parents?status=pending", $this->headers())->assertOk()
            ->assertJsonPath('data.links.0.parent.account_name', 'Dad')->assertJsonPath('data.links.0.student.profile.account_name', 'Amal');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/parents/{$link}/decide", ['approve' => true], $this->headers())->assertOk()->assertJsonPath('data.status', 'approved');

        // Amal knows who is linked to her.
        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/parents/mine", $this->headers())->assertJsonPath('data.0.parent.account_name', 'Dad');

        // Dad is no member of the class: not its chat, not its members, not its lessons.
        $this->as($this->dad);
        $this->getJson('/api/mobile/v1/chat/parent/children', $this->headers())->assertOk()->assertJsonPath('data.0.status', 'approved');
        $this->getJson("/api/mobile/v1/chat/conversations/{$class}/messages", $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/members", $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/lessons", $this->headers())->assertForbidden();
    }

    public function test_the_parent_sees_that_child_only_and_messages_the_teacher_when_allowed(): void
    {
        $class = $this->classGroup();
        $amal = $this->participant($class, 'Amal');
        $badr = $this->participant($class, 'Badr');

        // Homework graded for both, attendance for both, a coming class, an announcement.
        $this->as($this->teacher);
        $hw = $this->postJson("/api/mobile/v1/chat/groups/{$class}/assignments", ['title' => 'Essay', 'max_score' => 10], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/assignments", ['title' => 'Due soon', 'due_at' => now()->addDay()->toIso8601String()], $this->headers())->assertCreated();
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/grades", ['participant_id' => $amal, 'score' => 9], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/assignments/{$hw}/grades", ['participant_id' => $badr, 'score' => 3], $this->headers())->assertOk();
        $past = $this->postJson("/api/mobile/v1/chat/groups/{$class}/sessions", ['title' => 'Lesson 1', 'starts_at' => now()->subHours(4)->toIso8601String()], $this->headers())->json('data.id');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/sessions/{$past}/attendance", ['entries' => [
            ['participant_id' => $amal, 'status' => 'late'], ['participant_id' => $badr, 'status' => 'absent'],
        ]], $this->headers())->assertOk();
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/sessions", ['title' => 'Lesson 2', 'starts_at' => now()->addDay()->toIso8601String()], $this->headers())->assertCreated();
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/announcements", ['body' => 'Trip on Sunday'], $this->headers())->assertCreated();

        $link = $this->linkParent($class, $this->amal, $this->dad);

        $this->as($this->dad);
        $page = $this->getJson("/api/mobile/v1/chat/parent/children/{$link}", $this->headers())->assertOk()
            ->assertJsonPath('data.grades.0.score', 9)->assertJsonCount(1, 'data.grades')
            ->assertJsonPath('data.homework.0.title', 'Due soon')
            ->assertJsonPath('data.attendance.counts.late', 1)->assertJsonPath('data.attendance.counts.absent', 0)
            ->assertJsonPath('data.sessions.0.title', 'Lesson 2')
            ->assertJsonPath('data.announcements.0.body', 'Trip on Sunday')->json('data');
        // GR-020: nothing of Badr anywhere on Amal's page.
        $this->assertStringNotContainsString('Badr', json_encode($page));

        // Messaging the teacher: off until the teacher turns it on.
        $teacherRow = $this->participant($class, 'Teacher');
        $this->as($this->dad);
        $this->postJson("/api/mobile/v1/chat/parent/children/{$link}/message", ['teacher_participant_id' => $teacherRow], $this->headers())->assertForbidden();
        $this->as($this->teacher);
        $this->patchJson("/api/mobile/v1/chat/groups/{$class}/parents/settings", ['messaging' => true], $this->headers())->assertOk();
        $this->as($this->dad);
        $this->getJson('/api/mobile/v1/chat/parent/children', $this->headers())->assertJsonPath('data.0.teachers.0.participant_id', $teacherRow);
        // Only a teacher: not a classmate.
        $this->postJson("/api/mobile/v1/chat/parent/children/{$link}/message", ['teacher_participant_id' => $badr], $this->headers())->assertNotFound();
        $this->postJson("/api/mobile/v1/chat/parent/children/{$link}/message", ['teacher_participant_id' => $teacherRow], $this->headers())->assertOk()
            ->assertJsonStructure(['data' => ['conversation_id']]);
    }

    public function test_student_data_stays_private_and_the_link_ends_with_the_membership(): void
    {
        $class = $this->classGroup();
        $amal = $this->participant($class, 'Amal');
        $link = $this->linkParent($class, $this->amal, $this->dad);

        // GR-020: someone else's link, a stranger, a classmate asking for Amal's records.
        $this->as($this->stranger);
        $this->getJson("/api/mobile/v1/chat/parent/children/{$link}", $this->headers())->assertNotFound();
        $this->getJson('/api/mobile/v1/chat/parent/children', $this->headers())->assertJsonCount(0, 'data');
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/attendance?participant_id={$amal}", $this->headers())->assertForbidden();
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/parents", $this->headers())->assertForbidden();

        // The tool switched off by the admin: the parent's page closes, the link stays.
        ChatSetting::current()->update(['group_tools_disabled' => ['parents']]);
        $this->as($this->dad);
        $this->getJson("/api/mobile/v1/chat/parent/children/{$link}", $this->headers())->assertForbidden();
        ChatSetting::current()->update(['group_tools_disabled' => []]);
        $this->getJson("/api/mobile/v1/chat/parent/children/{$link}", $this->headers())->assertOk();

        // Amal leaves the class: the link is over at once.
        $this->as($this->teacher);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$class}/members/{$amal}", [], $this->headers())->assertOk();
        $this->as($this->dad);
        $this->getJson("/api/mobile/v1/chat/parent/children/{$link}", $this->headers())->assertNotFound();
        $this->getJson('/api/mobile/v1/chat/parent/children', $this->headers())->assertJsonCount(0, 'data');

        // A teacher removes a link; a parent removes their own.
        $badrDad = $this->linkParent($class, $this->badr, $this->stranger);
        $this->as($this->teacher);
        $this->deleteJson("/api/mobile/v1/chat/groups/{$class}/parents/{$badrDad}", [], $this->headers())->assertOk();
        $this->assertSame(0, ChatGroupParentLink::query()->where('uuid', $badrDad)->count());
    }

    public function test_a_parents_meeting_with_slots_each_parent_books_one(): void
    {
        $class = $this->classGroup();
        $amalLink = $this->linkParent($class, $this->amal, $this->dad);
        $badrLink = $this->linkParent($class, $this->badr, $this->stranger);
        $start = now()->addMinutes(20)->startOfMinute();

        $this->as($this->badr);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/parent-meetings", ['title' => 'Term review', 'starts_at' => $start->toIso8601String(), 'mode' => 'group'], $this->headers())->assertForbidden();
        $this->as($this->teacher);
        $meeting = $this->postJson("/api/mobile/v1/chat/groups/{$class}/parent-meetings", [
            'title' => 'Term review', 'starts_at' => $start->toIso8601String(), 'mode' => 'slots', 'slot_minutes' => 15, 'slots' => 3,
        ], $this->headers())->assertCreated()->assertJsonCount(3, 'data.slots')->json('data');

        $first = $meeting['slots'][0]['at'];
        $this->as($this->dad);
        $this->getJson("/api/mobile/v1/chat/parent/children/{$amalLink}/meetings", $this->headers())->assertOk()
            ->assertJsonPath('data.0.title', 'Term review')->assertJsonPath('data.0.call', null);
        $this->postJson("/api/mobile/v1/chat/parent/children/{$amalLink}/meetings/{$meeting['id']}/book", ['slot_at' => now()->addYear()->toIso8601String()], $this->headers())->assertStatus(422);
        $this->postJson("/api/mobile/v1/chat/parent/children/{$amalLink}/meetings/{$meeting['id']}/book", ['slot_at' => $first], $this->headers())->assertOk()
            ->assertJsonPath('data.my_slot', $first)->assertJsonPath('data.slots.0.mine', true);

        // Taken: the other parent picks another time.
        $this->as($this->stranger);
        $this->postJson("/api/mobile/v1/chat/parent/children/{$badrLink}/meetings/{$meeting['id']}/book", ['slot_at' => $first], $this->headers())->assertStatus(409);
        $this->postJson("/api/mobile/v1/chat/parent/children/{$badrLink}/meetings/{$meeting['id']}/book", ['slot_at' => $meeting['slots'][1]['at']], $this->headers())->assertOk()
            ->assertJsonPath('data.slots.0.free', false)->assertJsonPath('data.slots.0.mine', false)->assertJsonMissingPath('data.slots.0.parent');

        // The teacher sees who booked what.
        $this->as($this->teacher);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/parent-meetings", $this->headers())->assertJsonPath('data.0.slots.0.parent.account_name', 'Dad');

        // Dad's slot is within 30 minutes: he is reminded once.
        $this->artisan('chat:remind-group-sessions')->assertSuccessful();
        $this->assertSame(1, \Modules\Chat\Models\ChatGroupParentBooking::query()->whereNotNull('reminded_at')->count());

        $this->deleteJson("/api/mobile/v1/chat/groups/{$class}/parent-meetings/{$meeting['id']}", [], $this->headers())->assertOk();
        $this->as($this->dad);
        $this->getJson("/api/mobile/v1/chat/parent/children/{$amalLink}/meetings", $this->headers())->assertJsonCount(0, 'data');
    }

    /** The student makes a code, the parent enters it, the teacher approves. */
    private function linkParent(string $class, User $student, User $parent): string
    {
        $this->as($student);
        $code = $this->postJson("/api/mobile/v1/chat/groups/{$class}/parents/code", [], $this->headers())->json('data.code');
        $this->as($parent);
        $link = $this->postJson('/api/mobile/v1/chat/parent/link', ['code' => $code], $this->headers())->assertCreated()->json('data.id');
        $this->as($this->teacher);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/parents/{$link}/decide", ['approve' => true], $this->headers())->assertOk();

        return $link;
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

    /** @return array<string, string> */
    private function headers(): array
    {
        return ['X-Country' => 'SA'];
    }
}
