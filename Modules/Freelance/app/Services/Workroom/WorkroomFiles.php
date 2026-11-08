<?php

namespace Modules\Freelance\Services\Workroom;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceWorkroomFile as File;
use Modules\Freelance\Models\FreelanceWorkroomFileVersion as Version;
use Modules\Freelance\Models\FreelanceWorkroomTask as Side;
use Modules\Freelance\Services\Notifications\FreelanceNotifier;
use Modules\Freelance\Services\Policy\FreelancePolicies;
use Modules\Freelance\Services\Projects\MilestoneDeliveries;

/**
 * The Workroom's File Hub (FS-150b). A file is a title (optionally tied to a part) with numbered versions; the two sides of the agreement
 * read every file, either side adds a file, and the side that added a file renames it, adds its next versions and deletes it. A version
 * is never changed. Every upload passes the same size / type / scan checks as a delivery (the controller's rules) and is kept as it was on
 * the private disk. The most files and the most storage one project may hold are the admin's policies `workroom.files_max` /
 * `workroom.storage_mb` (none at first); a limit stops new additions only. After the agreement ends the files are only read.
 *
 * A freelancer's file tied to a part that is not approved yet is, for the client, a preview only whenever the agreement's rights terms
 * (FL-L13) say so - the Workroom never opens work that the delivery keeps closed until it is approved.
 */
class WorkroomFiles
{
    public const TITLE_MAX = 160;

    public const DESCRIPTION_MAX = 1000;

    public const NOTE_MAX = 500;

    /** One file keeps at most this many versions (a guard against a loop of uploads, not a business limit). */
    public const MAX_VERSIONS = 50;

    public function __construct(
        private readonly FreelanceNotifier $notifier,
        private readonly FreelancePolicies $policies,
    ) {}

    // -------------------------------------------------------------- reading

    /**
     * @param  array{milestone?: ?string, mine?: ?bool, search?: ?string}  $filters
     */
    public function list(WorkroomContext $c, array $filters, int $perPage): LengthAwarePaginator
    {
        $query = WorkroomSupport::visible(File::query()->where('project_id', $c->project->id), $c)->with(['milestone', 'current'])->withCount('comments');

        if (! empty($filters['milestone'])) {
            $query->whereHas('milestone', fn ($q) => $q->where('uuid', $filters['milestone']));
        }
        if (! empty($filters['mine'])) {
            $query->where('created_by_side', $c->side);
        }
        if (! empty($filters['search'])) {
            $query->where('title', 'like', '%'.addcslashes((string) $filters['search'], '%_\\').'%');
        }

        return $query->latest('updated_at')->orderByDesc('id')->paginate($perPage);
    }

    /** @return array{files: int, versions: int, storage_bytes: int} */
    public function summary(WorkroomContext $c): array
    {
        $files = WorkroomSupport::visible(File::query()->where('project_id', $c->project->id), $c);

        return [
            'files' => (clone $files)->count(),
            'versions' => (int) (clone $files)->sum('versions_count'),
            'storage_bytes' => $this->used($c),
        ];
    }

    /** @return array{max_files: ?int, max_storage_mb: ?int} what one project may hold now (null = no limit) */
    public function limits(WorkroomContext $c): array
    {
        return ['max_files' => $this->limit($c, 'workroom.files_max'), 'max_storage_mb' => $this->limit($c, 'workroom.storage_mb')];
    }

    public function find(WorkroomContext $c, string $uuid): File
    {
        return WorkroomSupport::visible(File::query()->where('project_id', $c->project->id), $c)->where('uuid', $uuid)->with(['milestone', 'current'])->withCount('comments')->firstOrFail();
    }

    public function version(File $file, int $number): Version
    {
        return Version::query()->where('file_id', $file->id)->where('number', $number)->with('media')->firstOrFail();
    }

    // -------------------------------------------------------------- writing

    /** @param  array{title?: ?string, description?: ?string, milestone?: ?string, note?: ?string}  $data */
    public function create(WorkroomContext $c, array $data, UploadedFile $upload): File
    {
        $scope = WorkroomSupport::scope($c, $data['scope'] ?? null);
        $file = DB::transaction(function () use ($c, $data, $upload, $scope): File {
            WorkroomSupport::lockActive($c);
            $this->assertRoom($c, $upload, true);

            $file = File::query()->create([
                'project_id' => $c->project->id,
                'agreement_id' => $c->agreement->id,
                'milestone_id' => WorkroomSupport::milestone($c, $data['milestone'] ?? null)?->id,
                'title' => $this->title($data['title'] ?? null, $upload),
                'description' => $this->text($data['description'] ?? null, self::DESCRIPTION_MAX),
                'versions_count' => 0,
                'scope' => $scope,
                'created_by_side' => $c->side,
                'created_by_id' => (int) $c->actor->getKey(),
            ]);
            $this->store($c, $file, $upload, $data['note'] ?? null);
            WorkroomSupport::record($c, 'workroom.file.uploaded', ['file' => $file->uuid, 'title' => $file->title, 'version' => 1], $file->scope);

            return $file;
        });

        $this->after($c, $file, 'created', true);

        return $this->fresh($c, $file);
    }

