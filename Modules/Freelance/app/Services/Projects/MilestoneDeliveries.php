<?php

namespace Modules\Freelance\Services\Projects;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Enums\FreelanceMilestoneStatus as Status;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceDelivery as Delivery;
use Modules\Freelance\Models\FreelanceMilestone as Milestone;
use Modules\Freelance\Models\FreelanceMilestoneCheck as Check;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * What a part's delivery is made of (FL-601 / FL-603 / FL-604): every delivery is a numbered version with its note and files (kept, never
 * changed), the changes the client asks for are written on the version they are about, and the client's checklist for the part — set while
 * the work hasn't started (after that, a change of scope goes through a change request, FL-405), ticked while reviewing a delivery.
 * Ticking doesn't approve anything: the client still decides.
 */
class MilestoneDeliveries
{
    public const MAX_FILES = 10;

    public const MAX_CHECKS = 10;

    public const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    /** The file types that may be delivered (checked again here, whatever the request said). */
    public const MIMES = 'jpg,jpeg,png,webp,gif,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,zip';

    /**
     * A new version for the part: the note and the files. Called inside the delivering transaction, so a refused file leaves nothing behind.
     *
     * @param  list<UploadedFile>  $files
     */
    public function record(Milestone $m, string $note, array $files): Delivery
    {
        if (count($files) > self::MAX_FILES) {
            throw new FreelanceException('delivery_too_many_files', 422, ['max' => self::MAX_FILES]);
        }
        $number = (int) Delivery::query()->where('milestone_id', $m->id)->max('number') + 1;
        $delivery = Delivery::query()->create(['milestone_id' => $m->id, 'number' => $number, 'note' => trim(strip_tags($note)), 'created_at' => now()]);
        foreach ($files as $file) {
            $delivery->addFile($file);
        }

        return $delivery;
    }

    /** The changes asked for go on the version the client was looking at (the last one). */
    public function markRevision(Milestone $m, string $reason): void
    {
        Delivery::query()->where('milestone_id', $m->id)->orderByDesc('number')->limit(1)
            ->update(['revision_reason' => mb_substr($reason, 0, 1000), 'revision_requested_at' => now()]);
    }

    /** The name the person gave the file (the stored name is generated): "design.png", not "files-1.png". */
    public static function displayName(Media $f): string
    {
        $ext = pathinfo($f->file_name, PATHINFO_EXTENSION);

        return trim($f->name) !== '' ? $f->name.($ext !== '' ? '.'.$ext : '') : $f->file_name;
    }

    public function count(Milestone $m): int
    {
        return Delivery::query()->where('milestone_id', $m->id)->count();
    }

    /**
     * @return list<array<string, mixed>> newest first; the files carry the path the apps download them from
     */
    public function list(Milestone $m, string $filesPath): array
    {
        return Delivery::query()->where('milestone_id', $m->id)->orderByDesc('number')->with('media')->get()->map(fn (Delivery $d) => [
            'uuid' => $d->uuid,
            'number' => $d->number,
            'note' => $d->note,
            'delivered_at' => $d->created_at?->toIso8601String(),
            'revision_reason' => $d->revision_reason,
            'revision_requested_at' => $d->revision_requested_at?->toIso8601String(),
            'files' => $d->getMedia('files')->map(fn (Media $f) => [
                'id' => $f->id, 'name' => self::displayName($f), 'mime' => $f->mime_type, 'size' => $f->size,
                'is_image' => in_array($f->mime_type, self::IMAGE_MIMES, true),
                'path' => "{$filesPath}/{$d->uuid}/files/{$f->id}",
            ])->values()->all(),
        ])->all();
    }

    public function file(Milestone $m, string $deliveryUuid, int $mediaId): Media
    {
        $delivery = Delivery::query()->where('milestone_id', $m->id)->where('uuid', $deliveryUuid)->firstOrFail();

        return $delivery->getMedia('files')->firstWhere('id', $mediaId) ?? abort(404);
    }

    // ------------------------------------------------------------ checklist (FL-604)

    /** @return list<array{uuid: string, text: string, checked: bool}> */
    public function checks(Milestone $m): array
    {
        return Check::query()->where('milestone_id', $m->id)->orderBy('position')->get()
            ->map(fn (Check $c) => ['uuid' => $c->uuid, 'text' => $c->text, 'checked' => $c->checked_at !== null])->all();
    }

    /** The client writes (replaces) the list while the work hasn't started. @param  list<string>  $texts */
    public function setChecks(Milestone $m, array $texts): void
    {
        if (! in_array($m->status, [Status::Unfunded, Status::Funded], true)) {
            throw new FreelanceException('checklist_locked', 409);
        }
        $texts = collect($texts)->map(fn ($t) => mb_substr(trim(strip_tags((string) $t)), 0, 200))->filter()->unique()->take(self::MAX_CHECKS)->values();
        DB::transaction(function () use ($m, $texts): void {
            Check::query()->where('milestone_id', $m->id)->delete();
            foreach ($texts as $i => $text) {
                Check::query()->create(['milestone_id' => $m->id, 'position' => $i + 1, 'text' => $text]);
            }
        });
    }

    /** The client ticks / unticks a line while looking at a delivery. */
    public function tick(Milestone $m, string $uuid, bool $checked): void
    {
        if ($m->status !== Status::Delivered) {
            throw new FreelanceException('checklist_not_reviewing', 409);
        }
        Check::query()->where('milestone_id', $m->id)->where('uuid', $uuid)->firstOrFail()->update(['checked_at' => $checked ? now() : null]);
    }

    /** A new round starts from an empty tick list. */
    public function clearTicks(Milestone $m): void
    {
        Check::query()->where('milestone_id', $m->id)->update(['checked_at' => null]);
    }
}
