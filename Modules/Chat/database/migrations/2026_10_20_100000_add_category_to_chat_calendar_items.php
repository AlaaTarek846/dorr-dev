<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What kind of appointment it is — a match, a meeting, a trip… — chosen in the form. Every appointment made before this
 * is a plain "appointment".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_calendar_items', function (Blueprint $table) {
            $table->string('category', 20)->default('appointment')->after('title')
                ->comment('appointment | meeting | match | event | trip | study | health | work');
        });
    }

    public function down(): void
    {
        Schema::table('chat_calendar_items', function (Blueprint $table) {
            $table->dropColumn('category');
        });
    }
};