    /** The next version of a file, by the side that added it. */
    public function addVersion(WorkroomContext $c, File $file, ?string $note, UploadedFile $upload): File
    {
        $file = DB::transaction(function () use ($c, $file, $note, $upload): File {
            WorkroomSupport::lockActive($c);
            $file = File::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($file->id);
            $this->assertWriter($c, $file);
            if ($file->versions_count >= self::MAX_VERSIONS) {
                throw new FreelanceException('workroom_versions_limit', 422, ['max' => self::MAX_VERSIONS], ['max' => self::MAX_VERSIONS]);
            }
            $this->assertRoom($c, $upload, false);
            $version = $this->store($c, $file, $upload, $note);
            WorkroomSupport::record($c, 'workroom.file.version', ['file' => $file->uuid, 'title' => $file->title, 'version' => $version->number], $file->scope);

            return $file;
        });

        $this->after($c, $file, 'version', true);

        return $this->fresh($c, $file);
    }

    /** @param  array<string, mixed>  $data  only the keys present are changed (`milestone` / `description` may be null) */
    public function update(WorkroomContext $c, File $file, array $data): File
    {
        $file = DB::transaction(function () use ($c, $file, $data): File {
            WorkroomSupport::lockActive($c);
            $file = File::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($file->id);
            $this->assertWriter($c, $file);

            $fill = [];
            if (array_key_exists('title', $data)) {
                $fill['title'] = $this->title($data['title'], null);
            }
            if (array_key_exists('description', $data)) {
                $fill['description'] = $this->text($data['description'], self::DESCRIPTION_MAX);
            }
            if (array_key_exists('milestone', $data)) {
                $fill['milestone_id'] = WorkroomSupport::milestone($c, $data['milestone'])?->id;
            }
            $file->fill($fill)->save();
            WorkroomSupport::record($c, 'workroom.file.updated', ['file' => $file->uuid, 'title' => $file->title, 'changed' => array_keys($fill)], $file->scope);

            return $file;
        });

        $this->after($c, $file, 'updated');

        return $this->fresh($c, $file);
    }

    /** Hides the file with its versions and notes; they stay on the record (and the disk) for a dispute. */
    public function delete(WorkroomContext $c, File $file): void
    {
        $file = DB::transaction(function () use ($c, $file): File {
            WorkroomSupport::lockActive($c);
            $file = File::query()->where('project_id', $c->project->id)->lockForUpdate()->findOrFail($file->id);
            $this->assertWriter($c, $file);
            $file->delete();
            WorkroomSupport::record($c, 'workroom.file.deleted', ['file' => $file->uuid, 'title' => $file->title], $file->scope);

            return $file;
        });

        $this->after($c, $file, 'deleted');
    }

    // -------------------------------------------------------------- shape

    /** @return array<string, mixed> */
    public function present(File $f, WorkroomContext $c, bool $detail = false): array
    {
        $open = $c->writable();
        $writer = $open && $f->created_by_side === $c->side;
        $milestone = $f->milestone;

        $out = [
            'uuid' => $f->uuid,
            'title' => $f->title,
            'description' => $f->description,
            'milestone' => $milestone === null ? null : ['uuid' => $milestone->uuid, 'title' => $milestone->title, 'position' => (int) $milestone->position],
            'versions_count' => $f->versions_count,
            'comments_count' => (int) ($f->comments_count ?? 0),
            'current' => $f->current === null ? null : $this->presentVersion($f->current, $f, $c),
            'created_by_side' => $f->created_by_side,
            'mine' => $f->created_by_side === $c->side,
            'scope' => $f->scope,
            'created_at' => $f->created_at?->toIso8601String(),
            'updated_at' => $f->updated_at?->toIso8601String(),
            'can' => [
                'file_edit' => $writer,
                'file_delete' => $writer,
                'version_add' => $writer && $f->versions_count < self::MAX_VERSIONS,
                'comment_add' => $open,
            ],
        ];
        if ($detail) {
            $out['versions'] = $f->versions()->with('media')->get()->map(fn (Version $v) => $this->presentVersion($v, $f, $c))->values()->all();
        }

        return $out;
    }

    /** @return array<string, mixed> */
    public function presentVersion(Version $v, File $f, WorkroomContext $c): array
    {
        return [
            'number' => $v->number,
            'note' => $v->note,
            'name' => $v->original_name,
            'mime_type' => $v->mime_type,
            'size_bytes' => $v->size_bytes,
            'is_image' => in_array($v->mime_type, MilestoneDeliveries::IMAGE_MIMES, true),
            'preview_only' => $this->previewOnly($v, $f, $c),
            'by_side' => $v->created_by_side,
            'created_at' => $v->created_at?->toIso8601String(),
            'path' => "projects/{$c->project->uuid}/workroom/files/{$f->uuid}/versions/{$v->number}/download",
        ];
    }

