<?php

namespace Modules\Freelance\Services\Workroom;

use Modules\Freelance\Models\FreelanceDispute as Dispute;
use Modules\Freelance\Models\FreelanceWorkroomComment as Comment;
use Modules\Freelance\Models\FreelanceWorkroomDecision as Decision;
use Modules\Freelance\Models\FreelanceWorkroomFile as File;
use Modules\Freelance\Models\FreelanceWorkroomFileVersion as Version;
use Modules\Freelance\Models\FreelanceWorkroomTask as Task;
use Modules\Freelance\Services\Projects\MilestoneDeliveries;

/**
 * FS-150f: what the staff read of a disputed project's shared Workroom - tasks, files with every version, notes on them and decisions - as
 * plain facts with no viewer (no `mine`, no `can`). It is the project of the dispute and nothing else, whatever the state of the dispute or
 * of the country's `workroom` switch (the work may have been done while it was on). Reading only; the controller writes the audit record.
 */
class DisputeWorkroom
{
    /** @return array<string, mixed> */
    public function build(Dispute $d): array
    {
        $project = (int) $d->project_id;
        $tasks = Task::query()->where('project_id', $project)->where('scope', Task::SHARED)->with('milestone')->orderBy('id')->get();
        $files = File::query()->where('project_id', $project)->where('scope', 'shared')->with(['milestone', 'versions'])->orderBy('id')->get();
        $comments = Comment::query()->where('project_id', $project)->where('scope', 'shared')->with(['file', 'delivery'])->orderBy('id')->get();
        $decisions = Decision::query()->where('project_id', $project)->with(['milestone', 'supersedes', 'supersededBy'])->orderBy('id')->get();

        return [
            'dispute' => $d->uuid,
            'tasks' => $tasks->map(fn (Task $t) => [
                'uuid' => $t->uuid, 'title' => $t->title, 'description' => $t->description, 'status' => $t->status,
                'due_on' => $t->due_on?->toDateString(), 'milestone' => $this->part($t->milestone),
                'assignee_side' => $t->assignee_side, 'created_by_side' => $t->created_by_side,
                'completed_at' => $t->completed_at?->toIso8601String(), 'created_at' => $t->created_at?->toIso8601String(),
            ])->values()->all(),
            'files' => $files->map(fn (File $f) => [
                'uuid' => $f->uuid, 'title' => $f->title, 'description' => $f->description, 'milestone' => $this->part($f->milestone),
                'created_by_side' => $f->created_by_side, 'created_at' => $f->created_at?->toIso8601String(),
                'versions' => $f->versions->sortBy('number')->map(fn (Version $v) => [
                    'number' => $v->number, 'note' => $v->note, 'name' => $v->original_name, 'mime_type' => $v->mime_type,
                    'size_bytes' => $v->size_bytes, 'by_side' => $v->created_by_side, 'created_at' => $v->created_at?->toIso8601String(),
                    'path' => "freelance-disputes/{$d->uuid}/workroom/files/{$f->uuid}/versions/{$v->number}/download",
                ])->values()->all(),
            ])->values()->all(),
            'comments' => $comments->map(fn (Comment $m) => [
                'uuid' => $m->uuid, 'body' => $m->body,
                'subject' => ['type' => $m->file_id !== null ? 'file' : 'delivery', 'uuid' => $m->file?->uuid ?? $m->delivery?->uuid],
                'version' => $m->version_number, 'author_side' => $m->author_side,
                'edited_at' => $m->edited_at?->toIso8601String(), 'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
            'decisions' => $decisions->map(fn (Decision $x) => [
                'uuid' => $x->uuid, 'title' => $x->title, 'body' => $x->body, 'status' => $x->status, 'milestone' => $this->part($x->milestone),
                'proposed_by_side' => $x->proposed_by_side, 'client_agreed' => $x->client_ack_at !== null, 'freelancer_agreed' => $x->freelancer_ack_at !== null,
                'answer_note' => $x->answer_note,
                'supersedes' => $x->supersedes === null ? null : ['uuid' => $x->supersedes->uuid, 'title' => $x->supersedes->title],
                'superseded_by' => $x->supersededBy === null ? null : ['uuid' => $x->supersededBy->uuid, 'title' => $x->supersededBy->title],
                'answered_at' => $x->answered_at?->toIso8601String(), 'created_at' => $x->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /** One version of a file of the dispute's project, or a 404. @return array{0: File, 1: Version} */
    public function version(Dispute $d, string $file, int $number): array
    {
        $f = File::query()->where('project_id', $d->project_id)->where('scope', 'shared')->where('uuid', $file)->firstOrFail();

        return [$f, Version::query()->where('file_id', $f->id)->where('number', $number)->with('media')->firstOrFail()];
    }

    public static function inline(?string $mime): bool
    {
        return in_array($mime, MilestoneDeliveries::IMAGE_MIMES, true);
    }

    /** @return ?array{uuid: string, title: string, position: int} */
    private function part(mixed $m): ?array
    {
        return $m === null ? null : ['uuid' => $m->uuid, 'title' => $m->title, 'position' => (int) $m->position];
    }
}
