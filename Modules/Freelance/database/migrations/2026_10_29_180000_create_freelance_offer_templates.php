<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-304: the texts a freelancer keeps to start an offer from. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_offer_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->string('title', 80);
            $table->text('details');
            $table->timestamps();
            $table->index(['freelancer_id', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_offer_templates');
    }
};
