<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The numbers the admin sets for freelance work (FL-008): commission, client fee, offers, review and
 * holding times — per country, and per country + specialty. A row holds only the keys its scope changes;
 * FreelancePolicies merges them over `freelance.policy_defaults`. Money is in the minor unit of the
 * country's own currency.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->unsignedBigInteger('specialty_id')->nullable()->comment('null = كل التخصصات (الكتالوج في FL-101)');
            $table->json('data')->comment('المفاتيح اللي النطاق ده بيغيرها بس');
            $table->boolean('status')->default(true);
            $table->foreignId('updated_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamps();

            // Uniqueness of a scope (NULL specialty included) is enforced by FreelancePolicies::save.
            $table->index(['country_id', 'specialty_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_policies');
    }
};
