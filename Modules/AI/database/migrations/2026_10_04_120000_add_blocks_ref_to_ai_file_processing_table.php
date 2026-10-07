<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 (Document Processing): one additive column, no new table.
 * `blocks_ref` follows the exact same content-reference pattern already
 * used by `extracted_content_ref` (and by AiKnowledgeIngestionService's
 * chunk files before it) - the normalized block structure (headings,
 * paragraphs, tables, ...) is a JSON file on the private disk, with only
 * a path reference kept here, never the content itself in a DB column.
 *
 * A dedicated `ai_file_contents` table (one row per block) was
 * considered and deliberately not built: the existing content-reference
 * pattern already solves "don't store unlimited content in one column"
 * without a new table, and per-block database rows would multiply row
 * count by the block count of every document for no present benefit -
 * see the Phase 2 report for the full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_file_processing', function (Blueprint $table) {
            $table->string('blocks_ref')->nullable()->after('extracted_content_ref')
                ->comment('مكان تخزين البنية المعيارية (blocks JSON) - مرجع فقط، نفس نمط extracted_content_ref');
        });
    }

    public function down(): void
    {
        Schema::table('ai_file_processing', function (Blueprint $table) {
            $table->dropColumn('blocks_ref');
        });
    }
};
