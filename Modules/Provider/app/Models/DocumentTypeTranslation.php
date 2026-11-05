<?php

namespace Modules\Provider\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTypeTranslation extends Model
{
    public $timestamps = false;

    protected $table = 'provider_document_type_translations';

    protected $fillable = ['provider_document_type_id', 'locale', 'name', 'instructions'];
}
