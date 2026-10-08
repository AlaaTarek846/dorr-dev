<?php

namespace Modules\AI\Enums;

/**
 * Phase 9 (doc S5): the internal retrieval decision - distinguishes no
 * retrieval, keyword/full-text, semantic/vector, hybrid, and exact
 * lookup. No equivalent abstraction existed before this phase (the
 * admin Knowledge Base's own AiKnowledgeRetriever always runs a fixed
 * lexical+optional-vector blend on every message - it has no "skip
 * retrieval" decision at all).
 */
enum AiRetrievalMode: string
{
    case None = 'none';
    case Keyword = 'keyword';
    case Semantic = 'semantic';
    case Hybrid = 'hybrid';
    case Exact = 'exact';
}
