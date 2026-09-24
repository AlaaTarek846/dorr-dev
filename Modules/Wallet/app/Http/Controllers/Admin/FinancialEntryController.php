<?php

namespace Modules\Wallet\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Wallet\Services\FinancialEntryQueryService;

/**
 * Read-only view of the platform's income/expense ledger ("financial ledger
 * read"). There is deliberately no create/update/delete here: entries are
 * only ever written by the wallet flows that cause them.
 */
class FinancialEntryController extends Controller implements HasMiddleware
{
    public function __construct(private readonly FinancialEntryQueryService $entries) {}

    /**
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('financial-entries', [
            ['view', ['index', 'summary']],
        ]);
    }

    public function index(Request $request)
    {
        return $this->entries->list($this->filters($request));
    }

    public function summary(Request $request)
    {
        return ApiResponse::success($this->entries->summary($this->filters($request)), __('api.retrieved'));
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'type' => ['nullable', 'in:income,expense'],
            'category' => ['nullable', 'string', 'max:64'],
            'country_id' => ['nullable', 'integer'],
            'currency_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);
    }
}
