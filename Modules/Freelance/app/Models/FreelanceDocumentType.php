<?php

namespace Modules\Freelance\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A kind of identity document a freelancer may have to upload (national ID, residence permit, passport, a selfie with the ID…). */
class FreelanceDocumentType extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['code', 'has_number', 'has_expiry', 'needs_back_side', 'status', 'sort_order'];

    protected function casts(): array
    {
        return ['has_number' => 'boolean', 'has_expiry' => 'boolean', 'needs_back_side' => 'boolean', 'status' => 'boolean', 'sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(FreelanceDocumentTypeTranslation::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(FreelanceDocumentRequirement::class, 'document_type_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(FreelanceVerificationDocument::class, 'document_type_id');
    }

    protected function translationModel(): string
    {
        return FreelanceDocumentTypeTranslation::class;
    }
}
