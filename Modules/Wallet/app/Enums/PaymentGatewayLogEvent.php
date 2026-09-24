<?php

namespace Modules\Wallet\Enums;

enum PaymentGatewayLogEvent: string
{
    case Initiate = 'initiate';
    case Webhook = 'webhook';
    case RedirectVerify = 'redirect_verify';
    case ManualReconcile = 'manual_reconcile';
    case RefundAttempt = 'refund_attempt';
    case OtpExecute = 'otp_execute';
}