    /**
     * FL-L13 for the Workroom: the client gets only a small picture of a freelancer's version that is tied to a part which is not approved
     * yet, when the agreement carries rights terms. Files tied to no part, the client's own files and the freelancer's own view are plain.
     */
    public function previewOnly(Version $v, File $f, WorkroomContext $c): bool
    {
        if ($c->side !== Side::CLIENT || $v->created_by_side !== Side::FREELANCER || $f->milestone === null) {
            return false;
        }
        $f->milestone->setRelation('agreement', $c->agreement);

        return MilestoneDeliveries::previewOnly($f->milestone, Side::CLIENT);
    }

    // -------------------------------------------------------------- inside

    private function assertWriter(WorkroomContext $c, File $file): void
    {
        if ($file->created_by_side !== $c->side) {
            throw new FreelanceException('workroom_file_forbidden', 403);
        }
    }

    /** The project's limits: one more file (when a new one is added) and the bytes of this upload must still fit. */
    private function assertRoom(WorkroomContext $c, UploadedFile $upload, bool $newFile): void
    {
        $maxFiles = $this->limit($c, 'workroom.files_max');
        if ($newFile && $maxFiles !== null && File::query()->where('project_id', $c->project->id)->count() >= $maxFiles) {
            throw new FreelanceException('workroom_files_limit', 422, ['max' => $maxFiles], ['max' => $maxFiles]);
        }
        $maxMb = $this->limit($c, 'workroom.storage_mb');
        if ($maxMb !== null && $this->used($c) + (int) $upload->getSize() > $maxMb * 1048576) {
            throw new FreelanceException('workroom_storage_limit', 422, ['max_mb' => $maxMb], ['max_mb' => $maxMb]);
        }
    }

    private function limit(WorkroomContext $c, string $key): ?int
    {
        $value = $this->policies->for((int) $c->project->country_id, $c->project->freelance_specialty_id)->get($key);

        return $value === null ? null : (int) $value;
    }

    /** The bytes the project's files (the ones not deleted) hold now. */
    private function used(WorkroomContext $c): int
    {
        return (int) Version::query()->join('freelance_workroom_files as f', 'f.id', '=', 'freelance_workroom_file_versions.file_id')
            ->where('f.project_id', $c->project->id)->whereNull('f.deleted_at')->sum('freelance_workroom_file_versions.size_bytes');
    }

    /** Writes the next version and keeps the upload on the private disk, as it was. */
    private function store(WorkroomContext $c, File $file, UploadedFile $upload, ?string $note): Version
    {
        $number = $file->versions_count + 1;
        $version = Version::query()->create([
            'file_id' => $file->id,
            'number' => $number,
            'note' => $this->text($note, self::NOTE_MAX),
            'original_name' => $this->originalName($upload),
            'mime_type' => (string) ($upload->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $upload->getSize(),
            'created_by_side' => $c->side,
            'created_by_id' => (int) $c->actor->getKey(),
            'created_at' => now(),
        ]);
        $media = $version->addFile($upload);
        // What the library measured is what counts (the same bytes the download serves).
        $version->forceFill(['mime_type' => (string) $media->mime_type, 'size_bytes' => (int) $media->size])->save();
        $file->forceFill(['versions_count' => $number])->save();

        return $version;
    }

    private function title(?string $title, ?UploadedFile $upload): string
    {
        $text = $title === null ? '' : trim(strip_tags($title));
        if ($text === '' && $upload !== null) {
            $text = trim(strip_tags(pathinfo($this->originalName($upload), PATHINFO_FILENAME)));
        }
        if ($text === '') {
            throw new FreelanceException('workroom_title_required', 422);
        }

        return mb_substr($text, 0, self::TITLE_MAX);
    }

    private function text(?string $text, int $max): ?string
    {
        $text = $text === null ? '' : trim(strip_tags($text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }

    /** The name the person saw on their device: no folders, no markup, no control characters. */
    private function originalName(UploadedFile $upload): string
    {
        $name = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]/u', '', strip_tags($name)));

        return mb_substr($name !== '' ? $name : 'file', -255);
    }

    private function fresh(WorkroomContext $c, File $file): File
    {
        return $this->find($c, $file->uuid);
    }

    /** After the step is saved: tell the other side about a file or a version it can open, and the live screens of the project. */
    private function after(WorkroomContext $c, File $file, string $action, bool $shared = false): void
    {
        if ($shared && $file->scope === 'shared') {
            $c->agreement->loadMissing(['client', 'freelancer']);
            $receiver = $c->side === Side::CLIENT ? $c->agreement->freelancer : $c->agreement->client;
            if ($receiver !== null) {
                $this->notifier->notify($receiver, 'workroom.file_shared', ['project' => (string) $c->project->title, 'title' => $file->title], ['project' => $c->project->uuid, 'file' => $file->uuid]);
            }
        }
        WorkroomSupport::changed($c, 'file', $action, $file->uuid, $file->scope);
    }
}
