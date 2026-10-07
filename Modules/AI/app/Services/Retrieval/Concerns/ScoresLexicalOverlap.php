<?php

namespace Modules\AI\Services\Retrieval\Concerns;

/**
 * Phase 9: the same tokenize()/lexicalScore() algorithm
 * AiKnowledgeRetriever already uses for the admin Knowledge Base
 * (Jaccard-style overlap weighted 0.7 toward query coverage, 0.3 toward
 * mutual overlap) - extracted into a trait rather than copy-pasted, so
 * both retrieval paths share one implementation. AiKnowledgeRetriever
 * itself is NOT refactored to use this trait in this phase (it is
 * working, already-shipped, tested code for an unrelated feature, and
 * touching it carries more regression risk than the small duplication
 * this trait removes going forward) - noted as a documented follow-up
 * opportunity, not done silently.
 */
trait ScoresLexicalOverlap
{
    /**
     * Function words that say nothing about a document's topic. Without
     * this, a single shared "of"/"in"/"في" is enough to push an unrelated
     * chunk over the relevance threshold ("capital of France" matching
     * "days of annual leave").
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'the', 'and', 'for', 'are', 'was', 'were', 'is', 'am', 'be', 'been', 'of', 'in', 'on', 'at', 'to', 'by', 'an', 'as', 'or',
        'do', 'does', 'did', 'it', 'its', 'this', 'that', 'these', 'those', 'with', 'from', 'what', 'which', 'who', 'whom', 'how',
        'when', 'where', 'why', 'can', 'could', 'would', 'should', 'will', 'has', 'have', 'had', 'any', 'all', 'get', 'per', 'me',
        'my', 'you', 'your', 'we', 'our', 'they', 'their', 'not', 'no', 'if', 'so', 'than', 'then', 'about',
        'في', 'من', 'الى', 'إلى', 'على', 'عن', 'مع', 'هذا', 'هذه', 'ذلك', 'تلك', 'ما', 'ماذا', 'هل', 'كم', 'كيف', 'متى', 'اين',
        'أين', 'لماذا', 'هو', 'هي', 'انا', 'أنا', 'انت', 'أنت', 'ان', 'أن', 'إن', 'او', 'أو', 'ثم', 'كان', 'كانت', 'يكون', 'التي',
        'الذي', 'الذين', 'عايز', 'عاوز', 'ايه', 'إيه', 'ده', 'دي', 'دا', 'فى', 'علي', 'عليه', 'بتاع', 'بتاعت',
    ];

    /**
     * @return list<string>
     */
    protected function tokenize(string $text): array
    {
        $normalized = mb_strtolower($text);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;

        $tokens = preg_split('/\s+/u', trim($normalized)) ?: [];
        $tokens = array_values(array_filter($tokens, fn ($t) => mb_strlen($t) >= 2));

        $meaningful = array_values(array_filter($tokens, fn ($t) => ! in_array($t, self::STOPWORDS, true)));

        // A query made only of function words ("what is it") must still be searchable.
        return $meaningful !== [] ? $meaningful : $tokens;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    protected function lexicalScore(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        $setA = array_unique($a);
        $setB = array_unique($b);
        $intersection = array_intersect($setA, $setB);

        if ($intersection === []) {
            return 0.0;
        }

        $coverage = count($intersection) / count($setA);
        $jaccard = count($intersection) / count(array_unique(array_merge($setA, $setB)));

        return ($coverage * 0.7) + ($jaccard * 0.3);
    }
}
