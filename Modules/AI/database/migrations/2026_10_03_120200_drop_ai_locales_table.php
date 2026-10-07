<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (languages consolidation, step 3/3): "ai_locales" was
 * built (Phase 10 CRUD: code/name/settings/is_active under a language)
 * but never actually consulted anywhere in the chat pipeline -
 * AiChatLanguageResolver (the only place a reply's language/dialect is
 * decided) only ever reads ai_language_variants, never ai_locales. It was
 * pure, unused duplication of the same "a language has named
 * sub-variants" idea ai_language_variants already covers and is already
 * wired into the resolver - dropped outright rather than consolidated,
 * since there is no live data path that depends on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_locales');
    }

    public function down(): void
    {
        // Intentionally irreversible - this table was dead weight with no
        // code path reading from it, so there is nothing meaningful to
        // restore.
    }
};
