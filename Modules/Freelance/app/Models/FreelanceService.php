<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A freelancer's ready-made service (FL-L04): a fixed price in up to three levels with paid extras, bought without offers. Its status
 * only moves through FreelanceServices. The images live on the private disk and go out as links that expire, like the portfolio's.
 * Deleted softly, so the orders placed on it keep pointing at it.
 */
class FreelanceService extends Model implements HasMedia
{
    use HasMediaTrait;
    use SoftDeletes;

    public const DISK = 'local';

    public const DRAFT = 'draft';

    public const PENDING = 'pending_review';

    public const PUBLISHED = 'published';

    public const PAUSED = 'paused';

    public const REJECTED = 'rejected';

    /** Stopped by the staff: only they bring it back. */
    public const SUSPENDED = 'suspended';

    public const STATUSES = [self::DRAFT, self::PENDING, self::PUBLISHED, self::PAUSED, self::REJECTED, self::SUSPENDED];

    /** FL-L05: sold once, or by the month (every month an order of its own). */
    public const ONCE = 'once';

    public const MONTHLY = 'monthly';

    public const BILLINGS = [self::ONCE, self::MONTHLY];

    /** Cheapest first: a higher level always costs more. */
    public const LEVELS = ['basic', 'standard', 'premium'];

    protected $fillable = [
        'uuid', 'freelancer_id', 'country_id', 'freelance_specialty_id', 'title', 'description', 'requirements', 'rights', 'billing', 'currency_code', 'status',
        'status_reason', 'submitted_at', 'published_at', 'reviewed_at', 'reviewed_by',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceService $s) => $s->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'published_at' => 'datetime', 'reviewed_at' => 'datetime', 'rights' => 'array'];
    }

    protected function webpSkipCollections(): array
    {
        return ['images'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(FreelanceSpecialty::class, 'freelance_specialty_id');
    }

    /** Cheapest first. */
    public function tiers(): HasMany
    {
        return $this->hasMany(FreelanceServiceTier::class, 'service_id')->orderByRaw("case level when 'basic' then 1 when 'standard' then 2 else 3 end");
    }

    public function addons(): HasMany
    {
        return $this->hasMany(FreelanceServiceAddon::class, 'service_id')->orderBy('sort_order')->orderBy('id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(FreelanceServiceOrder::class, 'service_id');
    }

    public function monthly(): bool
    {
        return $this->billing === self::MONTHLY;
    }

    /** Clients can find and order it. */
    public function live(): bool
    {
        return $this->status === self::PUBLISHED && $this->deleted_at === null;
    }

    public function addImage(UploadedFile $file): Media
    {
        return $this->addUploadedMedia($file, 'images', (string) (($this->getMedia('images')->max('order_column') ?? 0) + 1))->toMediaCollection('images', self::DISK);
    }
}
