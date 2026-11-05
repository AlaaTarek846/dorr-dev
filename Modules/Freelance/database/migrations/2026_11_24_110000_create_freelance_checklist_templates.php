<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-105: ready-made "what I will check before approving" lists by kind of project (website, app, design, marketing…), kept by the admin
 * in both languages. A client picks one for a part and edits it; the part's own checklist (FL-604) is still what counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->foreignId('specialty_id')->nullable()->constrained('freelance_specialties')->nullOnDelete()->comment('null = any kind of project');
            $table->boolean('status')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('freelance_checklist_template_translations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('freelance_checklist_template_id')->constrained('freelance_checklist_templates', 'id', 'fl_chk_tpl_trans_fk')->cascadeOnDelete();
            $table->string('locale', 10);
            $table->string('name', 191);
            $table->text('items')->nullable()->comment('one check per line');
            $table->unique(['freelance_checklist_template_id', 'locale'], 'fl_chk_tpl_locale_unique');
        });

        \Modules\Freelance\Services\Projects\ChecklistTemplateDefaults::ensure();
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_checklist_template_translations');
        Schema::dropIfExists('freelance_checklist_templates');
    }
};