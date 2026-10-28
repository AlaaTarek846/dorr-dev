<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per country only (owner's decision). No row = off.
        Schema::create('freelance_feature_rules', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 48)->comment('freelance / projects / bidding / milestones …');
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('note', 255)->nullable()->comment('سبب للإدارة: تجربة، ترخيص…');
            $table->timestamps();
            $table->unique(['feature', 'country_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_feature_rules');
    }
};
