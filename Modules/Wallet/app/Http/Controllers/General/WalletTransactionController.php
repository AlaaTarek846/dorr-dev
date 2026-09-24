<?php

namespace Modules\Wallet\Http\Controllers\General;

use App\Http\Controllers\Controller;
use App\Support\Api\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Wallet\Enums\WalletBucket;
use Modules\Wallet\Enums\WalletDirection;
use Modules\Wallet\Enums\WalletTransactionType;
use Modules\Wallet\Http\Resources\WalletTransactionResource;
use Modules\Wallet\Models\Wallet;
use Modules\Wallet\Services\WalletStatementService;
use Modules\Wallet\Support\OwnerType;

/**
 * The owner's statement for the wallet of the request's country — only ever
 * the authenticated owner's own rows, never selected by a client-supplied id.
 * An owner with no wallet yet simply has an empty statement.
 */
class WalletTransactionController extends Controller
{
    public function __construct(private readonly WalletStatementService $statements) {}

    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'type' => ['nullable', Rule::enum(WalletTransactionType::class)],
            'bucket' => ['nullable', Rule::enum(WalletBucket::class)],
            'direction' => ['nullable', Rule::enum(WalletDirection::class)],
        ]);

        $country = currentCountry();

        abort_if($country === null, 500, 'The country middleware did not run on this route.');

        $owner = $request->user();
        $wallet = Wallet::query()
            ->where('owner_type', OwnerType::aliasFor($owner))
            ->where('owner_id', $owner->getKey())
            ->where('country_id', $country->id)
            ->first();

        if ($wallet === null) {
            return ApiResponse::success([], __('api.retrieved'), 200, [
                'per_page' => 10, 'total' => 0, 'current_page' => 1, 'last_page' => 1, 'has_more_pages' => false,
            ]);
        }

        return ApiResponse::paginated(
            $this->statements->query($wallet, $filters),
            WalletTransactionResource::class,
            __('api.retrieved'),
        );
    }
}
