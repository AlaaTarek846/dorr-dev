<?php

namespace Modules\Freelance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A document type a country asks freelancers for — from citizens, from residents, or from both (null residency). */
class FreelanceDocumentRequirement extends Model
{
    public const RESIDENCIES = ['citizen', 'resident'];

    protected $fillable = ['document_type_id', 'country_id', 'residency', 'is_required'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'country_id' => 'integer', 'document_type_id' => 'integer'];
    }

    public function documentType(): BelongsTo
    {
        return $this->belongsTo(FreelanceDocumentType::class, 'document_type_id');
    }
}
