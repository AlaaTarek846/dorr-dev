<?php

namespace Modules\Freelance\Models;

use App\Models\Country;
use App\Traits\HasMediaTrait;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Modules\Freelance\Enums\FreelanceProjectStatus;
use Modules\User\Models\User;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * A client's project (FL-201 / FL-202). Its status only moves through ProjectStateMachine. The files live on the private disk and
 * are handed out through the owner's signed-in endpoint — never a public link.
 */
class FreelanceProject extends Model implements HasMedia
{
    use HasMediaTrait;

    public const DISK = 'local';

    public const BUDGET_TYPES = ['fixed', 'range'];

    public const VISIBILITIES = ['public', 'private'];

    protected $fillable = [
        'uuid', 'client_id', 'country_id', 'freelance_specialty_id', 'title', 'description', 'questions', 'budget_type', 'budget_min_minor',
        'budget_max_minor', 'max_cost_minor', 'currency_code', 'duration_days', 'visibility', 'status', 'status_reason', 'version',
        'submitted_at', 'published_at', 'awarded_at', 'closed_at', 'offers_deadline_at', 'offers_closed_at', 'offers_close_reason', 'reopen_count',
    ];

    protected static function booted(): void
    {
        static::creating(fn (FreelanceProject $p) => $p->uuid ??= (string) Str::uuid());
    }

    protected function casts(): array
    {
        return [
            'status' => FreelanceProjectStatus::class,
            'questions' => 'array',
            'budget_min_minor' => 'integer',
            'budget_max_minor' => 'integer',
            'max_cost_minor' => 'integer',
            'duration_days' => 'integer',
            'version' => 'integer',
            'submitted_at' => 'datetime',
            'published_at' => 'datetime',
            'awarded_at' => 'datetime',
            'closed_at' => 'datetime',
            'offers_deadline_at' => 'datetime',
            'offers_closed_at' => 'datetime',
            'reopen_count' => 'integer',
        ];
    }

    /** Files keep their own type (a PDF stays a PDF). */
    protected function webpSkipCollections(): array
    {
        return ['attachments'];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function country(): BelongsTo
    {
        return $this->belongsTo(Country::class);
    }

    public function specialty(): BelongsTo
    {
        return $this->belongsTo(FreelanceSpecialty::class, 'freelance_specialty_id');
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(FreelanceSkill::class, 'freelance_project_skills', 'project_id', 'freelance_skill_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(FreelanceProjectInvitation::class, 'project_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(FreelanceOffer::class, 'project_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FreelanceProjectEvent::class, 'project_id');
    }

    public function addAttachment(UploadedFile $file): Media
    {
        return $this->addUploadedMedia($file, 'attachments', (string) (($this->getMedia('attachments')->max('order_column') ?? 0) + 1))
            ->usingName(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))
            ->toMediaCollection('attachments', self::DISK);
    }
}
