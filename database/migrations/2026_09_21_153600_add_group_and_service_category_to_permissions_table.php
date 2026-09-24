<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('permission.table_names.permissions');

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (! Schema::hasColumn($tableName, 'group_name')) {
                $table->string('group_name')->nullable()->after('guard_name');
            }

            if (! Schema::hasColumn($tableName, 'service_category_id')) {
                $table->foreignId('service_category_id')
                    ->nullable()
                    ->after('group_name')
                    ->constrained('service_categories')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        $tableName = config('permission.table_names.permissions');

        Schema::table($tableName, function (Blueprint $table) use ($tableName) {
            if (Schema::hasColumn($tableName, 'service_category_id')) {
                $table->dropForeign(['service_category_id']);
                $table->dropColumn('service_category_id');
            }

            if (Schema::hasColumn($tableName, 'group_name')) {
                $table->dropColumn('group_name');
            }
        });
    }
};
