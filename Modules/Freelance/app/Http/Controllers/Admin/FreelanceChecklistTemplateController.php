<?php

namespace Modules\Freelance\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\General\AuditLogger;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Validation\Rule;
use Modules\Freelance\Models\FreelanceChecklistTemplate;
use Modules\Freelance\Models\FreelanceSpecialty;
use Modules\Freelance\Services\Projects\MilestoneDeliveries;

/**
 * Admin → Freelance → Checklist templates (FS-105): the ready-made lists of what a client checks before approving a part, by kind of
 * project, in Arabic and English (one check per line, at most as many as a part's checklist takes).
 */
class FreelanceChecklistTemplateController extends Controller implements HasMiddleware
{
    /** @return list<Middleware> */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('freelance-checklist-templates', [
            ['view', ['index', 'options']],
            ['create', ['store']],
            ['update', ['update', 'status']],
            ['delete', ['destroy']],
        ]);
    }

    public function index(): JsonResponse
    {
        $rows = FreelanceChecklistTemplate::query()->with(['translations', 'specialty.translations'])->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($rows->map(fn (FreelanceChecklistTemplate $t) => $this->present($t))->values(), __('api.retrieved'));
    }

    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'specialties' => FreelanceSpecialty::query()->with('translations')->where('status', true)->get()
                ->map(fn (FreelanceSpecialty $s) => ['id' => $s->id, 'name' => $s->translatedName()])->values(),
            'max_checks' => MilestoneDeliveries::MAX_CHECKS,
        ], __('api.retrieved'));
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $t = new FreelanceChecklistTemplate(collect($data)->only(['code', 'specialty_id', 'status', 'sort_order'])->all());
        $t->fillAllTranslations($this->translations($data))->save();
        $audit->record('freelance_checklist_template.created', $t, null, $data);

        return ApiResponse::success($this->present($t->fresh(['translations', 'specialty.translations'])), __('api.created'), 201);
    }

    public function update(Request $request, FreelanceChecklistTemplate $template, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request, $template);
        $before = $this->present($template->load(['translations', 'specialty.translations']));
        $template->fill(collect($data)->only(['code', 'specialty_id', 'status', 'sort_order'])->all());
        $template->fillAllTranslations($this->translations($data))->save();
        $after = $this->present($template->fresh(['translations', 'specialty.translations']));
        $audit->record('freelance_checklist_template.updated', $template, $before, $after);

        return ApiResponse::success($after, __('api.updated'));
    }

    public function status(Request $request, FreelanceChecklistTemplate $template, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', 'boolean']]);
        $template->update(['status' => (bool) $data['status']]);
        $audit->record('freelance_checklist_template.status', $template, null, ['status' => (bool) $data['status']]);

        return ApiResponse::success($this->present($template->fresh(['translations', 'specialty.translations'])), __('api.updated'));
    }

    public function destroy(FreelanceChecklistTemplate $template, AuditLogger $audit): JsonResponse
    {
        $template->delete();
        $audit->record('freelance_checklist_template.deleted', $template);

        return ApiResponse::success(null, __('api.deleted'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?FreelanceChecklistTemplate $t = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/', Rule::unique('freelance_checklist_templates', 'code')->ignore($t?->id)->whereNull('deleted_at')],
            'specialty_id' => ['nullable', 'integer', Rule::exists('freelance_specialties', 'id')],
            'status' => ['boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'name' => ['required', 'array'],
            'name.ar' => ['required', 'string', 'max:191'],
            'name.en' => ['required', 'string', 'max:191'],
            'items' => ['required', 'array'],
            'items.ar' => ['required', 'array', 'min:1', 'max:'.MilestoneDeliveries::MAX_CHECKS],
            'items.en' => ['required', 'array', 'min:1', 'max:'.MilestoneDeliveries::MAX_CHECKS],
            'items.ar.*' => ['required', 'string', 'max:200'],
            'items.en.*' => ['required', 'string', 'max:200'],
        ]);
        $data['sort_order'] = $data['sort_order'] ?? 0;

        return $data;
    }

    /** @param  array<string, mixed>  $data  @return array<string, array<string, string>> */
    private function translations(array $data): array
    {
        return [
            'name' => ['ar' => $data['name']['ar'], 'en' => $data['name']['en']],
            'items' => collect(['ar', 'en'])->mapWithKeys(fn ($l) => [$l => implode("\n", array_map(fn ($i) => trim(str_replace(["\r", "\n"], ' ', (string) $i)), $data['items'][$l]))])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(FreelanceChecklistTemplate $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->translated('name'),
            'names' => $t->translations->mapWithKeys(fn ($r) => [$r->locale => $r->name])->all(),
            'items' => $t->translations->mapWithKeys(fn ($r) => [$r->locale => FreelanceChecklistTemplate::split((string) $r->items)])->all(),
            'specialty_id' => $t->specialty_id,
            'specialty' => $t->specialty?->translatedName(),
            'status' => (bool) $t->status,
            'sort_order' => $t->sort_order,
        ];
    }
}