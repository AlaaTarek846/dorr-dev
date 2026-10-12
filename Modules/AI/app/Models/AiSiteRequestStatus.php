<?php

namespace Modules\AI\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The labels/colours/order of request statuses, edited from the dashboard. The codes in SYSTEM drive the workflow
 * (quote, countdown, instalments) so they can be renamed and recoloured but not removed; extra codes are free labels.
 */
class AiSiteRequestStatus extends Model
{
    public const SUBMITTED = 'submitted';

    public const UNDER_REVIEW = 'under_review';

    public const QUOTE_SENT = 'quote_sent';

    public const QUOTE_DECLINED = 'quote_declined';

    public const IN_PROGRESS = 'in_progress';

    public const ON_HOLD = 'on_hold';

    public const DELIVERED = 'delivered';

    public const COMPLETED = 'completed';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Finished states: nothing more happens to the request. */
    public const FINAL = [self::COMPLETED, self::REJECTED, self::CANCELLED];

    public const DEFAULTS = [
        [self::SUBMITTED, 'تم الاستلام', 'Received', 'info'],
        [self::UNDER_REVIEW, 'قيد المراجعة', 'Under review', 'warning'],
        [self::QUOTE_SENT, 'تم إرسال العرض', 'Quote sent', 'primary'],
        [self::QUOTE_DECLINED, 'تم رفض العرض', 'Quote declined', 'danger'],
        [self::IN_PROGRESS, 'جاري التنفيذ', 'In progress', 'primary'],
        [self::ON_HOLD, 'متوقف مؤقتاً', 'On hold', 'warning'],
        [self::DELIVERED, 'تم التسليم للمراجعة', 'Delivered for review', 'success'],
        [self::COMPLETED, 'مكتمل', 'Completed', 'success'],
        [self::REJECTED, 'مرفوض', 'Rejected', 'danger'],
        [self::CANCELLED, 'ملغي', 'Cancelled', 'secondary'],
    ];

    protected $table = 'ai_site_request_statuses';

    protected $fillable = ['code', 'name_ar', 'name_en', 'color', 'sort_order', 'is_active', 'is_system'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean', 'sort_order' => 'integer'];
    }

    public function label(): string
    {
        return app()->getLocale() === 'ar' ? $this->name_ar : $this->name_en;
    }

    /** Creates the built-in statuses when the table is empty, so a fresh install works without seeding. */
    public static function ensureDefaults(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $i => [$code, $ar, $en, $color]) {
            static::query()->create(['code' => $code, 'name_ar' => $ar, 'name_en' => $en, 'color' => $color, 'sort_order' => ($i + 1) * 10, 'is_active' => true, 'is_system' => true]);
        }
    }

    /** @return array<string, self> keyed by code */
    public static function map(): array
    {
        static::ensureDefaults();

        return static::query()->orderBy('sort_order')->get()->keyBy('code')->all();
    }
}
