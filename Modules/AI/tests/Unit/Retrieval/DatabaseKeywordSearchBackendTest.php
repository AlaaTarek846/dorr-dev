<?php

namespace Modules\AI\Tests\Unit\Retrieval;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\AI\Enums\AiRetrievalMode;
use Modules\AI\Models\AiFileChunk;
use Modules\AI\Services\Retrieval\AiRetrievalQuery;
use Modules\AI\Services\Retrieval\Backends\DatabaseKeywordSearchBackend;
use Modules\User\Models\User;
use App\Enums\UserStatus;
use Tests\TestCase;

class DatabaseKeywordSearchBackendTest extends TestCase
{
    use RefreshDatabase;

    protected function owner(): User
    {
        return User::query()->create([
            'name' => 'Keyword Backend Test Owner',
            'email' => 'keyword-backend-'.uniqid().'@example.test',
            'password' => bcrypt('test-password-not-real'),
            'status' => UserStatus::Active,
        ]);
    }

    protected function query(string $text): AiRetrievalQuery
    {
        return new AiRetrievalQuery(owner: $this->owner(), queryText: $text, mode: AiRetrievalMode::Keyword);
    }

    public function test_is_always_available(): void
    {
        $backend = new DatabaseKeywordSearchBackend;

        $this->assertTrue($backend->isAvailable($this->query('anything')));
    }

    public function test_exact_phrase_match_scores_higher_than_scattered_overlap(): void
    {
        $backend = new DatabaseKeywordSearchBackend;
        $chunk = new AiFileChunk;

        $exactPhraseScore = $backend->score($this->query('annual leave policy'), $chunk, 'Our annual leave policy grants 21 days per year.');
        $scatteredScore = $backend->score($this->query('annual leave policy'), $chunk, 'The annual report mentions leave separately from the policy section elsewhere.');

        $this->assertGreaterThan($scatteredScore, $exactPhraseScore);
    }

    public function test_no_overlap_scores_zero(): void
    {
        $backend = new DatabaseKeywordSearchBackend;
        $chunk = new AiFileChunk;

        $score = $backend->score($this->query('quantum physics'), $chunk, 'This is about cooking recipes and baking bread.');

        $this->assertSame(0.0, $score);
    }

    public function test_partial_word_overlap_scores_between_zero_and_one(): void
    {
        $backend = new DatabaseKeywordSearchBackend;
        $chunk = new AiFileChunk;

        $score = $backend->score($this->query('total revenue 2026'), $chunk, 'The total revenue reported was impressive this quarter.');

        $this->assertGreaterThan(0.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_arabic_text_overlap_scores_correctly(): void
    {
        $backend = new DatabaseKeywordSearchBackend;
        $chunk = new AiFileChunk;

        $score = $backend->score($this->query('سياسة الإجازات'), $chunk, 'سياسة الإجازات في الشركة تمنح 21 يوم إجازة سنوية.');

        $this->assertGreaterThan(0.0, $score);
    }
}
