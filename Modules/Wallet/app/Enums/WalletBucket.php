<?php

namespace Modules\Wallet\Enums;

/**
 * The withdrawable/spend_only flag — docs/wallet-plan.md §10 (🔴 most
 * important invariant in the whole wallet design). Every wallet_transactions
 * and wallet_holds row must carry one of these explicitly; there's no
 * default on purpose.
 */
enum WalletBucket: string
{
    case Withdrawable = 'withdrawable';
    case SpendOnly = 'spend_only';
    // Watch & Earn rewards (docs/watch_earn_tasks.md D2): spent in the app only — never withdrawn, never sent to
    // anyone, never expires.
    case Reward = 'reward';
}
