<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-207: the freelancers team keeps its own files and notes in the Workroom (`scope` = team): the client never sees them. Tasks already
 * carry `scope` (FS-150a); a note takes the scope of the file it is on.
 */
return new class extends Migration
{
    private const TABLES = ['freelance_workroom_files', 'freelance_workroom_comments'];

    public function up(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->string('scope', 10)->default('shared')->comment('shared | team');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn('scope');
            });
        }
    }
};
