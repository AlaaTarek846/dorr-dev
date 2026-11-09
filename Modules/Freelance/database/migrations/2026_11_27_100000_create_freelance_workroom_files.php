<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FS-150b: the Workroom's File Hub (docs/modules/freelance/TEAMS-ERD.md, section 3).
 *  - `freelance_workroom_files`: one file of an agreement's Workroom (a title, optionally tied to one of its parts). What is stored is in its
 *    versions; deleting a file only hides it (soft delete), because the project record and a dispute may point at it.
 *  - `freelance_workroom_file_versions`: one uploaded copy, numbered 1, 2, 3… and never changed. The bytes live in the media library on the
 *    private disk, only after the upload passed the same size / type / scan checks as a delivery.
 *  - `freelance_workroom_comments`: a note on a file (optionally on one of its versions) or on a delivery of a part; the writer's side edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('freelance_workroom_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('milestone_id')->nullable()->constrained('freelance_milestones')->nullOnDelete();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->unsignedInteger('versions_count')->default(0);
            $table->string('created_by_side', 10)->comment('client | freelancer');
            $table->unsignedBigInteger('created_by_id')->comment('the client user or the freelancer');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['project_id', 'deleted_at', 'updated_at']);
            $table->index('milestone_id');
        });

        Schema::create('freelance_workroom_file_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('file_id')->constrained('freelance_workroom_files')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('note', 500)->nullable()->comment('what this version changes');
            $table->string('original_name', 255);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->string('created_by_side', 10)->comment('client | freelancer');
            $table->unsignedBigInteger('created_by_id');
            $table->timestamp('created_at')->nullable();

            $table->unique(['file_id', 'number']);
        });

        Schema::create('freelance_workroom_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('project_id')->constrained('freelance_projects')->cascadeOnDelete();
            $table->foreignId('agreement_id')->constrained('freelance_agreements')->cascadeOnDelete();
            $table->foreignId('file_id')->nullable()->constrained('freelance_workroom_files')->cascadeOnDelete();
            $table->unsignedInteger('version_number')->nullable()->comment('the version of the file the note is about, when it is about one');
            $table->foreignId('delivery_id')->nullable()->constrained('freelance_deliveries')->cascadeOnDelete();
            $table->string('body', 2000);
            $table->string('author_side', 10)->comment('client | freelancer');
            $table->unsignedBigInteger('author_id')->comment('the client user or the freelancer');
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['file_id', 'id']);
            $table->index(['delivery_id', 'id']);
            $table->index(['project_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('freelance_workroom_comments');
        Schema::dropIfExists('freelance_workroom_file_versions');
        Schema::dropIfExists('freelance_workroom_files');
    }
};
