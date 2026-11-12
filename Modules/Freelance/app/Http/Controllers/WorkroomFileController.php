<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Exceptions\FreelanceException;
use Modules\Freelance\Models\FreelanceWorkroomFile as File;
use Modules\Freelance\Rules\CleanUpload;
use Modules\Freelance\Services\Projects\MilestoneDeliveries;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\Freelance\Services\Workroom\WorkroomContext;
use Modules\Freelance\Services\Workroom\WorkroomFiles;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Workroom's File Hub (FS-150b), one controller for both prefixes (the client's `mobile/v1/freelance/projects/{uuid}/workroom/files` and
 * the freelancer's `freelance/v1/projects/{uuid}/workroom/files`): the two sides of the agreement read and open the files; either side adds a
 * file; the side that added it renames it, adds its next versions and deletes it. A member of the client's team reads (`view`) and, as a
 * manager, works on them (`workroom`) - see ClientTeamAbilities. Every upload is checked like a delivery's: size, type, and a scan of the bytes.
 */
class WorkroomFileController extends Controller
{
    public function __construct(
        private readonly WorkroomAccess $access,
        private readonly WorkroomFiles $files,
    ) {}

    /** GET .../workroom/files[?milestone=&mine=1&search=&per_page=&page=] */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $f = $request->validate([
            'milestone' => ['nullable', 'uuid'],
            'mine' => ['nullable', 'boolean'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $c = $this->access->context($request, $uuid);
        $page = $this->files->list($c, $f, (int) ($f['per_page'] ?? 30));

        return ApiResponse::success([
            'files' => $page->getCollection()->map(fn (File $file) => $this->files->present($file, $c))->values(),
            'summary' => $this->files->summary($c),
            'limit' => $this->files->limits($c),
            'upload' => ['max_kb' => (int) config('freelance.upload_max_kb', 20480), 'mimes' => explode(',', MilestoneDeliveries::MIMES), 'max_versions' => WorkroomFiles::MAX_VERSIONS],
            'read_only' => ! $c->writable(),
            'can' => ['file_upload' => $c->writable()],
        ], __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** GET .../workroom/files/{file} - the file with all its versions. */
    public function show(Request $request, string $uuid, string $file): JsonResponse
    {
        $c = $this->access->context($request, $uuid);

        return ApiResponse::success(['file' => $this->files->present($this->files->find($c, $file), $c, true)], __('api.retrieved'));
    }

    /** POST .../workroom/files (multipart) {file, title?, description?, milestone?, note?} - the first version. */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $c = $this->access->context($request, $uuid);
        $data = $request->validate([
            'file' => $this->uploadRules(),
            'title' => ['nullable', 'string', 'max:'.WorkroomFiles::TITLE_MAX],
            'description' => ['nullable', 'string', 'max:'.WorkroomFiles::DESCRIPTION_MAX],
            'milestone' => ['nullable', 'uuid'],
            'note' => ['nullable', 'string', 'max:'.WorkroomFiles::NOTE_MAX],
            'scope' => ['nullable', 'in:shared,team'],
        ]);
        $created = $this->files->create($c, $data, $request->file('file'));

        return ApiResponse::created($this->answer($c, $created), __('freelance.workroom_file_created'));
    }

    /** PUT .../workroom/files/{file} - any of title, description, milestone (the side that added the file only). */
    public function update(Request $request, string $uuid, string $file): JsonResponse
    {
        $c = $this->access->context($request, $uuid);
        $data = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:'.WorkroomFiles::TITLE_MAX],
            'description' => ['sometimes', 'nullable', 'string', 'max:'.WorkroomFiles::DESCRIPTION_MAX],
            'milestone' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $updated = $this->files->update($c, $this->files->find($c, $file), $data);

        return ApiResponse::success($this->answer($c, $updated), __('freelance.workroom_file_updated'));
    }

    /** POST .../workroom/files/{file}/versions (multipart) {file, note?} - the next version (the side that added the file only). */
    public function addVersion(Request $request, string $uuid, string $file): JsonResponse
    {
        $c = $this->access->context($request, $uuid);
        $data = $request->validate([
            'file' => $this->uploadRules(),
            'note' => ['nullable', 'string', 'max:'.WorkroomFiles::NOTE_MAX],
        ]);
        $updated = $this->files->addVersion($c, $this->files->find($c, $file), $data['note'] ?? null, $request->file('file'));

        return ApiResponse::created($this->answer($c, $updated), __('freelance.workroom_version_added'));
    }

    /** DELETE .../workroom/files/{file} - the side that added it. */
    public function destroy(Request $request, string $uuid, string $file): JsonResponse
    {
        $c = $this->access->context($request, $uuid);
        $this->files->delete($c, $this->files->find($c, $file));

        return ApiResponse::success(['summary' => $this->files->summary($c)], __('freelance.workroom_file_deleted'));
    }

    /**
     * GET .../workroom/files/{file}/versions/{number}/download - an image is shown in place, anything else is a download; nothing is ever opened
     * or run by the server, and the browser is told not to guess the type. A preview-only version (see WorkroomFiles) is a small picture.
     */
    public function download(Request $request, string $uuid, string $file, int $number): BinaryFileResponse|\Illuminate\Http\Response
    {
        $c = $this->access->context($request, $uuid);
        $f = $this->files->find($c, $file);
        $v = $this->files->version($f, $number);
        $media = $v->stored() ?? abort(404);
        $image = in_array($media->mime_type, MilestoneDeliveries::IMAGE_MIMES, true);

        if ($this->files->previewOnly($v, $f, $c)) {
            $preview = $image ? MilestoneDeliveries::preview($media) : null;
            if ($preview === null) {
                throw new FreelanceException('rights_preview_only', 403);
            }

            return response($preview[0], 200, ['Content-Type' => $preview[1], 'Content-Disposition' => 'inline', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store']);
        }
        $response = response()->download($media->getPath(), $v->original_name, ['Content-Type' => $media->mime_type, 'X-Content-Type-Options' => 'nosniff'], $image ? 'inline' : 'attachment');

        return tap($response, fn ($r) => $r->setPrivate()->headers->addCacheControlDirective('no-store'));
    }

    /** The same limits and scan as a delivery's file. @return list<mixed> */
    private function uploadRules(): array
    {
        return ['required', 'file', 'max:'.(int) config('freelance.upload_max_kb', 20480), 'mimes:'.MilestoneDeliveries::MIMES, new CleanUpload];
    }

    /** @return array<string, mixed> */
    private function answer(WorkroomContext $c, File $file): array
    {
        return ['file' => $this->files->present($file, $c, true), 'summary' => $this->files->summary($c)];
    }
}
