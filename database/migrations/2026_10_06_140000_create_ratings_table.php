<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ratings', function (Blueprint $table) {
            $table->id();

            // Who rated (a user today; any authenticatable model tomorrow).
            $table->morphs('author');

            // What was rated. Empty = the app itself ("Rate the app").
            $table->nullableMorphs('rateable');

            // sha256 of author + rated thing: one rating per author for each thing, enforced by the database
            // (a unique index on the morph columns alone would let several "app" ratings through because of the NULLs).
            $table->char('unique_key', 64)->unique();

            $table->unsignedTinyInteger('stars');

            // feedback = 1–3 stars, kept internally only; review = 4–5 stars, the person is also invited to rate on the store.
            $table->string('type', 20)->index();

            $table->text('comment')->nullable();
            $table->timestamps();

            $table->index('stars');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ratings');
    }
};
