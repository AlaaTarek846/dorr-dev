<?php

namespace Modules\Wallet\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminPermissionMiddleware;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Validation\Rule;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletDirection;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Http\Requests\WalletAdjustmentRequest;
use Modules\Wallet\Http\Resources\WalletTransactionResource;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletAdminService;
use Modules\Wallet\Services\WalletStatementService;

class WalletController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly WalletAdminService $wallets,
        private readonly WalletStatementService $statements,
    ) {}

    /**
     * @return list<\Illuminate\Routing\Controllers\Middleware>
     */
    public static function middleware(): array
    {
        return AdminPermissionMiddleware::fromActionMethodMap('wallets', [
            ['view', ['index', 'show', 'transactions']],
            ['manual-adjustment', ['adjust']],
        ]);
    }

    public function index(Request $request)
    {
        return $this->wallets->list($request->validate([
            'owner_type' => ['nullable', 'in:user,provider'],
            'owner_id' => ['nullable', 'integer'],
            'country_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:100'],
        ]));
    }

    public function show(Wallet $wallet)
    {
        return $this->wallets->show($wallet);
    }

    public function transactions(Request $request, Wallet $wallet)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', Rule::enum(WalletTransactionType::class)],
            'bucket' => ['nullable', Rule::enum(WalletBucket::class)],
            'direction' => ['nullable', Rule::enum(WalletDirection::class)],
        ]);

        return ApiResponse::paginated(
            $this->statements->query($wallet, $filters),
            WalletTransactionResource::class,
            __('api.retrieved'),
        );
    }

    public function adjust(WalletAdjustmentRequest $request, Wallet $wallet)
    {
        $transaction = $this->wallets->adjust(
            $wallet,
            WalletDirection::from($request->validated('direction')),
            WalletBucket::from($request->validated('bucket')),
            (int) $request->validated('amount_minor'),
            $request->validated('reason'),
            (int) auth('admin_api')->id(),
        );

        return ApiResponse::created(new WalletTransactionResource($transaction), __('api.created'));
    }
}
