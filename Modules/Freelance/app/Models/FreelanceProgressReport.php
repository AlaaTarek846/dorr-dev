<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** A progress report the freelancer sent on an active agreement (FL-605). Never edited or deleted. */
class FreelanceProgressReport extends Model
{
    protected $fillable = ['uuid', 'agreement_id', 'freelancer_id', 'note', 'percent'];

    protected static function booted(): void
    {
        static::creating(function (FreelanceProgressReport $r): void {
            $r->uuid ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['percent' => 'integer'];
    }

    public function agreement(): BelongsTo
    {
        return $this->belongsTo(FreelanceAgreement::class, 'agreement_id');
    }
}
