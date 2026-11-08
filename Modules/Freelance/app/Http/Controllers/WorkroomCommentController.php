<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceWorkroomComment as Comment;
use Modules\Freelance\Services\Workroom\WorkroomAccess;
use Modules\Freelance\Services\Workroom\WorkroomComments;
use Modules\Freelance\Services\Workroom\WorkroomContext;

/**
 * The Workroom's notes (FS-150b), one controller for both prefixes (`mobile/v1/freelance/projects/{uuid}/workroom/comments` and
 * `freelance/v1/projects/{uuid}/workroom/comments`): a note is on a file (`file`, optionally one of its versions) or on a delivery of a part
 * (`delivery`). The two sides read them and write them; the side that wrote a note edits and deletes it. A member of the client's team reads
 * (`view`) and, as a manager, writes (`workroom`) - see ClientTeamAbilities.
 */
class WorkroomCommentController extends Controller
{
    public function __construct(
        private readonly WorkroomAccess $access,
        private readonly WorkroomComments $comments,
    ) {}

    /** GET .../workroom/comments?file={uuid}[&version=N] or ?delivery={uuid}[&per_page=&page=] - oldest first. */
    public function index(Request $request, string $uuid): JsonResponse
    {
        $f = $request->validate([
            'file' => ['nullable', 'uuid', 'required_without:delivery', 'prohibits:delivery'],
            'delivery' => ['nullable', 'uuid', 'required_without:file'],
            'version' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $c = $this->access->context($request, $uuid);
        [$file, $delivery] = $this->subject($c, $f);
        $page = $this->comments->list($c, $file, $delivery, isset($f['version']) ? (int) $f['version'] : null, (int) ($f['per_page'] ?? 50));

        return ApiResponse::success([
            'comments' => $page->getCollection()->map(fn (Comment $m) => $this->comments->present($m, $c))->values(),
            'read_only' => ! $c->writable(),
            'can' => ['comment_add' => $c->writable()],
        ], __('api.retrieved'), 200, ApiPaginator::meta($page));
    }

    /** POST .../workroom/comments {file | delivery, version?, body} */
    public function store(Request $request, string $uuid): JsonResponse
    {
        $data = $request->validate([
            'file' => ['nullable', 'uuid', 'required_without:delivery', 'prohibits:delivery'],
            'delivery' => ['nullable', 'uuid', 'required_without:file'],
            'version' => ['nullable', 'integer', 'min:1'],
            'body' => ['required', 'string', 'max:'.WorkroomComments::BODY_MAX],
        ]);
        $c = $this->access->context($request, $uuid);
        [$file, $delivery] = $this->subject($c, $data);
        $comment = $this->comments->create($c, $file, $delivery, isset($data['version']) ? (int) $data['version'] : null, $data['body']);

        return ApiResponse::created(['comment' => $this->comments->present($comment, $c)], __('freelance.workroom_comment_created'));
    }

    /** PUT .../workroom/comments/{comment} {body} - the side that wrote it. */
    public function update(Request $request, string $uuid, string $comment): JsonResponse
    {
        $data = $request->validate(['body' => ['required', 'string', 'max:'.WorkroomComments::BODY_MAX]]);
        $c = $this->access->context($request, $uuid);
        $updated = $this->comments->update($c, $this->comments->find($c, $comment), $data['body']);

        return ApiResponse::success(['comment' => $this->comments->present($updated, $c)], __('freelance.workroom_comment_updated'));
    }

    /** DELETE .../workroom/comments/{comment} - the side that wrote it. */
    public function destroy(Request $request, string $uuid, string $comment): JsonResponse
    {
        $c = $this->access->context($request, $uuid);
        $this->comments->delete($c, $this->comments->find($c, $comment));

        return ApiResponse::success(null, __('freelance.workroom_comment_deleted'));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: ?\Modules\Freelance\Models\FreelanceWorkroomFile, 1: ?\Modules\Freelance\Models\FreelanceDelivery}
     */
    private function subject(WorkroomContext $c, array $data): array
    {
        if (! empty($data['file'])) {
            return [$this->comments->file($c, $data['file']), null];
        }

        return [null, $this->comments->delivery($c, (string) $data['delivery'])];
    }
}
