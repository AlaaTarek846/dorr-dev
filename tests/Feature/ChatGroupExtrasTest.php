<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Currency;
use App\Models\Flag;
use App\Models\Language;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Chat\Models\ChatGroupQuiz;
use Modules\User\Models\User;
use Tests\TestCase;

/**
 * Educational extras (GR-021, 022, 024, 025, 026, 028, 030) and help requests (GR-031, GR-041).
 */
class ChatGroupExtrasTest extends TestCase
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

    public function test_the_bag_the_tests_calendar_a_competition_and_a_reminder(): void
    {
        $class = $this->group('education');

        $this->as($this->teacher);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/assignments", ['title' => 'Essay', 'due_at' => now()->addDay()->toIso8601String()], $this->headers())->assertCreated();
        $quiz = $this->postJson("/api/mobile/v1/chat/groups/{$class}/quizzes", [
            'title' => 'Speed round', 'is_competition' => true, 'opens_at' => now()->addMinutes(30)->toIso8601String(),
            'questions' => [['type' => 'true_false', 'text' => '2 is even', 'correct' => true, 'points' => 1]],
        ], $this->headers())->assertCreated()->json('data.id');
        $this->patchJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}", ['is_published' => true], $this->headers())->assertOk();

        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/bag", $this->headers())->assertOk()
            ->assertJsonPath('data.to_hand_in.0.title', 'Essay')->assertJsonPath('data.tests.0.title', 'Speed round');
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/quizzes/schedule", $this->headers())->assertJsonPath('data.tests.0.is_competition', true);

        $this->artisan('chat:remind-group-sessions')->assertSuccessful();
        $this->assertNotNull(ChatGroupQuiz::query()->value('reminded_at'));

        // Both take it once it opens; the leaderboard shows both, best first.
        $this->travel(31)->minutes();
        foreach ([[$this->amal, true], [$this->badr, false]] as [$student, $answer]) {
            $this->as($student);
            $qid = $this->postJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/start", [], $this->headers())->assertOk()->json('data.questions.0.id');
            $this->postJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/submit", ['answers' => [$qid => $answer]], $this->headers())->assertOk();
        }
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/quizzes/{$quiz}/leaderboard", $this->headers())->assertOk()
            ->assertJsonPath('data.0.student.profile.account_name', 'Amal')->assertJsonPath('data.1.rank', 2);
    }

    public function test_stars_reading_and_certificates(): void
    {
        $class = $this->group('education');
        $amal = $this->participant($class, 'Amal');

        $this->as($this->teacher);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/stars", ['participant_id' => $amal, 'reason' => 'Helped everyone'], $this->headers())->assertCreated();
        $item = $this->postJson("/api/mobile/v1/chat/groups/{$class}/reading", ['title' => 'The little prince', 'pages' => 90], $this->headers())->assertCreated()->json('data.id');
        $cert = $this->postJson("/api/mobile/v1/chat/groups/{$class}/certificates", ['participant_id' => $amal, 'title' => 'Top reader'], $this->headers())->assertCreated()->json('data');
        $this->assertStringContainsString('/chat/media/', $cert['pdf']);

        $this->as($this->amal);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/stars", $this->headers())->assertJsonPath('data.0.reason', 'Helped everyone');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/reading/{$item}/progress", ['status' => 'done', 'pages_read' => 90], $this->headers())->assertOk();
        $mine = $this->getJson("/api/mobile/v1/chat/groups/{$class}/certificates", $this->headers())->assertJsonCount(1, 'data')->json('data.0.pdf');
        $this->get($mine)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/stars", ['participant_id' => $amal], $this->headers())->assertForbidden();

        // Badr: no certificate of his, can't open Amal's.
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/certificates", $this->headers())->assertJsonCount(0, 'data');

        $this->as($this->teacher);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/reading", $this->headers())->assertJsonPath('data.0.class.done', 1);
    }

    public function test_a_survey_hides_its_results_from_the_students(): void
    {
        $class = $this->group('education');

        $this->as($this->teacher);
        $message = $this->postJson("/api/mobile/v1/chat/groups/{$class}/surveys", ['question' => 'Was the lesson clear?', 'options' => ['Yes', 'No']], $this->headers())->assertCreated()->json('data.message_id');

        $this->as($this->amal);
        $poll = collect($this->getJson("/api/mobile/v1/chat/conversations/{$class}/messages", $this->headers())->json('data.messages'))->firstWhere('id', $message);
        $this->assertTrue($poll['poll']['results_hidden']);
        $this->assertNull($poll['poll']['options'][0]['votes']);
        $this->getJson("/api/mobile/v1/chat/messages/{$message}/votes", $this->headers())->assertForbidden();

        $this->as($this->teacher);
        $poll = collect($this->getJson("/api/mobile/v1/chat/conversations/{$class}/messages", $this->headers())->json('data.messages'))->firstWhere('id', $message);
        $this->assertFalse($poll['poll']['results_hidden']);
    }

    public function test_help_requests_reach_the_teachers_or_the_team(): void
    {
        $class = $this->group('education');

        // A class: the student asks, the teacher answers, a classmate doesn't even see it.
        $this->as($this->amal);
        $help = $this->postJson("/api/mobile/v1/chat/groups/{$class}/help", ['question' => 'I do not get fractions'], $this->headers())->assertCreated()->json('data.id');
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$class}/help", $this->headers())->assertJsonCount(0, 'data.requests');
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/help/{$help}/answer", ['answer' => 'x'], $this->headers())->assertNotFound();
        $this->as($this->teacher);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/help/{$help}/answer", ['answer' => 'See lesson 2'], $this->headers())->assertOk()->assertJsonPath('data.status', 'answered');
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$class}/help/{$help}/close", [], $this->headers())->assertOk()->assertJsonPath('data.status', 'closed');

        // A work group: the whole team sees it.
        $team = $this->group('work');
        $this->as($this->amal);
        $this->postJson("/api/mobile/v1/chat/groups/{$team}/help", ['question' => 'Who has the van keys?'], $this->headers())->assertCreated();
        $this->as($this->badr);
        $this->getJson("/api/mobile/v1/chat/groups/{$team}/help", $this->headers())->assertJsonCount(1, 'data.requests')->assertJsonPath('data.requests.0.can.answer', true);
    }

    private function group(string $kind): string
    {
        $this->as($this->teacher);

        return $this->postJson('/api/mobile/v1/chat/groups', [
            'name' => 'Class', 'kind' => $kind, 'members' => [$this->amal->id, $this->badr->id],
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
