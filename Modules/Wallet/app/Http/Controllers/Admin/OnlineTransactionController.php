<?php

namespace Modules\Wallet\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Modules\Wallet\Http\Resources\OnlineTransactionResource;
use Modules\Wallet\Models\PaymentTransaction;
use Modules\Wallet\Services\OnlineTransactionService;
use Modules\Wallet\Services\PaymentReconciliationService;
use Modules\Wallet\Services\PaymentRefundService;

/**
 * Admin "Online Transactions": what customers paid through gateways, the full
 * request/response history of each attempt, and the two corrective actions —
 * reconcile (ask the gateway) and refund (reverse the wallet credit).
 */
class OnlineTransactionController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly OnlineTransactionService $transactions,
        private readonly PaymentReconciliationService $reconciliation,
        private readonly PaymentRefundService $refunds,
    ) {}

    /**
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('online-transactions', [
            ['view', ['index', 'summary', 'show']],
            ['reconcile', ['reconcile']],
            ['refund', ['refund']],
        ]);
    }

    public function index(Request $request)
    {
        return $this->transactions->list($this->filters($request));
    }

    public function summary(Request $request)
    {
        return ApiResponse::success($this->transactions->summary($this->filters($request)), __('api.retrieved'));
    }

    public function show(int $online_transaction)
    {
        return ApiResponse::success(
            new OnlineTransactionResource($this->transactions->show($online_transaction)),
            __('api.retrieved'),
        );
    }

    public function reconcile(int $online_transaction)
    {
        $this->reconciliation->reconcile(PaymentTransaction::query()->with('paymentMethod')->findOrFail($online_transaction), $this->admin());

        return $this->show($online_transaction);
    }

    public function refund(int $online_transaction)
    {
        $this->refunds->refund(PaymentTransaction::query()->with('paymentMethod')->findOrFail($online_transaction), $this->admin());

        return $this->show($online_transaction);
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'in:pending,paid,failed,expired,refunded'],
            'payment_method_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'owner_type' => ['nullable', 'in:user,provider'],
            'owner_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
    }

    /**
     * @return array{type: string, id: int|null}
     */
    private function admin(): array
    {
        return ['type' => 'admin', 'id' => auth('admin_api')->id()];
    }
}
