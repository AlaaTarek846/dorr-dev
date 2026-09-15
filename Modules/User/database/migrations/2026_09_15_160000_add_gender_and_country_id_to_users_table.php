<?php

use App\Enums\Gender;
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
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'gender')) {
                $table->enum('gender', array_column(Gender::cases(), 'value'))
                    ->nullable()
                    ->after('phone')
                    ->comment('الجنس');
            }

            if (! Schema::hasColumn('users', 'country_id')) {
                $table->foreignId('country_id')
                    ->nullable()
                    ->after('gender')
                    ->constrained('countries')
                    ->comment('الدولة');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'country_id')) {
                $table->dropConstrainedForeignId('country_id');
            }

            if (Schema::hasColumn('users', 'gender')) {
                $table->dropColumn('gender');
            }
        });
    }
};
