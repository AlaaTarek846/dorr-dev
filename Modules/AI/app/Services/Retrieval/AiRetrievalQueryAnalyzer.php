<?php

namespace Modules\AI\Services\Retrieval;

use Modules\AI\Enums\AiRetrievalMode;

/**
 * Phase 9 (doc S4/S5): decides whether a chat turn needs file
 * retrieval at all, and if so which mode - the one decision point that
 * did NOT exist anywhere in this codebase before this phase (the admin
 * Knowledge Base's AiKnowledgeRetriever has no such gate: it always
 * runs its hybrid scoring pass on every single message, unconditionally
 * - confirmed by inspecting AiChatService::sendMessage(), where
 * `$this->knowledgeRetriever->retrieve($owner, $outgoingContent)` is
 * called with no guard at all).
 *
 * Deliberately the SAME transparent, extensible keyword-map trade-off
 * already used by AiRequiredCapabilityResolver/AiIntentClassifier/
 * AiSafetyGuard elsewhere in this module, not a full ML classifier or
 * an extra LLM call just to decide whether to make another LLM call.
 * Disclosed plainly as a heuristic, not a guarantee - a message that
 * matches none of these phrases but genuinely needs file content will
 * be missed, same honesty standard as every other keyword-map in this
 * codebase.
 */
class AiRetrievalQueryAnalyzer
{
    /**
     * Phrases that explicitly point at file/document content - doc S4's
     * own worked examples ("Summarize this PDF", "What does page 12
     * say?", "What is the total revenue in the Excel file?", "Explain
     * this contract") plus their natural Arabic equivalents.
     *
     * @var list<string>
     */
    protected array $fileReferenceKeywords = [
        'لخص', 'لخصلي', 'تلخيص', 'الملف', 'المرفق', 'المستند', 'الوثيقة', 'العقد', 'الصفحة', 'صفحة',
        'الشيت', 'الجدول', 'الاكسل', 'العرض التقديمي', 'البوربوينت', 'السطر', 'حسب الملف', 'في الملف',
        'في المستند', 'طبقا للملف', 'وفقا للمستند', 'اشرح هذا', 'اشرحلي الملف',
        'summarize', 'summary', 'this pdf', 'this document', 'this file', 'the attached', 'attachment',
        'the excel file', 'the spreadsheet', 'the presentation', 'the slide', 'according to the file',
        'according to the document', 'what does page', 'what does the file say', 'in the document',
        'in the file', 'this contract', 'this report', 'this spreadsheet', 'row ', 'sheet named',
        // Several files at once (compare / cross-reference).
        'these files', 'these documents', 'both files', 'both documents', 'the files', 'the documents', 'my files', 'my documents',
        'compare', 'قارن', 'مقارنة', 'الملفين', 'الملفات', 'المستندات', 'المرفقات',
    ];

    /**
     * A pure greeting/small-talk opener - never triggers retrieval even
     * when files happen to be in scope (doc S4: "Hello, how are you?"
     * -> retrieval not required).
     *
     * @var list<string>
     */
    protected array $greetingOnlyPatterns = [
        '/^\s*(hi|hello|hey|hiya|good\s*(morning|evening|afternoon))\s*[!.؟?]*\s*$/iu',
        '/^\s*(مرحبا|اهلا|أهلا|السلام عليكم|صباح الخير|مساء الخير)\s*[!.؟?]*\s*$/iu',
        '/^\s*(how are you|how r u|what\'s up|whats up)\s*[!.؟?]*\s*$/iu',
        '/^\s*(ازيك|عامل ايه|كيفك|شلونك)\s*[!.؟?]*\s*$/iu',
    ];

    /**
     * @param  bool  $hasFilesInScope  Whether the current conversation has at least one owner's own, ready, indexed file to search at all - resolved structurally (doc S15), never guessed from the text.
     * @param  bool  $hasFreshAttachment  Whether the user just attached a file THIS turn - a strong, deliberate signal that retrieval is wanted, regardless of wording.
     * @return array{required: bool, mode: AiRetrievalMode}
     */
    public function analyze(string $content, bool $hasFilesInScope, bool $hasFreshAttachment = false): array
    {
        $none = ['required' => false, 'mode' => AiRetrievalMode::None];

        if (! config('ai.retrieval.enabled', true) || ! $hasFilesInScope) {
            return $none;
        }

        if ($this->isGreetingOnly($content)) {
            return $none;
        }

        if ($hasFreshAttachment) {
            return ['required' => true, 'mode' => AiRetrievalMode::Hybrid];
        }

        if ($this->matchesFileReference($content)) {
            return ['required' => true, 'mode' => AiRetrievalMode::Hybrid];
        }

        // Doc S4: "What is Laravel?" -> retrieval normally not required
        // unless file context is explicitly requested. No keyword
        // matched and there was no fresh attachment this turn, so the
        // conservative default is NOT to retrieve - a generic-knowledge
        // question about an unrelated topic must not be padded with
        // irrelevant file excerpts just because the conversation
        // happens to have files attached somewhere earlier.
        return $none;
    }

    protected function isGreetingOnly(string $content): bool
    {
        foreach ($this->greetingOnlyPatterns as $pattern) {
            if (preg_match($pattern, trim($content)) === 1) {
                return true;
            }
        }

        return false;
    }

    protected function matchesFileReference(string $content): bool
    {
        $haystack = mb_strtolower($content);

        foreach ($this->fileReferenceKeywords as $needle) {
            if (mb_stripos($haystack, mb_strtolower($needle)) !== false) {
                return true;
            }
        }

        return false;
    }
}
