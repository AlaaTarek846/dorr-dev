<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-306: the freelancers a client keeps, to find them again. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_client_favorites', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('freelancer_id')->constrained('freelancers')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'freelancer_id']);
            $table->index('freelancer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_client_favorites');
    }
};
