<?php

namespace Modules\Driver\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Driver\Enums\DocumentSubject;

/** A kind of document a driver or a vehicle may need (licence, registration, insurance…). */
class DriverDocumentType extends Model
{
    use HasTranslations, SoftDeletes;

    protected $fillable = ['code', 'applies_to', 'has_number', 'has_expiry', 'needs_back_side', 'expiry_alert_days', 'status', 'sort_order'];

    protected function casts(): array
    {
        return [
            'applies_to' => DocumentSubject::class,
            'has_number' => 'boolean',
            'has_expiry' => 'boolean',
            'needs_back_side' => 'boolean',
            'expiry_alert_days' => 'integer',
            'status' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(DriverDocumentTypeTranslation::class);
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(DriverDocumentRequirement::class, 'document_type_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class, 'document_type_id');
    }

    protected function translationModel(): string
    {
        return DriverDocumentTypeTranslation::class;
    }
}
