<?php

namespace Modules\Provider\Models;

use App\Models\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Provider\Enums\DocumentKind;
use Modules\Provider\Enums\DocumentSubject;

/** A paper or a vehicle photo the admin asks from establishments, vehicles or drivers, per country, service and vehicle type. */
class DocumentType extends Model
{
    use HasTranslations, SoftDeletes;

    /** The photo that shows the vehicle with its plate: always asked, whatever else the admin picks (owner's call). */
    public const PLATE_PHOTO = 'vehicle_plate_photo';

    protected $table = 'provider_document_types';

    protected $fillable = [
        'code', 'subject', 'kind', 'has_number', 'has_expiry', 'needs_back_side', 'expiry_alert_days', 'status', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'subject' => DocumentSubject::class,
            'kind' => DocumentKind::class,
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
        return $this->hasMany(DocumentTypeTranslation::class, 'provider_document_type_id');
    }

    protected function translationModel(): string
    {
        return DocumentTypeTranslation::class;
    }

    public function requirements(): HasMany
    {
        return $this->hasMany(DocumentRequirement::class, 'document_type_id');
    }
}
