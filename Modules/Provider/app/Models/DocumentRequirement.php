<?php

namespace Modules\Provider\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One country asks for one document type; optionally only for one service, one vehicle type or one establishment type. */
class DocumentRequirement extends Model
{
    protected $table = 'provider_document_requirements';

    protected $fillable = ['document_type_id', 'country_id', 'service_category_id', 'vehicle_type_id', 'establishment_type', 'is_required'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }
}
