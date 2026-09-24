<?php

namespace Modules\Wallet\Enums;

enum WebhookInboxStatus: string
{
    case Received = 'received';
    case Processing = 'processing';
    case Processed = 'processed';
    case Failed = 'failed';
    case Ignored = 'ignored';
}
