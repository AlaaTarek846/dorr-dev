<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * FL-803: staff classify a dispute (what kind of problem it is) so the support and the reports can group them. The reason the person
 * gave stays as it was; this is staff's own label and can be changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('freelance_disputes', function (Blueprint $table) {
            $table->string('category', 16)->nullable()->after('reason')->comment('payment | delivery | quality | conduct | scope | other — set by staff');
            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('freelance_disputes', function (Blueprint $table) {
            $table->dropIndex(['category']);
            $table->dropColumn('category');
        });
    }
};
