<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Modules\Wallet\Http\Resources\WalletTransactionResource;
use Modules\Wallet\Models\WalletTransaction;
use Modules\Wallet\Services\WalletStatementService;
use Modules\Wallet\Support\TransactionReferences;

/**
 * One row of the owner's own statement in full: the row, what it points at (a ride, when the module
 * that owns it can say), the other rows of the same operation (a payment and its commission…), and
 * whether it was reversed or is itself a reversal. A row that isn't the owner's is simply not found.
 */
class WalletTransactionDetailController extends Controller
{
    public function __construct(
        private readonly WalletStatementService $statements,
        private readonly TransactionReferences $references,
    ) {}

    public function __invoke(Request $request, string $uuid)
    {
        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        $owner = $request->user();
        $wallet = $this->statements->walletOf($owner, $country);
        $transaction = $wallet === null ? null : $this->statements->find($wallet, $uuid);

        if ($transaction === null) {
            return ApiResponse::error(__('wallet.errors.transaction_not_found'), 404);
        }

        return ApiResponse::success([
            ...(new WalletTransactionResource($transaction))->resolve($request),
            'reference' => $this->references->resolve($transaction, $owner),
            'related' => $this->statements->related($transaction)->map(fn (WalletTransaction $row) => [
                'uuid' => $row->uuid,
                'type' => $row->type->value,
                'type_label' => __('wallet.types.'.$row->type->value),
                'direction' => $row->direction->value,
                'bucket' => $row->bucket->value,
                'amount_minor' => $row->amount_minor,
                'created_at' => $row->created_at?->toISOString(),
            ])->values(),
            'reversed' => $transaction->isReversed(),
            'reverses_uuid' => $transaction->reverses?->uuid,
        ], __('api.retrieved'));
    }
}
