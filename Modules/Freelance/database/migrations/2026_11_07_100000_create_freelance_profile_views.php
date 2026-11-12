<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How often a freelancer's profile and ready-made services are looked at (FL-L11): one counter per day, kind and place the visit came from
 * — never who looked. A row is the number of visits of that day, so the table grows by a few rows a day however many clients come.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_profile_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            // The service looked at, for `service` visits (0 for the profile: a real column so the unique key can hold it).
            $table->unsignedBigInteger('service_id')->default(0);
            $table->string('kind', 12)->comment('profile | service');
            $table->string('source', 12)->comment('offer | portfolio | market');
            $table->date('day');
            $table->unsignedInteger('views')->default(0);
            $table->timestamps();

            $table->unique(['freelancer_id', 'service_id', 'kind', 'source', 'day'], 'freelance_profile_views_unique');
            $table->index(['freelancer_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_profile_views');
    }
};
