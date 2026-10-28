<?php

namespace Modules\Freelance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiPaginator;
use App\Support\Api\ApiResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Freelance\Models\Freelancer;
use Modules\Freelance\Services\Projects\FreelanceReceipts;

/**
 * Receipts of the project money (FL-511), each side its own: the client at `mobile/v1/freelance/receipts`, the freelancer at
 * `freelance/v1/receipts`. GET receipts (newest first), GET receipts/{uuid} (with the text to share).
 */
class ReceiptController extends Controller
{
    public function __construct(private readonly FreelanceReceipts $receipts) {}

    public function index(Request $request): JsonResponse
    {
        [$me, $side] = $this->me($request);

        $request->validate(['project' => ['nullable', 'uuid']]);
        $page = $this->receipts->list($me, $side, ApiPaginator::perPage(), $request->input('project'));

        return ApiResponse::fromPaginator($page, $page->getCollection()->values(), __('api.retrieved'));
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        [$me, $side] = $this->me($request);

        return ApiResponse::success($this->receipts->present($this->receipts->find($me, $side, $uuid)), __('api.retrieved'));
    }

    /** @return array{0: Model, 1: 'client'|'freelancer'} */
    private function me(Request $request): array
    {
        $freelancer = $request->user('freelance_api');

        return $freelancer instanceof Freelancer ? [$freelancer, 'freelancer'] : [$request->user('user_api'), 'client'];
    }
}
