<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-103: what a freelancer tells about themselves — a headline, a bio, years of experience, the languages they talk
 * to clients in, a city — and whether their email was confirmed by a code. The currency isn't here: it is the country's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelancers', function (Blueprint $table) {
            $table->string('headline', 100)->nullable()->after('name')->comment('العنوان المهني');
            $table->text('bio')->nullable()->after('headline')->comment('النبذة');
            $table->unsignedTinyInteger('years_of_experience')->nullable()->after('bio');
            $table->json('contact_languages')->nullable()->after('years_of_experience')->comment('لغات التواصل مع العملاء');
            $table->string('city', 80)->nullable()->after('country_id');
            $table->timestamp('email_verified_at')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('freelancers', function (Blueprint $table) {
            $table->dropColumn(['headline', 'bio', 'years_of_experience', 'contact_languages', 'city', 'email_verified_at']);
        });
    }
};
