<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DORR services offered inside an answer (AiServiceActions): e.g. "Book with DORR" with the destination,
 * time and riders already understood when the person said they want to go somewhere.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_messages', function (Blueprint $table) {
            $table->json('actions')->nullable()->after('safety')->comment('كروت خدمات دُرر تحت الرد (زي «احجز مشوارك مع دُرر» والبيانات جاهزة)');
        });
    }

    public function down(): void
    {
        Schema::table('ai_messages', fn (Blueprint $table) => $table->dropColumn('actions'));
    }
};
