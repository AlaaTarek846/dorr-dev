<?php

namespace Modules\Freelance\Models;

use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * One work in a freelancer's portfolio (FL-105). Its images live on the private disk and are only ever handed out through
 * the owner's signed-in endpoint or a short-lived signed link — never a permanent public URL.
 */
class FreelancerPortfolioItem extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const PUBLIC = 'public';

    public const CLIENTS_ONLY = 'clients_only';

    public const PRIVATE = 'private';

    public const VISIBILITIES = [self::PUBLIC, self::CLIENTS_ONLY, self::PRIVATE];

    protected $fillable = [
        'uuid', 'freelancer_id', 'title', 'description', 'completed_on', 'freelance_specialty_id', 'visibility', 'rights_confirmed_at',
        'external_url', 'video_provider', 'video_id', 'sort_order', 'hidden_by_admin_at', 'hidden_reason', 'hidden_by_admin_id',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelancerPortfolioItem $i) => $i->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['completed_on' => 'date', 'rights_confirmed_at' => 'datetime', 'hidden_by_admin_at' => 'datetime', 'sort_order' => 'integer'];
    }

    protected function webpSkipCollections(): array
    {
        return ['images'];
    }

    public function freelancer(): BelongsTo
    {
        return $this->belongsTo(Freelancer::class)->withTrashed();
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(FreelanceSpecialty::class, 'freelance_specialty_id');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(FreelanceSkill::class, 'freelancer_portfolio_item_skills', 'portfolio_item_id', 'freelance_skill_id');
    }

    public function isHidden(): bool
    {
        return $this->hidden_by_admin_at !== null;
    }

    public function addImage(UploadedFile $file): Media
    {
        return $this->addUploadedMedia($file, 'images', (string) (($this->getMedia('images')->max('order_column') ?? 0) + 1))->toMediaCollection('images', self::DISK);
    }

    /** The embed address of the video (a fixed shape per provider, never the link the freelancer typed). */
    public function videoEmbedUrl(): ?string
    {
        return match ($this->video_provider) {
            'youtube' => 'https://www.youtube.com/embed/'.$this->video_id,
            'vimeo' => 'https://player.vimeo.com/video/'.$this->video_id,
            default => null,
        };
    }
}
