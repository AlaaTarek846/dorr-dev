<?php

namespace Modules\AI\Tests\Unit\Retrieval;

use Modules\AI\Enums\AiRetrievalMode;
use Modules\AI\Services\Retrieval\AiRetrievalQueryAnalyzer;
use Tests\TestCase;

class AiRetrievalQueryAnalyzerTest extends TestCase
{
    public function test_no_retrieval_when_no_files_in_scope_even_with_file_keywords(): void
    {
        $analyzer = new AiRetrievalQueryAnalyzer;

        $decision = $analyzer->analyze('Summarize this PDF for me', hasFilesInScope: false);

        $this->assertFalse($decision['required']);
        $this->assertSame(AiRetrievalMode::None, $decision['mode']);
    }

    public function test_greeting_never_triggers_retrieval_even_with_files_in_scope(): void
    {
        $analyzer = new AiRetrievalQueryAnalyzer;

        $decision = $analyzer->analyze('Hello, how are you?', hasFilesInScope: true);

        $this->assertFalse($decision['required']);
    }

    public function test_arabic_greeting_never_triggers_retrieval(): void
    {
        $analyzer = new AiRetrievalQueryAnalyzer;

        $decision = $analyzer->analyze('السلام عليكم', hasFilesInScope: true);

        $this->assertFalse($decision['required']);
    }

    public function test_fresh_attachment_always_triggers_retrieval(): void
    {
        $analyzer = new AiRetrievalQueryAnalyzer;

        $decision = $analyzer->analyze('ok', hasFilesInScope: true, hasFreshAttachment: true);

        $this->assertTrue($decision['required']);
        $this->assertSame(AiRetrievalMode::Hybrid, $decision['mode']);
    }

    public function test_file_reference_keyword_triggers_retrieval(): void
    {
        $analyzer = new AiRetrievalQueryAnalyzer;

        $decision = $analyzer->analyze('What does page 12 say in the document?', hasFilesInScope: true);

        $this->assertTrue($decision['required']);
    }

    public function test_arabic_file_reference_keyword_triggers_retrieval(): void
    {
        $analyzer = new AiRetrievalQueryAnalyzer;

        $decision = $analyzer->analyze('لخصلي الملف ده', hasFilesInScope: true);

        $this->assertTrue($decision['required']);
    }

    public function test_generic_knowledge_question_does_not_trigger_retrieval(): void
    {
        $analyzer = new AiRetrievalQueryAnalyzer;

        $decision = $analyzer->analyze('What is Laravel?', hasFilesInScope: true);

        $this->assertFalse($decision['required']);
    }

    public function test_retrieval_disabled_via_config_always_skips(): void
    {
        config(['ai.retrieval.enabled' => false]);
        $analyzer = new AiRetrievalQueryAnalyzer;

        $decision = $analyzer->analyze('Summarize this PDF', hasFilesInScope: true, hasFreshAttachment: true);

        $this->assertFalse($decision['required']);
    }
}
