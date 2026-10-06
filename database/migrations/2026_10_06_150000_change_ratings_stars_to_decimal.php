<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Ratings can be given in quarter stars (e.g. 3.25, 4.5), so `stars` is a decimal. */
    public function up(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->decimal('stars', 3, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ratings', function (Blueprint $table) {
            $table->unsignedTinyInteger('stars')->change();
        });
    }
};
