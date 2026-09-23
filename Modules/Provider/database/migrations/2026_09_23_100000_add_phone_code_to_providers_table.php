<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (! Schema::hasColumn('providers', 'phone_code')) {
                $table->string('phone_code')
                    ->nullable()
                    ->after('phone')
                    ->comment('مفتاح اتصال الدولة');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('providers', function (Blueprint $table) {
            if (Schema::hasColumn('providers', 'phone_code')) {
                $table->dropColumn('phone_code');
            }
        });
    }
};
