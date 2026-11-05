<?php

namespace Modules\Freelance\Models;

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
 * A post in the professional community (FL-L12): a post, a question or a finished work, in the author's country. Its images live on the
 * private disk and are handed out through short-lived signed links only.
 */
class FreelanceCommunityPost extends Model implements HasMedia
{
    use HasMediaTrait, SoftDeletes;

    public const DISK = 'local';

    public const POST = 'post';

    public const QUESTION = 'question';

    public const WORK = 'work';

    public const KINDS = [self::POST, self::QUESTION, self::WORK];

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    protected $table = 'freelance_community_posts';

    protected $fillable = [
        'uuid', 'freelancer_id', 'country_id', 'freelance_specialty_id', 'kind', 'body', 'portfolio_item_id', 'accepted_comment_id',
        'reactions_count', 'comments_count', 'status', 'hidden_reason', 'hidden_by_admin_id', 'hidden_at', 'edited_at',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceCommunityPost $p) => $p->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return ['hidden_at' => 'datetime', 'edited_at' => 'datetime', 'reactions_count' => 'integer', 'comments_count' => 'integer'];
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

    public function portfolioItem(): BelongsTo
    {
        return $this->belongsTo(FreelancerPortfolioItem::class, 'portfolio_item_id');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(FreelanceCommunityComment::class, 'post_id');
    }

    public function isHidden(): bool
    {
        return $this->status === self::HIDDEN;
    }

    public function addImage(UploadedFile $file, int $order): Media
    {
        return $this->addUploadedMedia($file, 'images', (string) $order)->toMediaCollection('images', self::DISK);
    }
}
