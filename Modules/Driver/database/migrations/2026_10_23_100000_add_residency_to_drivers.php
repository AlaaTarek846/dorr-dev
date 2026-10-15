<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Citizen / resident, in every country (owner, 2026-10-14): on the driver, and on each document
 * requirement — a document can be required from citizens, from residents, or from both (null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('drivers', function (Blueprint $table) {
            $table->string('residency', 10)->default('citizen')->after('nationality_country_id')->comment('citizen / resident في دولة الشغل');
            $table->index(['country_id', 'residency']);
        });

        Schema::table('driver_document_requirements', function (Blueprint $table) {
            $table->string('residency', 10)->nullable()->after('service_category_id')->comment('null = مطلوب من الاتنين، أو citizen / resident بس');
            // The new index first: MySQL keeps the old one for the document_type_id foreign key until then.
            $table->unique(['document_type_id', 'country_id', 'service_category_id', 'residency'], 'driver_doc_req_residency_unique');
        });

        Schema::table('driver_document_requirements', function (Blueprint $table) {
            $table->dropUnique('driver_doc_requirement_unique');
        });
    }

    public function down(): void
    {
        Schema::table('driver_document_requirements', function (Blueprint $table) {
            $table->unique(['document_type_id', 'country_id', 'service_category_id'], 'driver_doc_requirement_unique');
        });

        Schema::table('driver_document_requirements', function (Blueprint $table) {
            $table->dropUnique('driver_doc_req_residency_unique');
            $table->dropColumn('residency');
        });

        Schema::table('drivers', function (Blueprint $table) {
            $table->dropIndex(['country_id', 'residency']);
            $table->dropColumn('residency');
        });
    }
};
