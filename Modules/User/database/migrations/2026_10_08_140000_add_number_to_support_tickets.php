<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The number a customer and the team quote for a ticket: random and unique (7 digits), not the database id.
 * Tickets that already exist get one too.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->string('number', 12)->nullable()->unique()->after('id');
        });

        $taken = [];

        DB::table('support_tickets')->orderBy('id')->pluck('id')->each(function ($id) use (&$taken) {
            do {
                $number = (string) random_int(1000000, 9999999);
            } while (isset($taken[$number]));

            $taken[$number] = true;
            DB::table('support_tickets')->where('id', $id)->update(['number' => $number]);
        });
    }

    public function down(): void
    {
        Schema::table('support_tickets', function (Blueprint $table) {
            $table->dropUnique(['number']);
            $table->dropColumn('number');
        });
    }
};
