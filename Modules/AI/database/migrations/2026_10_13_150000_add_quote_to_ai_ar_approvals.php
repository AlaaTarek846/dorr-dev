<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The provider can attach a price to an approval; accepting the design means accepting that price.
        Schema::table('ai_ar_approvals', function (Blueprint $table) {
            $table->unsignedBigInteger('quote_minor')->nullable()->after('provider_note'); // minor units of `currency`
            $table->string('currency', 3)->nullable()->after('quote_minor');
            $table->timestamp('accepted_at')->nullable()->after('decided_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_ar_approvals', function (Blueprint $table) {
            $table->dropColumn(['quote_minor', 'currency', 'accepted_at']);
        });
    }
};
