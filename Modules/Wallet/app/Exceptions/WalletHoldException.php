<?php

namespace Modules\Wallet\Exceptions;

use RuntimeException;

/**
 * Capturing/releasing a hold that isn't `active` anymore, or capturing more
 * than was originally held.
 */
class WalletHoldException extends RuntimeException {}
