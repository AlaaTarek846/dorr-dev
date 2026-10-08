<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The guided help menu of the app (before a ticket is opened): a tree of topics. A topic with sub-topics is
 * a menu; a topic without any is an answer, after which the customer must say "solved" or "I need an agent".
 * Titles and answers are translated, like every other translated table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_help_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('support_help_nodes')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();

            $table->index(['parent_id', 'sort_order']);
        });

        Schema::create('support_help_node_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_help_node_id')->constrained('support_help_nodes')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('title', 150);
            $table->text('answer')->nullable();
            $table->timestamps();

            $table->unique(['support_help_node_id', 'locale'], 'support_help_node_translations_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_help_node_translations');
        Schema::dropIfExists('support_help_nodes');
    }
};
