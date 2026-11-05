<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The promo banners on the freelancer app's home (the redesign, batch B): the staff write them in both languages, for every country or one,
 * between two dates, with an image and a place in the app (or a web page) a tap opens.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_promos', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->json('title');
            $table->json('body')->nullable();
            $table->json('cta')->nullable();
            // none | screen (a page of the app, one of FreelancePromo::SCREENS) | url (https only)
            $table->string('target_type', 16)->default('none');
            $table->string('target', 500)->nullable();
            $table->foreignId('country_id')->nullable()->constrained('countries')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_promos');
    }
};
