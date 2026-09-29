<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('whatsapps', function (Blueprint $table) {
            $table->string('phone_number')->nullable()->after('phone_number_id');
            $table->foreignId('phone_country_id')->nullable()->after('phone_number')->constrained('countries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('whatsapps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('phone_country_id');
            $table->dropColumn('phone_number');
        });
    }
};
