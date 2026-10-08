<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What customers pressed at the end of a help topic: "that solved my problem" or "I need an agent".
 * One row per press. The topic may be deleted later; the answer stays, without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_help_feedback', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_help_node_id')->nullable()->constrained('support_help_nodes')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('solved');
            $table->timestamps();

            $table->index(['support_help_node_id', 'solved']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_help_feedback');
    }
};
