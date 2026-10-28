<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-302: what an offer said before each change — the price, the days, the details and the money worked out with them. Written once, never changed. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_offer_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained('freelance_offers')->cascadeOnDelete();
            $table->unsignedSmallInteger('number')->comment('1 = the offer as first sent');
            $table->unsignedBigInteger('price_minor');
            $table->unsignedSmallInteger('duration_days');
            $table->text('details');
            $table->unsignedBigInteger('commission_minor')->default(0);
            $table->unsignedBigInteger('net_minor')->default(0);
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['offer_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_offer_revisions');
    }
};
