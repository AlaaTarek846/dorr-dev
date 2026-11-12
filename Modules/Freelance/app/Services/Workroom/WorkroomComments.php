<?php

namespace Modules\Freelance\Services\Workroom;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceDelivery as Delivery;
use Modules\Freelance\Models\FreelanceWorkroomComment as Comment;
use Modules\Freelance\Models\FreelanceWorkroomFile as File;
use Modules\Freelance\Models\FreelanceWorkroomFileVersion as Version;
use Modules\Freelance\Models\FreelanceWorkroomTask as Side;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;

/**
 * The Workroom's notes (FS-150b): a note is written on a file (optionally on one of its versions) or on a delivery of a part. Both sides of
 * the agreement read them; either side writes one; the side that wrote a note edits and deletes it. After the agreement ends the notes are
 * only read. Every step is written on the project's record (without the text), tells the live screens once, and a new note reaches the
 * other side as a notice.
 */
class WorkroomComments
{
    public const BODY_MAX = 2000;

    public function __construct(
        private readonly WorkroomFiles $files,
        private readonly FreelanceNotifier $notifier,
    ) {}

    // -------------------------------------------------------------- reading

    /** The file a note can be on (404 when it is not this Workroom's, or was deleted). */
    public function file(WorkroomContext $c, string $uuid): File
    {
        return $this->files->find($c, $uuid);
    }

    /** The delivery a note can be on: one of the agreement's own parts (404 otherwise). */
    public function delivery(WorkroomContext $c, string $uuid): Delivery
    {
        return Delivery::query()->where('uuid', $uuid)
            ->whereHas('milestone', fn ($q) => $q->where('agreement_id', $c->agreement->id))->with('milestone')->firstOrFail();
    }

    public function list(WorkroomContext $c, ?File $file, ?Delivery $delivery, ?int $version, int $perPage): LengthAwarePaginator
    {
        $query = WorkroomSupport::visible(Comment::query()->where('project_id', $c->project->id), $c)->with(['file:id,uuid', 'delivery:id,uuid']);
        if ($file !== null) {
            $query->where('file_id', $file->id);
            if ($version !== null) {
                $query->where('version_number', $version);
            }
        } else {
            $query->where('delivery_id', $delivery?->id);
        }

        return $query->orderBy('id')->paginate($perPage);
    }

    public function find(WorkroomContext $c, string $uuid): Comment
    {
        return WorkroomSupport::visible(Comment::query()->where('project_id', $c->project->id), $c)->where('uuid', $uuid)
            // A note on a deleted file is hidden with it.
            ->where(fn ($q) => $q->whereNull('file_id')->orWhereHas('file'))
            ->with(['file:id,uuid', 'delivery:id,uuid'])->firstOrFail();
    }

    // -------------------------------------------------------------- writing

    public function create(WorkroomContext $c, ?File $file, ?Delivery $delivery, ?int $version, string $body): Comment
    {
        $comment = DB::transaction(function () use ($c, $file, $delivery, $version, $body): Comment {
            WorkroomSupport::lockActive($c);
            if ($file !== null) {
                // The file may have been deleted a moment ago.
                File::query()->where('project_id', $c->project->id)->findOrFail($file->id);
                if ($version !== null && ! Version::query()->where('file_id', $file->id)->where('number', $version)->exists()) {
                    throw new FreelanceException('workroom_version_invalid', 422);
                }
            }

            $comment = Comment::query()->create([
                'project_id' => $c->project->id,
                'agreement_id' => $c->agreement->id,
                'file_id' => $file?->id,
                'version_number' => $file !== null ? $version : null,
                'delivery_id' => $file === null ? $delivery?->id : null,
                'body' => $this->body($body),
                'scope' => $file?->scope ?? 'shared',
                'author_side' => $c->side,
                'author_id' => (int) $c->actor->getKey(),
            ]);
            WorkroomSupport::record($c, 'workroom.comment.created', $this->payload($comment, $file, $delivery), $comment->scope);

            return $comment;
        });

        $label = $file?->title ?? (string) $delivery?->milestone?->title;
        $this->after($c, $comment, $file?->uuid ?? (string) $delivery?->uuid, 'created', $label);

        return $comment->load(['file:id,uuid', 'delivery:id,uuid']);
    }

