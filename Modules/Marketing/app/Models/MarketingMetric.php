<?php

namespace Modules\Marketing\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One day of results on one channel (U11). `basis` keeps what was measured apart from what was guessed (spec 08
 * step 11): `imported` = copied from the platform's own report (by hand or a file), `estimated` = the person's estimate,
 * `actual` = read from a connected platform (phase C). A missing number stays null — never 0.
 */
class MarketingMetric extends Model
{
    public const BASES = ['imported', 'estimated'];

    public const MEASURED = ['imported', 'actual'];

    public const FIELDS = ['impressions', 'clicks', 'spend_minor', 'conversions', 'revenue_minor'];

    protected $fillable = [
        'project_id', 'content_item_id', 'campaign_id', 'channel', 'metric_date', 'source', 'basis', 'row_key',
        'impressions', 'clicks', 'spend_minor', 'conversions', 'revenue_minor', 'currency', 'note', 'import_batch',
        'entered_by_type', 'entered_by_id',
    ];

    protected function casts(): array
    {
        return [
            'metric_date' => 'date',
            'impressions' => 'integer',
            'clicks' => 'integer',
            'spend_minor' => 'integer',
            'conversions' => 'integer',
            'revenue_minor' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(MarketingProject::class, 'project_id');
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(MarketingContentItem::class, 'content_item_id');
    }

    public static function key(string $channel, string $date, string $basis, ?int $contentId, ?int $campaignId = null): string
    {
        return implode('|', [$channel, $date, $basis, $contentId ?? '-', $campaignId ?? '-']);
    }
}
