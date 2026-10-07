<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Root-cause fix (languages consolidation, step 2/3): moves every AI
 * language concern off the redundant "ai_languages" table and onto the
 * platform's single general "languages" table (see the previous
 * migration for "ai_enabled", which this one now actually uses). After
 * this runs, "ai_language_variants", "ai_user_language_preferences" and
 * "ai_language_evaluations" all point their "language_id" straight at
 * "languages" - there is exactly one table that answers "which languages
 * exist in this system."
 *
 * Data-preserving: every ai_languages row is matched to a languages row
 * by "code" (this platform has only ever seeded "ar"/"en" in both, so a
 * match is expected every time; a row is created in languages only if one
 * is genuinely missing), its is_active flag becomes that languages row's
 * ai_enabled, and every foreign key in the three dependent tables is
 * remapped from the old ai_languages id to the matching languages id
 * before ai_languages is dropped - nothing is silently lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_languages')) {
            // Already consolidated, or a fresh install that never had
            // the old table - nothing to carry over.
            return;
        }

        $map = $this->migrateLanguageRowsAndBuildIdMap();

        $this->remapForeignKey('ai_language_variants', $map, cascade: true);
        $this->remapForeignKey('ai_user_language_preferences', $map, cascade: false);
        $this->remapForeignKey('ai_language_evaluations', $map, cascade: true);

        // ai_locales still has its own leftover foreign key pointing at
        // ai_languages (it was never consolidated - it's the dead,
        // never-wired-up table the next migration drops) - that FK blocks
        // dropping ai_languages below with a 3730 error, so it has to go
        // first. Fine to drop here instead of waiting for the next
        // migration: ai_locales carries no live data path to preserve.
        Schema::dropIfExists('ai_locales');

        Schema::dropIfExists('ai_languages');
    }

    /**
     * @return array<int, int> old ai_languages.id => languages.id
     */
    protected function migrateLanguageRowsAndBuildIdMap(): array
    {
        $map = [];

        foreach (DB::table('ai_languages')->get() as $aiLanguage) {
            $generalId = DB::table('languages')->where('code', $aiLanguage->code)->value('id');

            if ($generalId === null) {
                $generalId = $this->createMissingGeneralLanguage($aiLanguage);

                if ($generalId === null) {
                    // No flags row to satisfy languages.flag_id's NOT NULL
                    // constraint - skip rather than fail the whole
                    // migration; an admin can add this language manually
                    // from the general Languages screen afterwards.
                    continue;
                }
            } else {
                DB::table('languages')->where('id', $generalId)->update([
                    'ai_enabled' => (bool) $aiLanguage->is_active,
                ]);
            }

            $map[$aiLanguage->id] = $generalId;
        }

        return $map;
    }

    protected function createMissingGeneralLanguage(object $aiLanguage): ?int
    {
        $flagId = DB::table('flags')->orderBy('id')->value('id');

        if ($flagId === null) {
            return null;
        }

        $generalId = DB::table('languages')->insertGetId([
            'code' => $aiLanguage->code,
            'direction' => $aiLanguage->direction,
            'is_default_website' => false,
            'is_default_dashboard' => false,
            'stores_translation' => false,
            'status' => true,
            'ai_enabled' => (bool) $aiLanguage->is_active,
            'flag_id' => $flagId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('language_translations')->insert([
            'language_id' => $generalId,
            'locale' => 'en',
            'name' => $aiLanguage->name,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $generalId;
    }

    /**
     * @param  array<int, int>  $map
     */
    protected function remapForeignKey(string $table, array $map, bool $cascade): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'language_id')) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint) {
            $blueprint->dropForeign(['language_id']);
        });

        foreach ($map as $oldId => $newId) {
            DB::table($table)->where('language_id', $oldId)->update(['language_id' => $newId]);
        }

        Schema::table($table, function (Blueprint $blueprint) use ($cascade) {
            $foreign = $blueprint->foreign('language_id')->references('id')->on('languages');

            $cascade ? $foreign->cascadeOnDelete() : $foreign->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Intentionally irreversible, same as other consolidating
        // migrations in this project - recreating ai_languages with its
        // exact pre-migration ids/content is not something a down() can
        // safely reconstruct.
    }
};
