<?php

namespace Modules\Chat\Models\Verification;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Chat\Enums\VerificationTrack;

/**
 * A document a verification request asks for (AV-003): the ID with its back side, a selfie holding
 * it, a screenshot proving a celebrity owns their accounts… Each one names the tracks it is for.
 */
class ChatVerificationDocumentType extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['code', 'tracks', 'is_required', 'needs_back_side', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['tracks' => 'array', 'is_required' => 'boolean', 'needs_back_side' => 'boolean', 'status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ChatVerificationDocumentTypeTranslation::class);
    }

    protected function translationModel(): string
    {
        return ChatVerificationDocumentTypeTranslation::class;
    }

    /** The switched-on documents of one track, in the admin's order. */
    public function scopeForTrack(Builder $query, VerificationTrack $track): Builder
    {
        return $query->where('status', true)->whereJsonContains('tracks', $track->value)->orderBy('sort_order')->orderBy('id');
    }
}
