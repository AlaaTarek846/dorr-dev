<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_site_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->foreignId('offer_id')->constrained('ai_site_offers');
            $table->decimal('amount', 10, 2);
            $table->string('currency', 3);
            $table->string('wallet_operation_id')->nullable();
            $table->unsignedInteger('generations_included');
            $table->unsignedInteger('generations_used')->default(0);
            $table->string('status', 16)->default('active')->comment('active, refunded');
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_site_purchases');
    }
};
