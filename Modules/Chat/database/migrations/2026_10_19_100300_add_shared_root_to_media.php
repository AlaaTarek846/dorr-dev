<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A forwarded file is no longer copied: the new media row points at the first one's file
 * (`shared_root_id` = that first row's id, its folder in `custom_properties.shared_path`).
 * The file is removed only when the last row using it goes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->unsignedBigInteger('shared_root_id')->nullable()->after('order_column')->index();
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table) {
            $table->dropIndex(['shared_root_id']);
            $table->dropColumn('shared_root_id');
        });
    }
};
