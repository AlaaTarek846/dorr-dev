<?php

namespace Modules\Freelance\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\FreelanceChecklistTemplate;

/** GET checklist-templates[?specialty=id] - the ready-made checklists a client can start a part's checklist from (FS-105). */
class ChecklistTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $specialty = $request->integer('specialty') ?: null;
        $rows = FreelanceChecklistTemplate::query()->with('translations')->where('status', true)
            ->when($specialty !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('specialty_id')->orWhere('specialty_id', $specialty)))
            ->orderBy('sort_order')->orderBy('id')->get();

        return ApiResponse::success($rows->map(fn (FreelanceChecklistTemplate $t) => [
            'code' => $t->code,
            'name' => $t->translated('name'),
            'specialty_id' => $t->specialty_id,
            'items' => $t->checks(),
        ])->values(), __('api.retrieved'));
    }
}