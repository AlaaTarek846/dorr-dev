<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The country a user last signed in from (by IP, saved once per sign-in) — so the app's country
 * (wallet, payment methods, prices) doesn't need an IP lookup on every request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('logged_in_country_id')->nullable()->after('country_id')
                ->constrained('countries')->nullOnDelete()
                ->comment('Country of the last sign-in (by IP; SA when unknown)');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('logged_in_country_id');
        });
    }
};
