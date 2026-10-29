<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** FL-707: the internal quality score and risk level of a client (a Dorr user, so kept beside the users table), for the staff list and its filter. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_client_risk', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->string('risk_level', 8)->default('low')->comment('low | medium | high');
            $table->timestamp('checked_at')->nullable();
            $table->index('risk_level');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_client_risk');
    }
};
