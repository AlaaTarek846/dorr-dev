<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** One attempt to act on a platform, keyed by the caller's Idempotency-Key (POL-07, AT-14). */
class MarketingCampaignAction extends Model
{
    public const SUBMITTING = 'SUBMITTING';

    public const SUCCEEDED = 'SUCCEEDED';

    public const FAILED = 'FAILED';

    public const UNKNOWN = 'UNKNOWN';

    protected $fillable = [
        'uuid', 'project_id', 'campaign_id', 'action_type', 'idempotency_key', 'payload_hash', 'campaign_version', 'approval_id', 'marker',
        'request_json', 'response_json', 'status', 'external_ref', 'error_code', 'actor_type', 'actor_id',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $action) {
            $action->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['request_json' => 'array', 'response_json' => 'array', 'campaign_version' => 'integer'];
    }
}
