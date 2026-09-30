<?php

use App\Enums\ServiceAudience;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_categories', function (Blueprint $table) {
            $table->json('audiences')->nullable()->after('module_name');
        });

        Schema::table('service_category_translations', function (Blueprint $table) {
            $table->longText('description')->nullable()->after('name');
        });

        DB::table('service_categories')
            ->orderBy('id')
            ->chunk(100, function ($rows): void {
                foreach ($rows as $row) {
                    $audiences = ServiceAudience::inferFromLegacy(
                        (bool) $row->requires_provider,
                        (bool) $row->is_login_dashboard,
                        $row->module_name,
                    );

                    DB::table('service_categories')
                        ->where('id', $row->id)
                        ->update(['audiences' => json_encode($audiences, JSON_THROW_ON_ERROR)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('service_category_translations', function (Blueprint $table) {
            $table->dropColumn('description');
        });

        Schema::table('service_categories', function (Blueprint $table) {
            $table->dropColumn('audiences');
        });
    }
};
