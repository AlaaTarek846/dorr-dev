<?php

namespace Modules\User\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Modules\User\Models\SupportQuickReply;

/**
 * Dashboard → Support → Quick replies: the ready answers agents drop into a ticket with "/" + a shortcut,
 * with a title and a text per language.
 */
class SupportQuickReplyController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('support-quick-replies', [
            ['view', ['index', 'show']],
            ['create', ['store']],
            ['update', ['update']],
            ['change-status', ['status']],
            ['delete', ['destroy']],
            ['multiple-delete', ['deleteMultiple']],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $status = (string) $request->query('status', 'all');

        $paginator = SupportQuickReply::query()->with('translations')
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q
                ->where('shortcut', 'like', '%'.ltrim($search, '/').'%')
                ->orWhereHas('translations', fn ($t) => $t->where('title', 'like', "%{$search}%")->orWhere('body', 'like', "%{$search}%"))))
            ->when(in_array($status, ['active', 'inactive'], true), fn ($query) => $query->where('status', $status === 'active'))
            ->orderBy('sort_order')->orderBy('id')
            ->paginate(min(max((int) $request->query('per_page', 15), 1), 100));

        // The All / Active / Inactive tabs ride on the list (`?status_counts=1`), counted over the whole table.
        $meta = null;

        if ($request->boolean('status_counts')) {
            $total = SupportQuickReply::query()->count();
            $active = SupportQuickReply::query()->where('status', true)->count();
            $meta = ['status_counts' => ['total' => $total, 'active' => $active, 'inactive' => $total - $active]];
        }

        return ApiResponse::success(
            $paginator->getCollection()->map(fn (SupportQuickReply $reply) => $this->present($reply))->values(),
            __('api.retrieved'),
            200,
            ApiPaginator::meta($paginator),
            $meta,
        );
    }

    public function deleteMultiple(Request $request): JsonResponse
    {
        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:support_quick_replies,id'],
        ])['ids'];

        SupportQuickReply::query()->whereIn('id', $ids)->delete();

        return ApiResponse::noContent(__('api.deleted'));
    }

    public function show(SupportQuickReply $supportQuickReply): JsonResponse
    {
        return ApiResponse::success($this->present($supportQuickReply->load('translations')), __('api.retrieved'));
    }

    public function store(Request $request): JsonResponse
    {
        $reply = $this->save(new SupportQuickReply, $this->validated($request));

        return ApiResponse::created($this->present($reply), __('api.created'));
    }

    public function update(Request $request, SupportQuickReply $supportQuickReply): JsonResponse
    {
        return ApiResponse::success($this->present($this->save($supportQuickReply, $this->validated($request, $supportQuickReply))), __('api.updated'));
    }

    public function status(Request $request, SupportQuickReply $supportQuickReply): JsonResponse
    {
        $supportQuickReply->update($request->validate(['status' => ['required', 'boolean']]));

        return ApiResponse::success($this->present($supportQuickReply->load('translations')), __('api.updated'));
    }

    public function destroy(SupportQuickReply $supportQuickReply): JsonResponse
    {
        $supportQuickReply->delete();

        return ApiResponse::noContent(__('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SupportQuickReply $current = null): array
    {
        // "/refund" and "refund" are the same shortcut: the slash is how agents type it, not part of it.
        if ($request->has('shortcut')) {
            $request->merge(['shortcut' => mb_strtolower(ltrim(trim((string) $request->input('shortcut')), '/'))]);
        }

        return $request->validate([
            'shortcut' => ['required', 'string', 'max:40', 'regex:/^[\p{L}\p{N}_-]+$/u', Rule::unique('support_quick_replies', 'shortcut')->ignore($current?->id)],
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.title' => ['required', 'string', 'max:120'],
            'translations.*.body' => ['required', 'string', 'max:4000'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
            'status' => ['sometimes', 'boolean'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(SupportQuickReply $reply, array $data): SupportQuickReply
    {
        DB::transaction(function () use ($reply, $data) {
            $reply->fill(collect($data)->except('translations')->all())->save();

            foreach ($data['translations'] as $row) {
                $reply->translations()->updateOrCreate(
                    ['locale' => $row['locale']],
                    ['title' => trim($row['title']), 'body' => trim($row['body'])],
                );
            }
        });

        return $reply->load('translations');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(SupportQuickReply $reply): array
    {
        return [
            'id' => $reply->id,
            'shortcut' => $reply->shortcut,
            'title' => $reply->translated('title'),
            'body' => $reply->translated('body'),
            'sort_order' => $reply->sort_order,
            'created_at' => $reply->created_at,
            'updated_at' => $reply->updated_at,
            'status' => $reply->status,
            'translations' => $reply->translations->map(fn ($t) => ['locale' => $t->locale, 'title' => $t->title, 'body' => $t->body])->values(),
        ];
    }
}
