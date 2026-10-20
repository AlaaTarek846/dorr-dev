<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A city can say which governorate / state it is in (optional, and always one of its own country's). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('discover_cities', function (Blueprint $table) {
            $table->foreignId('region_id')->nullable()->after('country_id')->constrained('regions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('discover_cities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('region_id');
        });
    }
};
