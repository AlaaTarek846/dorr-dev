<?php

namespace Modules\Chat\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\DB;
use Modules\Chat\Models\ChatReportType;

/**
 * The reasons people pick when reporting a chat, named per language.
 */
class ChatReportTypeController extends Controller implements HasMiddleware
{
    /**
     * @return list<Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('chat-report-types', [
            ['view', ['index', 'show', 'dropdown']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(Request $request)
    {
        $rows = ChatReportType::query()->with('translations')->withCount('reports')
            ->when($request->filled('search'), fn ($q) => $q->whereHas('translations', fn ($t) => $t->where('name', 'like', '%'.$request->string('search').'%')))
            ->orderBy('sort_order')->orderBy('id')
            ->get()
            ->map(fn (ChatReportType $type) => $this->present($type));

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function dropdown()
    {
        $rows = ChatReportType::query()->with('translations')->orderBy('sort_order')->orderBy('id')->get()
            ->map(fn (ChatReportType $type) => ['id' => $type->id, 'name' => $type->translatedName()]);

        return ApiResponse::success($rows, __('api.retrieved'));
    }

    public function show(ChatReportType $chatReportType)
    {
        return ApiResponse::success($this->present($chatReportType->load('translations')->loadCount('reports')), __('api.retrieved'));
    }

    public function store(Request $request)
    {
        $type = $this->save(new ChatReportType, $this->validated($request));

        return ApiResponse::created($this->present($type), __('api.created'));
    }

    public function update(Request $request, ChatReportType $chatReportType)
    {
        return ApiResponse::success($this->present($this->save($chatReportType, $this->validated($request))), __('api.updated'));
    }

    public function status(Request $request, ChatReportType $chatReportType)
    {
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $chatReportType->update($data);

        return ApiResponse::success($this->present($chatReportType->load('translations')->loadCount('reports')), __('api.updated'));
    }

    /**
     * Past reports keep their record; they just lose the reason (report_type_id → null).
     */
    public function destroy(ChatReportType $chatReportType)
    {
        $chatReportType->delete();

        return ApiResponse::success(null, __('api.deleted'));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'translations' => ['required', 'array', 'min:1'],
            'translations.*.locale' => ['required', 'string', 'max:10'],
            'translations.*.name' => ['required', 'string', 'max:150'],
            'status' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:100000'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(ChatReportType $type, array $data): ChatReportType
    {
        DB::transaction(function () use ($type, $data) {
            $type->fill(collect($data)->except('translations')->all())->save();

            foreach ($data['translations'] as $row) {
                $type->translations()->updateOrCreate(['locale' => $row['locale']], ['name' => $row['name']]);
            }
        });

        return $type->load('translations')->loadCount('reports');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ChatReportType $type): array
    {
        return [
            'id' => $type->id,
            'name' => $type->translatedName(),
            'status' => $type->status,
            'sort_order' => $type->sort_order,
            'reports_count' => $type->reports_count ?? 0,
            'translations' => $type->translations->map(fn ($t) => ['locale' => $t->locale, 'name' => $t->name])->values(),
        ];
    }
}
