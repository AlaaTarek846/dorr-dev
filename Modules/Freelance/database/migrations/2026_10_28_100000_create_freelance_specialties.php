<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FL-101: the specialties a freelancer works in — a specialty, and sub-specialties under it (two levels), each with a name in every
 * language, an order and a switch. Policies (FL-008) already carry a `specialty_id` for a country + specialty scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_specialties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('freelance_specialties')->restrictOnDelete();
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('freelance_specialty_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelance_specialty_id')->constrained('freelance_specialties')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 80);
            $table->string('description', 255)->nullable();
            $table->unique(['freelance_specialty_id', 'locale'], 'freelance_specialty_locale_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_specialty_translations');
        Schema::dropIfExists('freelance_specialties');
    }
};