    public function update(WorkroomContext $c, Comment $comment, string $body): Comment
    {
        $comment = DB::transaction(function () use ($c, $comment, $body): Comment {
            WorkroomSupport::lockActive($c);
            $comment = Comment::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($comment->id);
            $this->assertAuthor($c, $comment);
            $comment->forceFill(['body' => $this->body($body), 'edited_at' => now()])->save();
            WorkroomSupport::record($c, 'workroom.comment.updated', $this->payload($comment, null, null), $comment->scope);

            return $comment;
        });

        $comment->load(['file:id,uuid', 'delivery:id,uuid']);
        $this->after($c, $comment, $this->subjectUuid($comment), 'updated');

        return $comment;
    }

    public function delete(WorkroomContext $c, Comment $comment): void
    {
        $comment = DB::transaction(function () use ($c, $comment): Comment {
            WorkroomSupport::lockActive($c);
            $comment = Comment::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($comment->id);
            $this->assertAuthor($c, $comment);
            $comment->delete();
            WorkroomSupport::record($c, 'workroom.comment.deleted', $this->payload($comment, null, null), $comment->scope);

            return $comment;
        });

        $comment->load(['file:id,uuid', 'delivery:id,uuid']);
        $this->after($c, $comment, $this->subjectUuid($comment), 'deleted');
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public function present(Comment $m, WorkroomContext $c): array
    {
        $mine = $m->author_side === $c->side;
        $writer = $c->writable() && $mine;

        return [
            'uuid' => $m->uuid,
            'body' => $m->body,
            'subject' => ['type' => $m->file_id !== null ? 'file' : 'delivery', 'uuid' => $this->subjectUuid($m)],
            'version' => $m->version_number,
            'author_side' => $m->author_side,
            'mine' => $mine,
            'scope' => $m->scope,
            'edited_at' => $m->edited_at?->toIso8601String(),
            'created_at' => $m->created_at?->toIso8601String(),
            'can' => ['comment_edit' => $writer, 'comment_delete' => $writer],
        ];
    }

    // -------------------------------------------------------------- inside

    private function assertAuthor(WorkroomContext $c, Comment $comment): void
    {
        if ($comment->author_side !== $c->side) {
            throw new FreelanceException('workroom_comment_forbidden', 403);
        }
    }

    private function body(string $body): string
    {
        $text = trim(strip_tags($body));
        if ($text === '') {
            throw new FreelanceException('workroom_comment_empty', 422);
        }

        return mb_substr($text, 0, self::BODY_MAX);
    }

    private function subjectUuid(Comment $comment): string
    {
        return (string) ($comment->file?->uuid ?? $comment->delivery?->uuid);
    }

    /** The record points at the note and what it is on; the text itself stays out of it. @return array<string, mixed> */
    private function payload(Comment $comment, ?File $file, ?Delivery $delivery): array
    {
        return array_filter([
            'comment' => $comment->uuid,
            'file' => $file?->uuid,
            'delivery' => $delivery?->uuid,
            'version' => $comment->version_number,
        ], fn ($v) => $v !== null);
    }

    /** After the step is saved: a new note reaches the other side, and the live screens of the project reload once. */
    private function after(WorkroomContext $c, Comment $comment, string $subject, string $action, ?string $label = null): void
    {
        if ($action === 'created') {
            $c->agreement->loadMissing(['client', 'freelancer']);
            $receiver = $c->side === Side::CLIENT ? $c->agreement->freelancer : $c->agreement->client;
            if ($receiver !== null) {
                $this->notifier->notify($receiver, 'workroom.comment_added', ['project' => (string) $c->project->title, 'title' => (string) $label], ['project' => $c->project->uuid, 'subject' => $subject, 'comment' => $comment->uuid]);
            }
        }
        // For a note the event carries the file or delivery it is on, so the open screen knows what to reload.
        WorkroomSupport::changed($c, 'comment', $action, $subject, $comment->scope);
    }
}
